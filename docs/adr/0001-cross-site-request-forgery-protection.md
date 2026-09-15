# ADR-0001: Cross-site request forgery protection

- Status: proposed
- Date: 2026-09-15
- Context ticket: #8, split out of the hardening pass #1

## Problem

Platform-Manager has no cross-site request forgery protection anywhere. Every state-changing
action is authenticated by the session cookie alone, so a page a User visits elsewhere can make
their browser perform any action they are entitled to: creating an account, granting a Role in a
Space, editing an invoice, deleting a booking. An installation administrator is the most valuable
target, because an administrator Status overrides any Role in any Space.

The hardening pass deliberately excluded the fix. Retrofitting it touches the form generator and
several hundred views, and the current test suite cannot validate it. This document decides how
it will be done, once, so the implementation can be scheduled as its own piece of work.

## Decision

Use the **synchroniser token pattern**, with **one token per session**, verified in **request
dispatch** rather than in form handling, rolled out in a **report-only phase before enforcement**.

The rest of this document is the reasoning and the plan.

### Token issue, storage and verification

**One token per session, not per form.** The token is 32 random bytes, hex encoded, generated the
first time a session needs one and held in the session under its own key. It is compared with a
constant-time comparison, never with `==`.

Per-session rather than per-form because of how this application is actually used. Facility
managers keep several tabs open on the same Space — a booking calendar, a resource list, an
invoice — and they use the back button heavily on the long generated forms. Per-form tokens
invalidate exactly those workflows, and the usual workarounds (a pool of live tokens, a
per-tab identifier) are more machinery than the threat here justifies. A per-session token stops
every cross-origin request just as effectively; what it does not stop is an attacker who can
already read the page, and such an attacker has the session cookie anyway.

**Rotation** happens where the session identifier is already renewed: at sign-in, in the single
place a successful authentication populates the session. The hardening pass added the renewal
there, so the token is issued immediately after it, in the same place. It is dropped on sign-out
with the rest of the session. It is **not** rotated per request: doing so breaks parallel tabs
and in-flight asynchronous requests for no gain over a session-lifetime secret.

**Storage.** The session, nothing else. No database column, no second cookie. The token must not
be readable by page scripts, which the session cookie's `HttpOnly` attribute already guarantees
for the session itself; the token reaches scripts only through the page the server rendered for
that session.

The alternative considered and rejected is the double-submit cookie: it needs no server state,
but it is weaker on a host that shares a registrable domain with anything else, and this
application is routinely deployed on an institutional subdomain alongside other services. Server
state is cheap here because there is already a session.

### Where the token is issued

**Form generation, one place.** The form generator already emits a hidden `formid` input into
every form it builds, and the controllers that build forms — 95 of them — never touch that markup.
The token field goes in beside it. This covers every generated form in the application without
editing a single controller or view.

**Hand-written forms, 21 view files.** These carry a literal `<form>` element and bypass the
generator. Each needs one line: a call to the same helper the generator uses, so there is exactly
one implementation of "render the token field". They are found by searching the view tree for a
literal form element; the report-only phase below confirms none was missed.

**Asynchronous requests, 17 call sites.** The layout exposes the token once in a `<meta>` element,
and a single shared front-end helper reads it and sets it as a request header on every
non-idempotent call. The call sites change to use that helper rather than each carrying the token
by hand, because the next new endpoint must not have to remember.

### Where the token is verified

**Request dispatch, before the controller is constructed.** The router is the only place every
request passes through, and it has two paths — the declarative route table and the legacy
path-cache lookup — so verification sits in the single method that owns both, ahead of the branch.

Verification is explicitly **not** put in the form generator's `check()`. That would look natural
and would be wrong: `check()` only runs for forms the generator produced, so every hand-written
form, every asynchronous endpoint and every state-changing link would silently remain unprotected,
and the gap would be invisible.

**What is verified.** Every request whose method is not `GET`, `HEAD` or `OPTIONS`.

**State-changing GET requests are the exception that needs deciding.** Several actions today change
state on a `GET`: impersonating a User, ending impersonation, and running the Space user-expiry.
A token on forms does nothing for those, because they are plain links. The recommendation is to
convert them to `POST` as part of this work rather than to accept tokens in the query string,
where they end up in browser history, in proxy logs and in referrer headers. Until they are
converted, they are verified against a token carried as a query parameter, and the list of such
routes is explicit in the code, not a pattern match, so it cannot quietly grow.

### API-key authenticated requests and JSON endpoints

These are two different things and must not be treated the same way.

