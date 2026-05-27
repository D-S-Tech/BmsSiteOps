# Architecture Decision Records

This directory contains the chronological record of significant architectural decisions made in the BmsSiteOps project. Every decision that is non-trivial to reverse — choice of framework, data model, deployment model, security posture — should be captured here as an ADR.

## Why ADRs

1. **Memory** — Six months from now, no one will remember why the database choice went the way it did. The ADR captures the context that produced the decision.
2. **Onboarding** — A new contributor reading the ADRs in order absorbs the project's intellectual history, not just its current state.
3. **Reversal** — When a decision is later overturned, the new ADR explicitly supersedes the old one. The history is preserved, not silently rewritten.

## Format

Each ADR is a single Markdown file named `NNNN-kebab-case-title.md`. The number is zero-padded to four digits and never reused. The first line is the title as an H1.

The recommended sections:

- **Status** — `Proposed` / `Accepted` / `Deprecated` / `Superseded by NNNN`
- **Date** — ISO date of acceptance
- **Context** — what forces are in play
- **Decision** — what we chose
- **Consequences** — what follows from this, good and bad
- **Alternatives considered** — the roads not taken, and why

## Index

| #    | Title                                                  | Status   | Date       |
|------|--------------------------------------------------------|----------|------------|
| 0001 | [Monorepo](./0001-monorepo.md)                         | Accepted | 2026-05-27 |
| 0002 | [Multi-tenancy: row-level](./0002-multi-tenancy-row-level.md) | Accepted | 2026-05-27 |
| 0003 | [Stack choices](./0003-stack-choices.md)               | Accepted | 2026-05-27 |
| 0004 | [Public repo security posture](./0004-public-repo-security.md) | Accepted | 2026-05-27 |
| 0005 | [License: AGPL-3.0](./0005-license-agpl.md)            | Accepted | 2026-05-27 |
