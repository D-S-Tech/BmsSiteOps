# Contributing

BmsSiteOps is in pre-alpha and developed primarily by [Bold Mechanical & Controls Enterprise, Inc.](https://boldmech.com) for internal use. External contributions are welcome but will be reviewed conservatively.

## Before you open a PR

1. Open an issue describing the change first. This avoids the case where you spend a weekend on a refactor that conflicts with planned work.
2. Read the relevant Architecture Decision Records in [`docs/adr/`](docs/adr/). If your change conflicts with an ADR, your PR must include a new ADR superseding it.
3. Confirm the change does not introduce customer data, credentials, or other security-sensitive content. See [ADR 0004](docs/adr/0004-public-repo-security.md).

## Development setup

```bash
git clone https://github.com/D-S-Tech/BmsSiteOps.git
cd BmsSiteOps
cp .env.example .env
make dev-up
```

Detailed onboarding will be added once the Sprint 0 stack is complete.

## Code style

- **Laravel (PHP)** — Laravel Pint with the default Laravel preset. CI fails on style violations. Run `make api-pint` before committing.
- **SvelteKit (TypeScript)** — Prettier + ESLint. Run `npm run format && npm run lint`.
- **Python (worker)** — Ruff for linting and formatting, mypy in strict mode for type-checking. Run `make worker-lint`.
- **Commit messages** — [Conventional Commits](https://www.conventionalcommits.org/) format. Examples: `feat(api): add Niagara collector`, `fix(worker): handle Fox timeout gracefully`.

## Tests

Every PR must pass:

- Laravel: `make api-test` (Pest)
- SvelteKit: `make web-test` (Vitest)
- Worker: `make worker-test` (pytest)

A PR that reduces test coverage in a non-trivial way must explain why in the description.

## Licensing of contributions

By submitting a pull request, you agree that your contribution is licensed under the [AGPL-3.0](LICENSE) on the same terms as the rest of the project. You retain copyright to your contribution; the AGPL license is what permits its inclusion. See [ADR 0005](docs/adr/0005-license-agpl.md) for the license rationale.

## What we will not merge

- Changes that introduce customer data or credentials, even briefly
- Changes that disable, weaken, or bypass tenant isolation
- Changes that introduce a new dependency with a more restrictive license than AGPL-3.0
- Changes that remove or weaken the ADR for a major architectural choice without a superseding ADR
- Feature requests outside the project's scope (see [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md), section 11)
