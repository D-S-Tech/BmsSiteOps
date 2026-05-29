# Laravel integration tests

Tests in this directory hit live external services — primarily a running
worker (FastAPI on :8000) and through it a real LiteLLM proxy. They are
**excluded from the default test run** via the `<group>integration</group>`
exclusion in `phpunit.xml`.

## Running them

```bash
cd apps/api

# Bring the worker stack up first
docker compose -f ../../infra/compose/docker-compose.dev.yml up -d

# Set env vars
export LIVE_TESTS=1
export WORKER_URL=http://localhost:8001
export WORKER_INTERNAL_KEY=<the worker's key>

# Run only the integration suite
php artisan test --testsuite=Integration
```

Or via the Makefile shortcut:

```bash
make api-test-integration
```

## What's tested

The integration suite proves that every Laravel→worker round trip works:

* **QA pipeline** — POST /api/v1/qa actually orchestrates embedding +
  search + answer against a real worker, and gets a Ready row back.
* **Worker HMAC** — the X-Worker-Timestamp / X-Worker-Signature scheme
  signs requests that the worker accepts.

## Skip behaviour

Each test calls `$this->skipUnlessLive()` in `setUp()`, which checks for
the `LIVE_TESTS=1` environment variable. Missing it -> the test is
marked skipped (not failed). All tests also carry the
`@group integration` annotation so PHPUnit excludes them by default
regardless of env.
