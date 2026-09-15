# Security audit — hardening pass, September 2026

This is the traceability record for the hardening pass tracked as issue #1. It lists every
weakness found, what was done about it, and for everything left open, why and what it would cost
to close.

**This repository is public and its upstream is deployed by real facilities.** Findings are
described by class and location. No entry contains a working payload, and none should be added.

**Scope.** Application code, the supplied container image and its web-server configuration, the
dependency trees, and continuous integration. Not covered: an installation's own reverse proxy,
TLS termination, database and host hardening, backups, or any local modification an operator has
made.

**Method.** Source review of the authentication, session and request-handling paths; the PHP and
front-end dependency trees; the image and its web-server configuration. Every fix below was
verified against a running instance, not only against the test suite. Verified means: signed in,
exercised the flow, and read the response.

---

## 1. Fixed in this pass

### 1.1 Reflected cross-site scripting on the login screen — **critical**

**What.** The login screen takes the destination a visitor was heading to, reads it straight from
the query string bypassing the escaping every other request parameter goes through, URL-decodes it
a second time, and writes it into an HTML attribute unescaped.

**Impact in plain terms.** A crafted link, sent by email to anyone, runs the attacker's JavaScript
in that person's browser on a page of the installation. Combined with 1.2, that is a complete
session-theft chain against any User, including an installation administrator, with no prior
access of any kind. The second decoding pass is what made it work against naive escaping.

**Fixed.** The redundant decode is gone, the value is validated to an internal relative
destination only, and the view escapes it where it writes it. Covered by tests on the validation
rule, and verified against a running instance: a crafted destination renders inert, an external
one is dropped, a legitimate internal one keeps its query string.

### 1.2 Session cookie readable by any script, and usable cross-site — **high**

**What.** The session cookie carried none of `HttpOnly`, `SameSite` or `Secure`.

**Impact.** Any scripting flaw anywhere in the application hands over the session, and another site
can ride an authenticated session on navigation.

**Fixed.** `HttpOnly` and `SameSite=Lax` unconditionally; `Secure` only when the request is
genuinely served over HTTPS, directly or via the protocol the reverse proxy forwards. Marking it
`Secure` unconditionally would make the supplied container stack, which serves plain HTTP,
unusable on a laptop. Asserted against a live instance by `bin/check-deployment.sh`.

### 1.3 Session fixation — **high**

**What.** The session identifier a visitor arrived with survived authentication.

**Impact.** An identifier planted in someone's browser beforehand stays valid against their account
after they sign in.

**Fixed.** The identifier is renewed where a successful authentication populates the session, which
covers the password, remember-me and API-key paths.

### 1.4 Redirect handling discarded the renewed session cookie — **found while fixing 1.3**

**What.** The framework's redirect helper calls `header_remove()`, which drops every header
including `Set-Cookie`.

**Impact.** On its own, before 1.3, this silently dropped the session cookie of a first-time
visitor and with it the flash message on a failed sign-in. With 1.3 in place it broke sign-in
outright: the browser kept an identifier the server had just discarded.

**Fixed.** Cookies are preserved across `header_remove()`. Verified both ways against a running
instance.

### 1.5 Remember-me tokens short and stored in the clear — **high**

**What.** The token was eight alphanumeric characters put through a hash, and the value stored
beside the User record was the same value the browser held. Signing out dropped the cookie but left
the stored token valid. The token was also interpolated into an exception message on failure, so it
reached the logs and the error-reporting service.

**Impact.** Read access to the database — a backup, a dump, a report query, a SQL injection
elsewhere — is enough to sign in as any User who ticked the box, with no password.

**Fixed.** 32 random bytes, only a hash stored, constant-time comparison, rotation on every use, and
invalidation on sign-out. Covered by tests through the model and verified end to end against a
running instance, including that a consumed token is refused on replay.

### 1.5b The "remember me" cookie carried no protections — **high, found in review**

**What.** 1.2 hardened the session cookie, but the remember-me cookie itself was still set with
none of `HttpOnly`, `SameSite` or `Secure`, and with no explicit path, so it took whatever
directory the request URL happened to have.

**Impact.** The longer-lived of the two credentials was the less protected one: readable by any
page script for three days, sent on cross-site navigations, and transmitted in the clear over
plain HTTP. The unset path also meant a rotation performed on a nested URL could scope the new
cookie to a subtree, or leave several cookies of the same name behind.

