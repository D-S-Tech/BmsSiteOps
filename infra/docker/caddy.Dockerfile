# =============================================================================
# BmsSiteOps — Caddy with rate_limit module (Sprint 8.6)
# =============================================================================
#
# Custom Caddy build that includes the caddy-ratelimit module
# (github.com/mholt/caddy-ratelimit — third-party but maintained by Caddy's
# lead developer Matt Holt). The module isn't in the upstream caddy:2-alpine
# image and is required for the `rate_limit` directive used on the MCP host
# in infra/caddy/Caddyfile.
#
# The module is always built in; its directive only fires when the Caddyfile
# uses it, so existing deploys with no rate_limit block keep working with
# this image.
#
# Build:
#     docker build -f infra/docker/caddy.Dockerfile -t bmssiteops/caddy:latest .
#
# Used by:
#     infra/compose/docker-compose.prod.yml — `caddy` service has
#     build: { context: .., dockerfile: docker/caddy.Dockerfile }
# =============================================================================

# ---- builder stage ----------------------------------------------------------
# caddy:2-builder ships with xcaddy + Go toolchain; lets us assemble a custom
# Caddy binary with the modules we want without pulling in the full Go SDK.
FROM caddy:2-builder AS builder

RUN xcaddy build \
    --with github.com/mholt/caddy-ratelimit

# ---- runtime stage ----------------------------------------------------------
# Same alpine base + supervisord layout as upstream caddy:2-alpine, but with
# our custom binary in place of the stock one. Image size delta is ~5 MB.
FROM caddy:2-alpine

COPY --from=builder /usr/bin/caddy /usr/bin/caddy

# Inherit the upstream image's ENTRYPOINT, CMD, healthcheck, EXPOSE 80/443/2019,
# WORKDIR /srv, etc. — no overrides needed.
