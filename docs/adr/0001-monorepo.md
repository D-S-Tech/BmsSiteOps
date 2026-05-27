# ADR 0001 — Monorepo

**Status:** Accepted
**Date:** 2026-05-27

## Context

BmsSiteOps is built from three runtime artifacts that ship together and evolve together:

1. A Laravel API backend with an embedded Filament admin panel.
2. A SvelteKit frontend.
3. A Python worker hosting data-source collectors and AI task handlers.

These three are not independently useful. A new collector in the worker means a new `Source` kind in the API, a new admin form in Filament, and a new view in SvelteKit. Coupling is intrinsic to the product.

We considered three repository layouts:

- **Three independent repos** with semantic versioning and a fourth "deploy" repo orchestrating them.
- **Monorepo** with all three apps as top-level directories.
- **Monorepo with workspaces** (pnpm/turborepo style) — overkill for three apps in three different languages.

## Decision

A monorepo with `apps/api/`, `apps/web/`, and `apps/worker/` as the three application roots, plus `infra/` for deployment artifacts and `docs/` for documentation. No workspace tooling.

## Consequences

**Positive**

- A single PR can change the API contract, the SvelteKit consumer, and the worker producer atomically. No version-skew window.
- One issue tracker, one release process, one CI pipeline.
- New contributors clone one URL and see the whole system.
- Deployment ordering is trivial: the Docker Compose file references all three with the same Git SHA.

**Negative**

- Larger clone size (mitigated; the repo will not contain binary assets).
- CI must be selective about which app it rebuilds on a given PR (mitigated with path filters in GitHub Actions).
- If we ever genuinely need to open-source one app independently (unlikely — the value is in the whole), we'd need to extract it.

## Alternatives considered

- **Three repos** was tempting for the "clean boundaries" argument, but the boundaries are tighter than the repo layout would suggest. Cross-repo PRs across three repos for every feature would be a constant tax.
- **Workspace tooling (pnpm, turbo, nx)** assumes a JavaScript-centric world. We have one JS app, one PHP app, and one Python app — the value of shared workspace tooling is low.

## See also

- [ADR 0003 — Stack choices](./0003-stack-choices.md)