**API-key requests are exempt.** They authenticate with a key and a login in request headers, not
with a cookie. A browser cannot be made to attach those headers to a cross-origin request
ambiently, so the attack this document is about does not apply. Exemption is decided by the
request carrying valid API-key headers, evaluated the same way authentication evaluates them, not
by the path or by a header the caller chooses.

**One thing must change for that exemption to be sound.** The API-key path currently calls the same
session-populating method as an interactive sign-in, so an API-key request made from a browser
mints an ambient session cookie for that account. Before CSRF verification is enforced, API-key
requests must stop persisting a session: authenticate, serve the request, and do not send a
session cookie back. Otherwise the exemption becomes a way to obtain the very thing the protection
assumes cannot be obtained.

**JSON endpoints the front-end calls are not exempt.** They are reached with the session cookie,
which makes them exactly as forgeable as a form post. They are covered by the header the shared
front-end helper sets. The response shape on failure differs, not the rule.

### What failure looks like

**To a User:** the standard error screen with HTTP 403 and a short, non-technical message —
that the page they submitted is no longer valid and they should reload it and try again. No
mention of tokens. For a request that asked for JSON, a 403 with the same message in the JSON
error shape the application already uses, and the front-end helper surfaces it as the usual error
banner.

**To an operator:** exactly one log line per rejection, at warning level, carrying the route, the
HTTP method, the login, and which of the three reasons applied — no token in the session, no token
in the request, or a token that did not match. It never carries either token value: a rejection
log that prints the expected token turns log access into session forgery, which is the mistake the
hardening pass had to undo elsewhere in this codebase.

The three reasons matter operationally, because the first one is almost always a User whose session
expired while a page was open, and the third is almost always a real attack or a bug.

## Migration order

Each step leaves the application fully usable, and each is independently revertable.

1. **Issue and expose, verify nothing.** Token generation in the session, the hidden field in the
   form generator, the `<meta>` element in the layout. No request is rejected. Ship it. Behaviour
   is unchanged, and from here on every generated form already carries a valid token.
2. **Verify in report-only mode.** Dispatch evaluates the token and logs what it *would* have
   rejected, then continues. Run it for a full release cycle on a real installation. The log is
   the inventory: it names every flow that still lacks a token, including the ones this document
   failed to anticipate. This step is the reason the rollout is safe, and skipping it is how a
   retrofit like this breaks a facility's Monday morning.
3. **Close what the log shows.** The 21 hand-written forms, the asynchronous call sites, the
   state-changing GET routes converted to POST, and the API-key session change. Repeat until a
   release cycle produces no report-only rejections.
4. **Enforce**, behind a configuration flag defaulting to on, so an operator who hits something
   nobody foresaw can turn it off for one release rather than roll back a deployment.
5. **Remove the flag** once a release cycle has passed with enforcement on and no rejections other
   than expired sessions.

## Blast radius

What must be retested, in terms of flows rather than files:

- **Every screen with a form.** 95 controllers build forms through the generator, across 312 view
  files. The generator change is one place, so the risk is not that a form breaks individually but
  that the hidden field lands somewhere it changes layout or trips a view that reads the form's
  fields positionally.
- **The 21 hand-written forms**, individually, because each is an edit.
- **File upload forms**, separately: they submit as multipart, and a token field that works in a
  URL-encoded post can still be dropped by a hand-rolled multipart form.
- **Every screen that posts asynchronously** — the booking calendar, the antibodies grids, the
  invoice and quote editors — because they change from posting nothing extra to posting a header.
- **Impersonation, both directions**, since those routes change HTTP method.
- **The Space user-expiry run**, same reason.
- **Every API-key integration**, because the exemption and the session change both land on that
  path. This is the one with consequences outside the installation: integrations break silently
  and their owners find out late.
- **Sign-in, sign-out, and the remember-me path**, because the token's lifetime is tied to the
  session identifier those paths renew.

The test suite covers controller dispatch through a request the test builds, so it can cover the
verification rule itself and the exemption; it cannot cover the generated markup or the front-end
helper. Those need the pre-deployment check and a manual pass.

## Consequences

- A User whose session expires while a page is open now sees a 403 instead of a silent
  re-authentication. This is the visible cost, and it is why the failure message must say to
  reload rather than say "forbidden".
- Anything that posts to the application without a browser session and without an API key stops
  working. Nothing in the repository does this today; an installation with local scripts might.
- The API-key path stops establishing a session, which is a behaviour change for any integration
  that relied on the cookie it happened to receive.

## Related

- Hardening pass #1 and the security audit report, which lists this as the largest item left open.
- The session identifier renewal added by #9, which the token's lifetime is tied to.
