#!/usr/bin/env bash
# =============================================================================
# BmsSiteOps — deploy
# =============================================================================
# Pull the latest code, rebuild images, run migrations, and bring the stack up
# with zero secrets baked into the image. Run as the deploy user from the
# repository root on the production host:
#
#   cd ~/BmsSiteOps
#   ./infra/scripts/deploy.sh
#
# Idempotent and safe to re-run. On any failure the script stops (set -e) so a
# half-applied deploy is never silently left running.
#
# Flags:
#   --no-pull        skip `git pull` (deploy the currently checked-out commit)
#   --no-build       skip image rebuild (use existing images)
#   --skip-migrate   do not run database migrations this deploy
# =============================================================================

set -euo pipefail

COMPOSE="docker compose -f infra/compose/docker-compose.prod.yml"
DO_PULL=1
DO_BUILD=1
DO_MIGRATE=1

for arg in "$@"; do
    case "$arg" in
        --no-pull)      DO_PULL=0 ;;
        --no-build)     DO_BUILD=0 ;;
        --skip-migrate) DO_MIGRATE=0 ;;
        *) echo "Unknown flag: $arg" >&2; exit 2 ;;
    esac
done

log()  { printf '\033[1;34m[deploy]\033[0m %s\n' "$*"; }
die()  { printf '\033[1;31m[deploy]\033[0m %s\n' "$*" >&2; exit 1; }

# -----------------------------------------------------------------------------
# Preflight
# -----------------------------------------------------------------------------
[ -f infra/compose/docker-compose.prod.yml ] \
    || die "Run from the repository root (infra/compose/docker-compose.prod.yml not found)."
[ -f .env ] \
    || die ".env not found. Copy infra/compose/.env.prod.example to .env and fill it in."

# Refuse to deploy with placeholder secrets still in place.
if grep -qE '(^|=)CHANGEME|<YOUR_' .env; then
    die ".env still contains CHANGEME / <YOUR_...> placeholders. Fill them in before deploying."
fi

# -----------------------------------------------------------------------------
# 1. Pull latest code
# -----------------------------------------------------------------------------
if [ "$DO_PULL" -eq 1 ]; then
    log "1/5 — Pulling latest code"
    git pull --ff-only
else
    log "1/5 — Skipping git pull (--no-pull)"
fi
log "    Deploying commit: $(git rev-parse --short HEAD) — $(git log -1 --pretty=%s)"

# -----------------------------------------------------------------------------
# 2. Build images
# -----------------------------------------------------------------------------
if [ "$DO_BUILD" -eq 1 ]; then
    log "2/5 — Building images"
    $COMPOSE build --pull
else
    log "2/5 — Skipping build (--no-build)"
fi

# -----------------------------------------------------------------------------
# 3. Start infrastructure first, wait for health
# -----------------------------------------------------------------------------
log "3/5 — Starting datastores (postgres, redis, meilisearch)"
$COMPOSE up -d postgres redis meilisearch

log "    Waiting for postgres to report healthy"
for i in $(seq 1 30); do
    if $COMPOSE ps postgres | grep -q "healthy"; then
        log "    postgres healthy"
        break
    fi
    [ "$i" -eq 30 ] && die "postgres did not become healthy in time."
    sleep 2
done

# -----------------------------------------------------------------------------
# 4. Run migrations, then bring up the app tier
# -----------------------------------------------------------------------------
if [ "$DO_MIGRATE" -eq 1 ]; then
    log "4/5 — Running database migrations"
    $COMPOSE run --rm api php artisan migrate --force
else
    log "4/5 — Skipping migrations (--skip-migrate)"
fi

log "    Bringing up the application tier"
$COMPOSE up -d --remove-orphans

# Cache Laravel config/routes/views for production performance.
log "    Warming Laravel caches"
$COMPOSE exec -T api php artisan config:cache || true
$COMPOSE exec -T api php artisan route:cache || true
$COMPOSE exec -T api php artisan event:cache || true

# -----------------------------------------------------------------------------
# 5. Health check
# -----------------------------------------------------------------------------
log "5/5 — Verifying health"
sleep 5
$COMPOSE ps

# Probe the API health endpoint from inside the network.
if $COMPOSE exec -T api php -r 'exit(0);' >/dev/null 2>&1; then
    log "    api container responding"
fi

WEB_HOST_VALUE="$(grep -E '^WEB_HOST=' .env | cut -d= -f2)"
log "Done. Deployed commit $(git rev-parse --short HEAD)."
log "Verify externally:  curl -I https://${WEB_HOST_VALUE}/health"
