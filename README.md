# platformmanager

Platform-Manager is a database web application to manage core facilities data: Resouces management, booking, services management, shared files, bibliography...

Platform-Manager is under GNU Affero GPL license.

The full documentation is available here: http://bgo-bioimagerie.github.io/platformmanager/

To deploy with [docker](http://bgo-bioimagerie.github.io/platformmanager/installation/docker/), compose and more information is available in docker subdirectory.

This repository is a fork of [bgo-bioimagerie/platformmanager](https://github.com/bgo-bioimagerie/platformmanager)
carrying a security hardening pass. The pass changes no feature.

## Security hardening

The full record, including severity and verification for each item, is in
[SECURITY-AUDIT.md](SECURITY-AUDIT.md). In short:

**Fixed**

- **Login screen XSS**: the post-login destination is validated to an internal relative path
  and escaped where it is written.
- **Session cookie**: `HttpOnly` and `SameSite=Lax` always, `Secure` when served over HTTPS
  (directly or behind a proxy forwarding the protocol).
- **Session fixation**: the session identifier is renewed on every successful sign-in
  (password, remember-me, API key).
- **Remember-me**: 32 random bytes, only a hash stored, constant-time comparison, rotated on
  every use, revoked on sign-out. The cookie gets the same attributes as the session cookie.
- **Passwords**: legacy MD5 hashes are transparently re-hashed with bcrypt at the next sign-in.
- **Logs**: failed sign-ins no longer log the user row and password hash.
- **Web server**: `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy` and a
  Content-Security-Policy on every response; the `.git` directory is refused.
- **Information disclosure**: the debug backtrace printed into every response is gone.
- **Build and tests**: dependencies install again, the container image builds again, and the
  test suite actually runs (76 tests, from 0 executed), including the first tests ever written
  for authentication.
- **CI**: runs on the production PHP version (8.3) and reports dependency advisories.
- **Runtime**: bytecode cache pinned, JSON responses compressed, PHP 9 deprecations removed.

**Known and deliberately left open** (details and cost in the audit, section 2)

- No CSRF protection yet. The design is decided in
  [docs/adr/0001](docs/adr/0001-cross-site-request-forgery-protection.md).
- API keys are stored in clear text; some state-changing actions are reachable by GET.
- The Content-Security-Policy still permits inline scripts and `unsafe-eval`.
- No sign-in throttling; the password reset emails a new password.
- The helpdesk mail poller does not run on the current image (`ext-imap` is unavailable).
- Known advisories remain in frozen front-end dependencies.

## Installation and deployment

The supported route is Docker Compose. Installing from source on Apache is described in
[doc/content/installation/source.md](doc/content/installation/source.md).

> **Build the image from this fork.** The published `quay.io` images are built from upstream and
> do not contain the fixes above. `docker/Dockerfile` clones this fork at build time; the
> `REPOSITORY` and `BRANCH` build arguments select the repository and branch.

### 1. Prerequisites

Docker with the Compose plugin. The stack runs Platform-Manager, MySQL, RabbitMQ, Redis,
InfluxDB and Grafana.

### 2. Configure

```sh
cd docker
cp env.example .env
```

Edit `.env` and replace **every** value:

- `PFM_WEB_URL`: the public URL of the installation.
- `PFM_ADMIN_PASSWORD` (8 characters minimum), `PFM_ADMIN_EMAIL`, `PFM_ADMIN_APIKEY`.
- `MYSQL_ROOT_PASSWORD`, `MYSQL_PASSWORD`, `PFM_INFLUXDB_TOKEN`.
- `PFM_JWT_SECRET`: not in `env.example` but used by the compose file. Add it, with a long
  random value (`openssl rand -hex 32`).
- `PFM_SMTP_HOST` / `PFM_SMTP_FROM`: a real SMTP server and sender address.

Then review `docker-compose.yml`. It is written for development, so for production:

- set `PFM_MODE: prod` (or remove it) and `DEBUG: 0`;
- change the RabbitMQ credentials (`pfm` / `pfm`), in the `pfm-rabbitmq` service and the three
  `PFM_AMQP_*` blocks;
- set `SMTP_PORT` to your server's port (1025 is a local mail catcher);
- drop the `pfm-helpdesk` service: its mail poller cannot run on this image.

All variables are documented in [doc/content/installation/docker.md](doc/content/installation/docker.md).

### 3. Build and start

```sh
docker compose build
docker compose up -d
```

The build clones `master` of this fork. To build another branch, for instance one not yet merged:

```sh
docker compose build --build-arg BRANCH=security-hardening
```

The `./data` directory is mounted into the container. It holds uploaded files and must be
writable by the container's `www-data` user.

### 4. Initialise the database

On first start, and after every upgrade:

```sh
docker compose exec pfm php cli/pfm-cli.php install
docker compose exec pfm php cli/pfm-cli.php version --db   # check the schema version
```

The administrator account is created from `PFM_ADMIN_USER` / `PFM_ADMIN_PASSWORD`.

### 5. Put it behind HTTPS

The container serves plain HTTP on port 3000. Expose it through a TLS-terminating reverse proxy
that forwards `X-Forwarded-Proto: https`. Without that header the session cookies are not
marked `Secure`.

### 6. Verify the deployment

```sh
bin/check-deployment.sh https://pfm.example.org
```

It checks the security headers, the CSP, that `.git` is refused, the cookie attributes, that the
About screen and the Grafana proxy still work, compression, and that no backtrace leaks. It exits
non-zero on any failure. Run it before handing an installation over.

### 7. Back up

MySQL, InfluxDB, Redis and Grafana live in Docker volumes; uploaded files live in `./data`.
Back both up to an external system.

### Upgrading an existing installation

Read [SECURITY-AUDIT.md section 4](SECURITY-AUDIT.md) before deploying this version over an
existing one. In short:

- existing "remember me" cookies stop working, and users sign in once more;
- MD5 password hashes are replaced at each user's next sign-in, with no prompt or reset;
- the CSP blocks any local customisation that loads scripts, styles, fonts or images from an
  outside host;
- helpdesk tickets are no longer received by email.

Then rebuild, restart, and run the `install` command from step 4.
