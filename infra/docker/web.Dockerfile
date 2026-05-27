# =============================================================================
# BmsSiteOps Web — SvelteKit 5 / adapter-node / Node 22 Alpine
# =============================================================================
# Image is consumed by infra/compose/docker-compose.{dev,prod}.yml as service
# `web`. Caddy reverse-proxies non-API/admin paths to this service on port 3000.
#
# Multi-stage build:
#   1) deps    — install npm deps with cache mounting
#   2) build   — run svelte-kit build (produces build/ via adapter-node)
#   3) runtime — minimal Alpine + Node + just the built output + prod deps
#
# Development uses `npm run dev` with bind-mounted source — see compose dev file.
# =============================================================================

ARG NODE_VERSION=22

# -----------------------------------------------------------------------------
# Stage 1 — deps
# -----------------------------------------------------------------------------
FROM node:${NODE_VERSION}-alpine AS deps

WORKDIR /app

# Copy lockfiles first for layer caching
COPY apps/web/package.json apps/web/package-lock.json* ./

RUN --mount=type=cache,target=/root/.npm \
    npm ci --include=dev

# -----------------------------------------------------------------------------
# Stage 2 — build
# -----------------------------------------------------------------------------
FROM node:${NODE_VERSION}-alpine AS build

WORKDIR /app

COPY --from=deps /app/node_modules /app/node_modules
COPY apps/web/ .

# SvelteKit build with adapter-node produces ./build/index.js
ENV NODE_ENV=production
RUN npm run build

# Reinstall only production dependencies for the final image
RUN --mount=type=cache,target=/root/.npm \
    npm ci --omit=dev

# -----------------------------------------------------------------------------
# Stage 3 — runtime
# -----------------------------------------------------------------------------
FROM node:${NODE_VERSION}-alpine AS runtime

WORKDIR /app

# Non-root user
RUN addgroup -g 1000 -S app && adduser -u 1000 -S app -G app

# Copy only what we need to run
COPY --from=build --chown=app:app /app/build       ./build
COPY --from=build --chown=app:app /app/node_modules ./node_modules
COPY --from=build --chown=app:app /app/package.json ./package.json

USER app

ENV NODE_ENV=production
ENV PORT=3000
ENV HOST=0.0.0.0

EXPOSE 3000

# Healthcheck — adapter-node responds on / with 200 once booted
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD wget --quiet --spider http://127.0.0.1:3000/ || exit 1

CMD ["node", "build/index.js"]
