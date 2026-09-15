#!/bin/bash
#
# Pre-deployment check for a running Platform-Manager instance.
#
# Everything asserted here lives in web server and PHP configuration, so none of
# it can be expressed in the PHP test suite. Run it against a deployed instance
# before handing it over; it is NOT a continuous integration step, because CI
# does not stand the full stack up.
#
# Usage:
#   bin/check-deployment.sh [base-url]        # default: http://localhost:3000
#
# Exits non-zero as soon as any check fails.

set -u

BASE_URL="${1:-${PFM_URL:-http://localhost:3000}}"
BASE_URL="${BASE_URL%/}"

FAILURES=0
CHECKS=0

green() { printf '\033[32m%s\033[0m' "$1"; }
red()   { printf '\033[31m%s\033[0m' "$1"; }

pass() {
    CHECKS=$((CHECKS + 1))
    printf '  [%s] %s\n' "$(green OK)" "$1"
}

fail() {
    CHECKS=$((CHECKS + 1))
    FAILURES=$((FAILURES + 1))
    printf '  [%s] %s\n' "$(red FAIL)" "$1"
    if [ $# -gt 1 ]; then
        printf '         %s\n' "$2"
    fi
}

section() { printf '\n%s\n' "$1"; }

# headers_of <path> -> response headers, lowercased header names
headers_of() {
    curl -sS -D - -o /dev/null -L --max-redirs 3 "${BASE_URL}$1" 2>/dev/null \
        | sed 's/\r$//'
}

# status_of <path> -> final HTTP status code, no redirect following
status_of() {
    curl -sS -o /dev/null -w '%{http_code}' "${BASE_URL}$1" 2>/dev/null
}

# header_value <headers> <name> -> value of the last occurrence, or empty
header_value() {
    printf '%s\n' "$1" | grep -i "^$2:" | tail -1 | cut -d: -f2- | sed 's/^ *//'
}

printf 'Checking %s\n' "$BASE_URL"

if ! curl -sS -o /dev/null --max-time 10 "${BASE_URL}/"; then
    printf '%s cannot reach %s\n' "$(red 'FATAL:')" "$BASE_URL"
    exit 2
fi

# --- Response headers -----------------------------------------------------

section 'Security headers on the application'
APP_HEADERS="$(headers_of /coreconnection)"

check_header() {
    local name="$1" expected="$2" value
    value="$(header_value "$APP_HEADERS" "$name")"
    if [ -z "$value" ]; then
        fail "$name is present" "no $name header on /coreconnection"
    elif [ -n "$expected" ] && ! printf '%s' "$value" | grep -qi -- "$expected"; then
        fail "$name contains '$expected'" "got: $value"
    else
        pass "$name: $value"
    fi
}

check_header 'X-Frame-Options' 'DENY'
check_header 'X-Content-Type-Options' 'nosniff'
check_header 'Referrer-Policy' ''

section 'Content-Security-Policy'
CSP="$(header_value "$APP_HEADERS" 'Content-Security-Policy')"
if [ -z "$CSP" ]; then
    fail 'Content-Security-Policy is present' 'no CSP header on /coreconnection'
else
    pass "Content-Security-Policy: $CSP"
    for directive in "default-src 'self'" "script-src 'self'" "style-src 'self'" \
                     "object-src 'none'" "frame-ancestors 'none'"; do
        if printf '%s' "$CSP" | grep -q -- "$directive"; then
            pass "policy carries \"$directive\""
        else
            fail "policy carries \"$directive\"" "got: $CSP"
        fi
    done
    # the views rely on inline scripts, so this one is expected to be permitted
    if printf '%s' "$CSP" | grep -q -- "script-src[^;]*'unsafe-inline'"; then
        pass "inline scripts are permitted (the views need them)"
    else
        fail "inline scripts are permitted" "the views rely on them; got: $CSP"
    fi
fi

# --- Version control directory --------------------------------------------

section 'Version-control directory'
for path in /.git/config /.git/HEAD /.git/logs/HEAD; do
    code="$(status_of "$path")"
    case "$code" in
        403|404) pass "$path refused ($code)" ;;
        *)       fail "$path refused" "got HTTP $code, the repository is being served" ;;
    esac
done

# --- Features that must keep working --------------------------------------

section 'Features that must keep working'
# The About screen reads the deployed version from the repository (git describe),
# which is the reason the version-control directory is refused rather than deleted.
ABOUT="$(curl -sS -L --max-redirs 3 -H 'Accept: application/json' "${BASE_URL}/core/about" 2>/dev/null)"
ABOUT_TAG="$(printf '%s' "$ABOUT" | sed -n 's/.*"tag" *: *"\([^"]*\)".*/\1/p')"
if [ -n "$ABOUT_TAG" ]; then
    pass "the About screen reports a deployed version ($ABOUT_TAG)"
else
    fail 'the About screen reports a deployed version' \
         'empty version: the repository is unreachable to the application, or the checkout has no tags'
fi

GRAFANA_CODE="$(status_of /grafana)"
case "$GRAFANA_CODE" in
    200|301|302|303|307|308|401|403)
        pass "the proxied metrics dashboard answers ($GRAFANA_CODE)"
        GRAFANA_HEADERS="$(curl -sS -D - -o /dev/null "${BASE_URL}/grafana" 2>/dev/null | sed 's/\r$//')"
        if [ -z "$(header_value "$GRAFANA_HEADERS" 'Content-Security-Policy')" ]; then
            pass 'the application policy is not imposed on the metrics dashboard'
        else
            fail 'the application policy is not imposed on the metrics dashboard' \
                 'it serves its own pages under its own rules'
        fi
        ;;
    000) fail 'the proxied metrics dashboard answers' 'no response; is grafana running?' ;;
    *)   fail 'the proxied metrics dashboard answers' "got HTTP $GRAFANA_CODE" ;;
esac

# --- Result ---------------------------------------------------------------

printf '\n'
if [ "$FAILURES" -eq 0 ]; then
    printf '%s %d checks passed\n' "$(green 'PASS:')" "$CHECKS"
    exit 0
fi
printf '%s %d of %d checks failed\n' "$(red 'FAIL:')" "$FAILURES" "$CHECKS"
exit 1
