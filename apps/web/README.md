# BmsSiteOps Web — SvelteKit 5 frontend

The SvelteKit 5 application that fronts BmsSiteOps. It talks to the Laravel API at `/api/v1/*` and renders the operator UI: site dashboards, alerts, scripts, and configuration.

## Stack

- SvelteKit 2.57 + Svelte 5 (runes mode forced project-wide)
- TypeScript 6
- Tailwind 4 (via `@tailwindcss/vite` plugin, no PostCSS)
- Vitest 4 (unit tests under `src/**/*.spec.ts`)
- `@sveltejs/adapter-node` for production SSR
- ESLint + Prettier (matched in CI)

## Running locally

The web app is run via Docker Compose from the repository root:

```bash
make dev-up-all          # brings up everything
make logs-web            # tail logs
make sh-web              # shell into the container
```

For ad-hoc local dev outside Docker:

```bash
cd apps/web
cp .env.example .env
npm install
npm run dev -- --open    # opens http://localhost:5173
```

## Layout

```
src/
├── app.css                          Tailwind import + design-tokens import
├── app.d.ts                         App.Error / App.Locals / App.PageData types
├── app.html                         shell document
└── lib/
    ├── design-tokens.css            CSS custom properties (light + dark)
    ├── api.ts                       typed fetch client → /api/v1/*
    ├── auth.ts                      bearer token + user storage helpers
    ├── auth.spec.ts                 Vitest unit tests for auth.ts
    ├── types.ts                     domain types mirroring Laravel models
    └── index.ts                     barrel export
└── routes/
    ├── +layout.svelte               app shell (header, footer, navigation)
    └── +page.svelte                 landing stub (Sprint 3 will replace)
```

## API client

Use `$lib/api` to call the Laravel backend:

```ts
import { api } from '$lib/api';
import type { Paginated, Site } from '$lib/types';

const { data: sites } = await api.get<Paginated<Site>>('/sites');

await api.post('/sites', {
	slug: 'northern-blvd',
	name: '4401 Northern Boulevard',
	timezone: 'America/New_York'
});
```

All requests are sent to `${PUBLIC_API_BASE_URL}/api/v1/*` with the bearer token from `$lib/auth` attached if one is set. Failures raise an `ApiError` exposing `status`, `body`, and `url`.

## Auth

Sanctum bearer tokens are kept in `localStorage` via `$lib/auth`. The current Sprint 0 surface is just a stub — Sprint 1 lands the actual login form, hooks.server.ts session reconstruction, and tenant-switching flow.

## Tests

```bash
make web-test                                       # full suite via Docker
cd apps/web && npm run test -- --run                # ad-hoc
cd apps/web && npm run test                         # watch mode
```

The Vitest project under `vite.config.ts` runs server tests (Node environment) over `src/**/*.{test,spec}.{js,ts}`. Component tests (.svelte.test.ts) will be added in Sprint 3.

## Type checking

```bash
make web-check                                      # via Docker
cd apps/web && npm run check                        # ad-hoc
```

`svelte-check` runs over the project and surfaces TypeScript + a11y warnings.
