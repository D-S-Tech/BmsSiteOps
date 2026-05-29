#!/usr/bin/env bash
# =============================================================================
# BmsSiteOps — Caddy TLS certificate expiry monitor (Sprint 9.1)
# =============================================================================
#
# Pokes into the running caddy container's data volume, finds every Let's
# Encrypt certificate Caddy is managing, and reports days remaining until
# expiry. Designed for both interactive use AND cron monitoring:
#
#   - interactive:        ./infra/scripts/cert-status.sh   (or `make cert-status`)
#   - cron / monitoring:  QUIET=1 ./infra/scripts/cert-status.sh
#
# Exit codes:
#   0  all certs > CERT_WARNING_DAYS days remaining
#   1  at least one cert < CERT_WARNING_DAYS (warning)
#   2  at least one cert < CERT_CRITICAL_DAYS (critical)
#   3  caddy container not running, or no certificates found
#
# Environment knobs:
#   CERT_WARNING_DAYS    default 14
#   CERT_CRITICAL_DAYS   default 7
#   QUIET                set to 1 to suppress output unless warning/critical
#   CADDY_CONTAINER      default bmssiteops-caddy
#
# Cron example (every morning at 7:00, only emits output on warning+):
#   0 7 * * * QUIET=1 /opt/bmssiteops/infra/scripts/cert-status.sh \\
#               || mail -s "[bmssiteops] TLS cert warning" ops@example.com
# =============================================================================

set -euo pipefail

WARNING_DAYS="${CERT_WARNING_DAYS:-14}"
CRITICAL_DAYS="${CERT_CRITICAL_DAYS:-7}"
QUIET="${QUIET:-0}"
CADDY_CONTAINER="${CADDY_CONTAINER:-bmssiteops-caddy}"

# --- helpers -----------------------------------------------------------------
log()  { [ "$QUIET" = "1" ] || printf '  \033[36m·\033[0m %s\n' "$*"; }
ok()   { [ "$QUIET" = "1" ] || printf '  \033[32m✓\033[0m %s\n' "$*"; }
warn() { printf '  \033[33m⚠\033[0m %s\n' "$*" >&2; }
crit() { printf '  \033[31m✗\033[0m %s\n' "$*" >&2; }
header() { [ "$QUIET" = "1" ] || printf '\n\033[1m%s\033[0m\n\n' "$*"; }

# --- sanity ------------------------------------------------------------------
if ! command -v docker >/dev/null 2>&1; then
    crit "docker not on PATH"
    exit 3
fi

if ! docker inspect "$CADDY_CONTAINER" >/dev/null 2>&1; then
    crit "container '$CADDY_CONTAINER' is not running"
    crit "  is the stack up? try: make prod-ps"
    exit 3
fi

# --- find certs --------------------------------------------------------------
header "BmsSiteOps Caddy TLS certificate status"
log "checking $CADDY_CONTAINER..."

# Caddy stores Let's Encrypt certs under
#   /data/caddy/certificates/<acme-ca-directory>/<domain>/<domain>.crt
# Fresh installs that haven't done their first ACME handshake yet may not
# have the certificates directory at all — handle that gracefully.
CERT_LIST="$(docker exec "$CADDY_CONTAINER" sh -c '
    if [ ! -d /data/caddy/certificates ]; then
        echo "NO_CERT_DIR"
        exit 0
    fi
    find /data/caddy/certificates -type f -name "*.crt" 2>/dev/null | sort
')"

if [ "$CERT_LIST" = "NO_CERT_DIR" ] || [ -z "$CERT_LIST" ]; then
    warn "no certificates found in $CADDY_CONTAINER:/data/caddy/certificates"
    warn "  this is normal on a fresh deploy that hasn't seen HTTPS traffic yet"
    warn "  trigger the ACME handshake by hitting the site over HTTPS once"
    exit 3
fi

# --- inspect each ------------------------------------------------------------
WORST=0   # 0=ok, 1=warning, 2=critical
NOW_EPOCH="$(date +%s)"

while IFS= read -r cert_path; do
    [ -z "$cert_path" ] && continue

    # Domain is the immediate parent directory name.
    domain="$(basename "$(dirname "$cert_path")")"

    # Pull notAfter via openssl (already shipped in caddy:2-alpine).
    enddate="$(docker exec "$CADDY_CONTAINER" openssl x509 -enddate -noout -in "$cert_path" 2>/dev/null \
        | sed -e 's/^notAfter=//' || true)"

    if [ -z "$enddate" ]; then
        warn "$domain — could not parse certificate at $cert_path"
        [ "$WORST" -lt 1 ] && WORST=1
        continue
    fi

    # GNU date and BusyBox date both accept the openssl format
    # ("Mon DD HH:MM:SS YYYY GMT"); run on the host where GNU date is present.
    if ! end_epoch="$(date -d "$enddate" +%s 2>/dev/null)"; then
        warn "$domain — could not parse expiry date '$enddate'"
        [ "$WORST" -lt 1 ] && WORST=1
        continue
    fi

    days_left=$(( (end_epoch - NOW_EPOCH) / 86400 ))

    if [ "$days_left" -lt 0 ]; then
        crit "$domain — EXPIRED $(( -days_left )) days ago ($enddate)"
        WORST=2
    elif [ "$days_left" -lt "$CRITICAL_DAYS" ]; then
        crit "$domain — $days_left days remaining (critical, expires $enddate)"
        WORST=2
    elif [ "$days_left" -lt "$WARNING_DAYS" ]; then
        warn "$domain — $days_left days remaining (warning, expires $enddate)"
        [ "$WORST" -lt 1 ] && WORST=1
    else
        ok "$domain — $days_left days remaining"
    fi
done <<< "$CERT_LIST"

# --- exit --------------------------------------------------------------------
if [ "$WORST" = 0 ]; then
    [ "$QUIET" = "1" ] || printf '\n\033[1;32m✓ all certificates healthy\033[0m\n\n'
elif [ "$WORST" = 1 ]; then
    printf '\n\033[1;33m⚠ at least one certificate expires soon (< %s days)\033[0m\n\n' "$WARNING_DAYS"
else
    printf '\n\033[1;31m✗ at least one certificate is in critical state (< %s days)\033[0m\n\n' "$CRITICAL_DAYS"
fi

exit "$WORST"