**Fixed.** Both the issue and the clearing of that cookie now go through one place, which applies
the same attributes as the session cookie and pins the path to the installation root. Verified
against a running instance: the cookie is set, rotated and cleared with matching attributes, and
the whole remember-me journey still works.

### 1.6 MD5 password hashes accepted, never migrated — **high**

**What.** Accounts created before bcrypt still authenticated against an MD5 hash and nothing ever
moved them off it.

**Impact.** For those accounts, a stolen database yields the passwords themselves, at a rate
limited only by hardware. Password reuse then extends the breach beyond this installation.

**Fixed.** A successful comparison against an MD5 hash immediately re-hashes with bcrypt and
updates the stored hash and its marker. Nobody is asked to reset anything and the debt
extinguishes itself as Users sign in. Covered by tests.

### 1.7 Credentials written to the log — **high**

**What.** On a wrong password or an inactive account, the authentication path logged the entire
User row, password hash included.

**Impact.** Log access becomes credential access, which is a much lower bar: logs are shipped,
aggregated, and read by people who are not database administrators.

**Fixed.** Those entries carry the login and the reason only.

### 1.8 No security headers, and the repository served from the web root — **high**

**What.** The application declared no security headers at all, and the image places the version
control directory inside the web root.

**Impact.** Anyone could download the full source and commit history of an installation, which
includes whatever an operator committed locally. No framing, MIME-sniffing or content-source
protection on any response.

**Fixed.** Frame denial, MIME-sniffing denial, a referrer policy and a Content-Security-Policy on
every response. The version control directory is refused by the web server rather than deleted,
because the About screen and the command line tool both read the deployed version from it. See 2.6
for what the policy had to permit.

### 1.9 Backtrace printed into every response — **medium**

**What.** A debug backtrace call ran at the end of every request.

**Impact.** Internal filesystem paths and call structure disclosed to anyone, which is
reconnaissance for everything else in this list.

**Fixed.** Removed.

### 1.10 The repository could not be installed, and the test suite did not run — **blocking**

**What.** Dependency installation failed on three unrelated constraints. Separately, the test
suite executed zero of its tests: two suites were skipped because a test framework upgrade
requires the class name to match the file name, and the suite that creates the fixtures was one of
them.

**Impact.** No test, no dependency audit, no image build, no way for anyone to verify any claim
about the application. A suite that reports success while running nothing is worse than no suite.

**Fixed.** Constraints corrected and the lock file committed; the suite runs 76 tests with no
failures and no deprecations, up from 0 tests executed.

**Worth stating separately: no test covered authentication at all before this pass** — not the
model's authentication method, not password comparison, not remember-me. Every change in 1.5,
1.5b, 1.6 and 1.7 was made to code that nothing verified. The tests added by those items are the
first coverage those paths have ever had, and they are the reason the next change to them will not
be a leap of faith.

### 1.11 The container image could not be built — **blocking**

**What.** The base image moved to a Debian release that renamed three packages the build
installs, and the mail extension the helpdesk poller uses needs a library Debian dropped.

**Impact.** No deployable artefact, so none of the above could be shipped or verified.

**Fixed for the image as a whole**, with one part left open — see 2.9.

### 1.12 Deprecations that become fatal on the next major PHP release — **low**

Twelve uses of a string-interpolation form removed in PHP 9, plus four more found by running the
suite: null passed to string functions in two framework helpers, and dynamic properties on the
resources controllers. All replaced; the suite now runs with zero deprecations.

---

## 2. Deliberately deferred

Each of these is a real weakness. Each is left open because closing it costs more than this pass
was scoped to spend, or breaks something that needs a migration window.

### 2.1 No cross-site request forgery protection anywhere — **the largest item left open**

**Impact.** Any authenticated User can be made to perform any action they are entitled to by a
page they visit elsewhere. An installation administrator is the valuable target, because an
administrator Status overrides any Role in any Space.

**Why deferred.** Retrofitting it touches the form generator and several hundred views, and the
current suite cannot validate the result.

**What it would cost.** The design is decided and written up in `docs/adr/0001`: a per-session
token, verified in request dispatch, rolled out report-only before enforcement. The blast radius is
95 controllers that build forms across 312 view files, 21 hand-written forms, 17 asynchronous call
sites, plus three routes that must change HTTP method. **What breaks if done carelessly:** every
form in the application, file uploads first, and every API-key integration.

### 2.2 API keys stored in the clear — **high**

**Impact.** Read access to the database yields working API credentials for every account that has
one. The same exposure as 1.5, for a credential that does not expire.

