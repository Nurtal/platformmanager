#!/bin/bash
#
# Start and manage a Platform-Manager instance with Docker Compose.
#
# The configuration is docker/.env, the file docker-compose.yml already reads.
# On the first run it is created from docker/env.example with every secret
# replaced by a random value; the generated administrator password is printed
# once. A .env still carrying a value from env.example is refused.
#
# Usage:
#   bin/pfm.sh [start]    build the image, start the stack, install or upgrade
#                         the database, then run the deployment check
#   bin/pfm.sh stop       stop the stack (data volumes are kept)
#   bin/pfm.sh status     list the services
#   bin/pfm.sh logs       follow the application logs
#   bin/pfm.sh check      run bin/check-deployment.sh against the instance
#
# Optional settings in docker/.env, read by this script only:
#   PFM_BRANCH=master     branch or tag of the fork the image is built from
#   PFM_SKIP_BUILD=1      reuse the existing image instead of rebuilding it

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DOCKER_DIR="$ROOT/docker"
ENV_FILE="$DOCKER_DIR/.env"
EXAMPLE_FILE="$DOCKER_DIR/env.example"
# The port docker-compose.yml publishes the application on.
LOCAL_URL="http://localhost:3000"
# Everything but pfm-helpdesk: its mail poller cannot run on this image
# (no ext-imap, see SECURITY-AUDIT.md 2.9).
SERVICES=(pfm pfm-events pfm-rabbitmq mysql redis influxdb grafana)
# Values that must never keep their env.example default.
SECRETS=(PFM_ADMIN_PASSWORD PFM_ADMIN_APIKEY PFM_INFLUXDB_TOKEN MYSQL_ROOT_PASSWORD MYSQL_PASSWORD)

info() { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33mWARNING:\033[0m %s\n' "$*" >&2; }
die()  { printf '\033[1;31mERROR:\033[0m %s\n' "$*" >&2; exit 1; }

compose() { (cd "$DOCKER_DIR" && docker compose "$@"); }

# env_get <file> <key> -> value of the last KEY=... line, or empty
env_get() { sed -n "s/^$2=//p" "$1" | tail -1; }

# env_set <key> <value> -> replace or append KEY=value in docker/.env
env_set() {
    if grep -q "^$1=" "$ENV_FILE"; then
        sed -i "s|^$1=.*|$1=$2|" "$ENV_FILE"
    else
        printf '%s=%s\n' "$1" "$2" >> "$ENV_FILE"
    fi
}

# random_hex <bytes> -> hexadecimal string, two characters per byte
random_hex() { head -c "$1" /dev/urandom | od -An -tx1 | tr -d ' \n'; }

require_docker() {
    command -v docker >/dev/null || die "docker is not installed"
    docker compose version >/dev/null 2>&1 || die "the docker compose plugin is not installed"
    docker info >/dev/null 2>&1 || die "the docker daemon is not reachable (is it running, are you in the docker group?)"
}

create_env() {
    info "Creating docker/.env from env.example with random secrets"
    cp "$EXAMPLE_FILE" "$ENV_FILE"
    chmod 600 "$ENV_FILE"
    env_set PFM_WEB_URL "$LOCAL_URL"
    env_set PFM_ADMIN_PASSWORD "$(random_hex 12)"
    env_set PFM_ADMIN_APIKEY "$(random_hex 10)"   # the column holds 30 characters
    env_set PFM_INFLUXDB_TOKEN "$(random_hex 24)"
    env_set MYSQL_ROOT_PASSWORD "$(random_hex 16)"
    env_set MYSQL_PASSWORD "$(random_hex 16)"
    env_set PFM_JWT_SECRET "$(random_hex 32)"
    GENERATED_PASSWORD="$(env_get "$ENV_FILE" PFM_ADMIN_PASSWORD)"
}

validate_env() {
    local key value
    for key in "${SECRETS[@]}"; do
        value="$(env_get "$ENV_FILE" "$key")"
        [ -n "$value" ] || die "$key is empty in docker/.env"
        [ "$value" != "$(env_get "$EXAMPLE_FILE" "$key")" ] \
            || die "$key in docker/.env still has its env.example value; replace it"
    done
    value="$(env_get "$ENV_FILE" PFM_ADMIN_PASSWORD)"
    [ "${#value}" -ge 8 ] || die "PFM_ADMIN_PASSWORD must be at least 8 characters"
    if [ -z "$(env_get "$ENV_FILE" PFM_JWT_SECRET)" ]; then
        info "Adding the missing PFM_JWT_SECRET to docker/.env"
        env_set PFM_JWT_SECRET "$(random_hex 32)"
    fi
}

# wait_for_http <url> <seconds> -> returns once the URL answers 200
wait_for_http() {
    local deadline=$((SECONDS + $2))
    while [ "$SECONDS" -lt "$deadline" ]; do
        if [ "$(curl -s -o /dev/null -w '%{http_code}' "$1" 2>/dev/null)" = "200" ]; then
            return 0
        fi
        sleep 5
    done
    return 1
}

cmd_start() {
    require_docker
    GENERATED_PASSWORD=""
    [ -f "$ENV_FILE" ] || create_env
    validate_env

    local branch skip_build
    branch="$(env_get "$ENV_FILE" PFM_BRANCH)"
    skip_build="$(env_get "$ENV_FILE" PFM_SKIP_BUILD)"

    if [ "$skip_build" = "1" ]; then
        info "Skipping the image build (PFM_SKIP_BUILD=1)"
    else
        info "Building the image from branch ${branch:-master} of the fork"
        compose build --build-arg "BRANCH=${branch:-master}" pfm
    fi

    info "Starting the stack"
    compose up -d "${SERVICES[@]}"

    # The application container waits for MySQL, RabbitMQ, InfluxDB and Grafana
    # before starting Apache, so a 200 here also means the database is reachable.
    info "Waiting for the application to answer on $LOCAL_URL (up to 5 minutes)"
    wait_for_http "$LOCAL_URL/coreconnection" 300 \
        || die "the application did not answer; see: bin/pfm.sh logs"

    info "Installing or upgrading the database schema"
    compose exec -T pfm php cli/pfm-cli.php install
    compose exec -T pfm php cli/pfm-cli.php routes --reload

    info "Running the deployment check"
    "$ROOT/bin/check-deployment.sh" "$LOCAL_URL" || warn "some deployment checks failed, see above"

    printf '\n'
    info "Platform-Manager is running at $LOCAL_URL"
    printf '    administrator login: %s\n' "$(env_get "$ENV_FILE" PFM_ADMIN)"
    if [ -n "$GENERATED_PASSWORD" ]; then
        printf '    administrator password: %s  (generated now, kept in docker/.env)\n' "$GENERATED_PASSWORD"
    else
        printf '    administrator password: PFM_ADMIN_PASSWORD in docker/.env\n'
    fi
    if [ "$(env_get "$ENV_FILE" PFM_SMTP_HOST)" = "mailhog" ]; then
        warn "PFM_SMTP_HOST is 'mailhog', which this stack does not run: no email will be sent"
    fi
    case "$(env_get "$ENV_FILE" PFM_WEB_URL)" in
        https://*) ;;
        *) warn "PFM_WEB_URL is not HTTPS. For production, put a TLS reverse proxy in front (see README)" ;;
    esac
}

case "${1:-start}" in
    start)  cmd_start ;;
    stop)   require_docker; compose stop ;;
    status) require_docker; compose ps ;;
    logs)   require_docker; compose logs -f pfm ;;
    check)  "$ROOT/bin/check-deployment.sh" "$LOCAL_URL" ;;
    -h|--help|help) sed -n '3,21p' "$0" | sed 's/^# \{0,1\}//' ;;
    *) die "unknown command '$1' (start, stop, status, logs, check)" ;;
esac
