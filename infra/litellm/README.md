# =============================================================================
# BmsSiteOps — LiteLLM proxy README
# =============================================================================
#
# This directory holds the **opt-in** LiteLLM proxy service that fronts every
# LLM and embedding call in the platform.
#
# Why opt-in: most BmsSiteOps deployments already have a LiteLLM proxy running
# elsewhere (e.g. on a dedicated AI workstation like BOLDNJPC), in which case
# the BmsSiteOps platform should talk to that proxy directly — no need for a
# second one. The compose service in `docker-compose.prod.yml` is therefore
# behind the `litellm` profile and only starts when you ask for it.
#
# ---------------------------------------------------------------------------
# Mode 1: external LiteLLM (recommended for shared AI infrastructure)
# ---------------------------------------------------------------------------
#
# Don't enable the profile. Set in `.env`:
#
#     LITELLM_BASE_URL=http://10.0.0.42:4000   # your AI workstation
#     LITELLM_MASTER_KEY=<the proxy's master key>
#
# The api + worker containers will read those and hit the external proxy.
#
# ---------------------------------------------------------------------------
# Mode 2: self-contained LiteLLM (single-host deploys)
# ---------------------------------------------------------------------------
#
# Enable the profile:
#
#     COMPOSE_PROFILES=litellm make prod-up
#
# Set in `.env`:
#
#     LITELLM_BASE_URL=http://litellm:4000
#     LITELLM_MASTER_KEY=<generate with: openssl rand -base64 32>
#     OLLAMA_API_BASE=http://host.docker.internal:11434   # host-side Ollama
#     ANTHROPIC_API_KEY=sk-ant-...
#
# Then `make prod-up` brings up the litellm container alongside everything else.
#
# ---------------------------------------------------------------------------
# Config file
# ---------------------------------------------------------------------------
#
# `config.yaml` is the LiteLLM model registry. Each `model_list` entry names a
# model BmsSiteOps can request and maps it to a vendor route + credentials.
#
# To add a new model, append to model_list:
#
#     - model_name: my-new-model
#       litellm_params:
#         model: <vendor-prefix>/<vendor-model-id>
#         api_key: os.environ/SOME_ENV_VAR
#
# Then redeploy: `make prod-restart` (or just restart the litellm container).
#
# ---------------------------------------------------------------------------
# Health check
# ---------------------------------------------------------------------------
#
#     curl http://localhost:4000/health/liveliness
#
# Should return `"OK"`. The compose service has a healthcheck wired to this
# endpoint with 30s interval, 3 retries.