**Why deferred.** Hashing them invalidates every existing key and breaks every integration without
notice.

**What it would cost.** A migration window: issue new keys, accept both forms for a release, then
drop the clear-text column. **What breaks:** every integration, silently, at the moment of the
change, and their owners are not necessarily reachable.

### 2.3 The API-key path establishes a session — **medium, found while writing ADR-0001**

**Impact.** An API-key request made from a browser mints an ambient session cookie for that
account. Beyond being surprising, it undermines the reasoning that would exempt API-key requests
from CSRF verification.

**Why deferred.** It is a prerequisite for 2.1 and belongs with it.

**What it would cost.** Small: authenticate, serve, do not persist a session. **What breaks:** any
integration that relies on the cookie it currently happens to receive.

### 2.4 State-changing actions reachable by GET — **medium**

Impersonating a User, ending impersonation, and running the Space user-expiry are plain links.

**Impact.** They are the easiest possible CSRF targets, and they are the highest-value ones:
impersonation is an identity change.

**Why deferred.** Converting them belongs with 2.1, whose protection would otherwise not cover
them. **What breaks:** the links themselves, and anything an operator bookmarked.

### 2.5 The application escapes on input, not on output — **medium**

**Impact.** The request object escapes scalars and deliberately skips arrays, so an array-shaped
parameter reaches a view unescaped. 1.1 is the same class of flaw, found in the one place that
bypassed even the input escaping. Escaping on input also corrupts data that legitimately contains
markup characters.

**Why deferred.** Reversing it is a codebase-wide change touching every view.

**What it would cost.** Escaping at every output site, 312 view files, with a transition where
values are escaped twice unless input escaping is removed in the same change. **What breaks:**
display of anything already stored escaped.

### 2.6 What the Content-Security-Policy had to permit — **medium**

The policy shipped in 1.8 is a real improvement but is not tight, and the reasons are worth
recording rather than discovering later:

- **Inline scripts and styles are permitted.** The views carry their scripts inline throughout.
  Tightening this means moving them to files with nonces or hashes: a change to every view.
- **`unsafe-eval` is permitted.** The shipped Vue build compiles in-DOM templates at runtime.
  Removing it means precompiling templates or moving to the runtime-only build — a front-end build
  step this project does not currently have.
- **An external avatar host is permitted**, see 2.7.

With these three permitted, the policy does not stop an injected inline script. It does stop
script loaded from an outside host, plugin objects, and framing, which is worth having; it is not
a substitute for fixing 2.5.

### 2.7 Every authenticated page discloses a hash of the User's email to a third party — **privacy**

**Impact.** The navigation bar builds each avatar URL from an MD5 of the User's email address and
requests it from a third-party avatar service. That service therefore receives, on every page view
of every authenticated User, a stable identifier for that person and the address of the
installation in the referrer. An MD5 of an email address is not anonymous: it is reversible for any
address an attacker can guess or enumerate.

**Why deferred.** Removing it blanks the avatar on every screen, which is a product decision and a
visible change. **What it would cost:** little technically — serve a local default, or make it an
installation setting, defaulting to off.

### 2.8 Password reset emails a new password and changes the account immediately — **medium**

**Impact.** Requesting a reset for someone else's login changes their password immediately, which
locks them out until they read their email — anyone who knows a login or email address can do it,
repeatedly. The replacement password is sent in the message body, so it sits in at least two
mailboxes and every mail server in between, in the clear. It is eight characters from a 60-character
alphabet, generated for a person who is expected to keep using it.

**Why deferred.** The fix is a different flow — a single-use, expiring reset link that changes
nothing until it is followed — which is a feature change, not hardening. **What breaks:** the
current reset journey, which people are used to.

### 2.9 The helpdesk mail poller cannot run on a current image — **medium**

**Impact.** The extension it uses needs a library Debian dropped after bullseye and that PHP moved
out of core in 8.4. The image now builds without it rather than not building at all, so the
helpdesk service's mail polling does not run. Nothing in the web application is affected: only the
poller calls it.

**Why deferred.** Restoring it means either vendoring an unmaintained C library into the image —
the wrong trade in a security pass — or moving the poller onto a maintained IMAP client library,
which is its own piece of work. **What it would cost:** rewriting one command-line script against
a library, plus a mailbox to test against.

### 2.10 No throttling on sign-in — **medium**

**Impact.** Nothing limits how fast passwords can be tried against a known login. bcrypt makes
this slow per attempt, which is most of the defence, but it also makes an unthrottled endpoint a
cheap way to exhaust the server's CPU.

**Why deferred.** It needs shared state across requests and a policy decision about lockout, which
is an operational question as much as a technical one.

### 2.11 Known advisories in frozen front-end dependencies — **medium**

Two high-severity advisories in the committed front-end tree: a prototype-pollution class in the
utility library and an inefficient-regular-expression class in the date library. The tree's
installed modules are committed to the repository, so these ship with every deployment.

**Why deferred.** Both fixes are major-version moves with API changes across the views.
Continuous integration now reports them on every pull request rather than nobody noticing for
years. **What breaks:** date formatting and utility calls across the front end.

### 2.12 Abandoned PHP packages

Three packages in the dependency tree are abandoned upstream: a social-media client, the debug bar,
and a message-factory shim. None carries an advisory today; abandoned means nobody will publish one
either. Reported by the new audit step on every pull request.

### 2.13 Deferred major dependency upgrades

The error-reporting SDK, the metrics client, the table and markdown libraries, and the drag-and-drop
component are all a major version behind. None is driven by an advisory; all are API debt. Left
frozen deliberately.

---

## 3. Out of scope, recorded for completeness

- **Asset URL versioning.** Asset URLs carry no version marker, which is what caps the asset cache
  lifetime at an hour. Raising the lifetime without versioning would serve stale scripts after a
  deployment, so the lifetime was deliberately left alone.
- **Database indexes and query shape.** Observed only. No schema or query change was made, and none
  should be without profiling against real data.
- **Renaming the two authorisation scales.** The database stores an installation-wide Status and a
  per-Space Role under the same column name, which is the source of most ambiguity in discussions
  about access. The glossary at `CONTEXT.md` fixes the vocabulary; the identifiers stay.
- **A dead import helper.** The model's legacy user-import method has a column-to-placeholder
  mismatch and would fail on any call. It has no callers. Left alone rather than fixed silently,
  because a repaired import path deserves its own review.
- **Test fixtures log whole user rows**, password hashes included. Test code only, never runs in
  production, but worth knowing before pasting test output anywhere.
- **The stale generated documentation directory.** Costs nothing in production and removing it
  would bury the diff.

---

## 4. What operators must expect at this deployment

One-off effects. All of them are deliberate.

1. **Existing "remember me" cookies stop working.** Affected Users sign in once more and get a new
   token. This is the accepted cost of 1.5 and the only effect most people will notice.
2. **Accounts still on MD5 have their stored hash replaced** the next time they sign in. Nobody is
   prompted, nobody resets anything, and the password does not change.
3. **The helpdesk mail poller does not run** on an image built from this revision. See 2.9. If an
   installation depends on helpdesk tickets arriving by email, hold the deployment or plan for the
   gap.
4. **A Content-Security-Policy is now enforced.** Any local customisation that loads a script,
   stylesheet, font or image from an outside host — a themed logo, an analytics snippet — stops
   loading. Check any local modification before deploying.
5. **The version control directory is no longer served.** If any local tooling fetched it over
   HTTP, it stops working. It remains present in the image, because the About screen needs it.
6. **API keys are unchanged** and keep working exactly as before.
7. **Nothing else changes.** No database schema change, no renamed identifier, no screen behaves
   differently, and every Role grants exactly the access it granted before.

Run `bin/check-deployment.sh <url>` against the deployed instance afterwards. It asserts the
headers, the policy, the refusal to serve the repository, the cookie attributes, compression, and
that the About screen still reports a version. It is a pre-deployment check, not a continuous
integration step, because continuous integration does not stand the full stack up.

One thing it cannot assert, because nothing about it is visible over HTTP, is the bytecode cache.
Check that one against the container directly:

    docker exec <container> php -r 'var_dump(ini_get("opcache.enable"), ini_get("opcache.validate_timestamps"));'

Both must be "1". The second is what keeps development mode and mounted source trees working.

---

## 5. Disclosure

The flaws in 1.1, 1.2, 1.3, 1.5, 1.6 and 1.7, and every item in section 2, exist identically in the
upstream project, which is public and deployed by real facilities. This repository is a fork.

**Recommendation:** report them upstream now that a fix exists here, through a private channel
first, offering the changes. 1.1 combined with 1.2 is the one to lead with: it is exploitable
against any installation by anyone who can send a link, and it needs no prior access.

Nothing in this document, in the commit messages, or in the issue tracker contains a working
payload, and nothing added later should.
