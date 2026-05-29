#!/usr/bin/env bash
# =============================================================================
# BmsSiteOps — end-to-end smoke test
# =============================================================================
#
# Validates the full Site Q&A pipeline against a running platform:
#
#   1.  POST /api/v1/documents   upload a synthetic document
#   2.  poll worker until the document hits status=ready (embedding done)
#   3.  POST /api/v1/qa           ask a question whose answer is in the doc
#   4.  assert the response is status=ready with non-empty answer + citations
#
# Designed for hand-run validation after every prod-deploy and as the
# acceptance test that the live BOLDNJPC AI stack is wired correctly.
#
# Required env vars (failed-fast if missing):
#   API_BASE_URL      base URL of the api service        (e.g. https://ops.bmssiteops.com)
#   API_BEARER_TOKEN  a Sanctum personal access token issued via
#                     `php artisan tinker` -> User::find(1)->createToken('smoke')
#
# Optional:
#   POLL_TIMEOUT_SEC  how long to wait for the worker to embed (default 120)
#   POLL_INTERVAL_SEC delay between polls (default 3)
#   QUESTION          override the question text
#   VERBOSE=1         dump full API responses, not just status
#
# Exit codes:
#   0  smoke passed
#   1  required env var missing
#   2  document upload failed
#   3  embedding timed out
#   4  Q&A failed
#   5  citations missing / empty
# =============================================================================

set -euo pipefail

# --- Required env --------------------------------------------------------------
: "${API_BASE_URL:?API_BASE_URL is required (e.g. https://ops.bmssiteops.com)}"
: "${API_BEARER_TOKEN:?API_BEARER_TOKEN is required (Sanctum personal access token)}"

# --- Optional env --------------------------------------------------------------
POLL_TIMEOUT_SEC="${POLL_TIMEOUT_SEC:-120}"
POLL_INTERVAL_SEC="${POLL_INTERVAL_SEC:-3}"
VERBOSE="${VERBOSE:-0}"

QUESTION="${QUESTION:-When does AHU-1 start in heating mode?}"

# --- Synthetic document --------------------------------------------------------
# A document whose content unambiguously contains the answer to QUESTION,
# so a correctly-functioning RAG pipeline must surface it.
DOC_TITLE="smoke-test-$(date +%s)"
DOC_CONTENT='Mechanical room AHU-1 sequence of operations.

AHU-1 serves the lobby. In heating mode, the unit starts when outdoor air
temperature drops below 55F. In cooling mode, AHU-1 starts when OAT rises
above 70F. The chilled water plant supplies 44F CHWS to the coil.

The control logic is implemented in the Honeywell JACE-8000 station as a
periodic schedule. AHU-1 cycles based on space temperature setpoint
deviation of more than 2F.'

# --- Helpers -------------------------------------------------------------------
log() { printf '  \033[36m·\033[0m %s\n' "$*" >&2; }
ok()  { printf '  \033[32m✓\033[0m %s\n' "$*" >&2; }
fail() { printf '  \033[31m✗\033[0m %s\n' "$*" >&2; }

call_api() {
    local method=$1 path=$2
    shift 2
    curl -sS -X "$method" "${API_BASE_URL}${path}" \
        -H "Authorization: Bearer ${API_BEARER_TOKEN}" \
        -H "Accept: application/json" \
        -H "Content-Type: application/json" \
        "$@"
}

# Robust JSON field extraction — uses python3 (always available on the
# operator host alongside Docker) rather than jq which may not be installed.
jget() {
    local field=$1
    python3 -c "
import json, sys
try:
    data = json.load(sys.stdin)
    # Navigate dot-separated path: 'data.id', 'data.status', etc.
    for k in '${field}'.split('.'):
        data = data[k] if isinstance(data, dict) else data[int(k)]
    print(data)
except Exception as e:
    print(f'<jget error: {e}>', file=sys.stderr)
    sys.exit(1)
"
}

# --- Step 1: upload document ---------------------------------------------------

printf '\n\033[1mBmsSiteOps smoke test\033[0m\n'
printf '   API: %s\n' "$API_BASE_URL"
printf '   Q:   %s\n\n' "$QUESTION"

log "uploading synthetic document '$DOC_TITLE'..."
DOC_RESP=$(call_api POST /api/v1/documents -d "$(python3 -c "
import json, os
print(json.dumps({
    'title': os.environ['DOC_TITLE'],
    'content': os.environ['DOC_CONTENT'],
    'source_type': 'manual'
}))" DOC_TITLE="$DOC_TITLE" DOC_CONTENT="$DOC_CONTENT")")

[ "$VERBOSE" = "1" ] && echo "$DOC_RESP" >&2 || true

DOC_ID=$(echo "$DOC_RESP" | jget 'data.id') || { fail "couldn't parse doc id from response"; exit 2; }
ok "document created: id=$DOC_ID"

# --- Step 2: poll until embedded -----------------------------------------------

log "waiting up to ${POLL_TIMEOUT_SEC}s for embedding (poll every ${POLL_INTERVAL_SEC}s)..."

ELAPSED=0
while [ "$ELAPSED" -lt "$POLL_TIMEOUT_SEC" ]; do
    STATUS_RESP=$(call_api GET "/api/v1/documents/${DOC_ID}")
    STATUS=$(echo "$STATUS_RESP" | jget 'data.status')

    case "$STATUS" in
        ready)
            ok "embedding complete (${ELAPSED}s elapsed)"
            break
            ;;
        failed)
            fail "embedding failed: $(echo "$STATUS_RESP" | jget 'data.error' || echo unknown)"
            exit 3
            ;;
        pending|embedding)
            sleep "$POLL_INTERVAL_SEC"
            ELAPSED=$((ELAPSED + POLL_INTERVAL_SEC))
            ;;
        *)
            fail "unexpected status: $STATUS"
            exit 3
            ;;
    esac
done

if [ "$STATUS" != "ready" ]; then
    fail "embedding timed out after ${POLL_TIMEOUT_SEC}s (last status: $STATUS)"
    exit 3
fi

# --- Step 3: ask the question --------------------------------------------------

log "asking: '$QUESTION'..."

QA_RESP=$(call_api POST /api/v1/qa -d "$(python3 -c "
import json, os
print(json.dumps({'question': os.environ['QUESTION']}))" QUESTION="$QUESTION")")

[ "$VERBOSE" = "1" ] && echo "$QA_RESP" >&2 || true

QA_STATUS=$(echo "$QA_RESP" | jget 'data.status') || { fail "couldn't parse Q&A status"; exit 4; }

case "$QA_STATUS" in
    ready)
        ok "Q&A returned status=ready"
        ;;
    failed)
        fail "Q&A pipeline failed: $(echo "$QA_RESP" | jget 'data.error' || echo unknown)"
        exit 4
        ;;
    *)
        fail "unexpected Q&A status: $QA_STATUS"
        exit 4
        ;;
esac

# --- Step 4: validate the answer has citations + content -----------------------

ANSWER=$(echo "$QA_RESP" | jget 'data.answer')
if [ -z "$ANSWER" ] || [ "$ANSWER" = "None" ]; then
    fail "Q&A returned empty answer"
    exit 5
fi
ok "answer length: $(echo -n "$ANSWER" | wc -c) chars"

# Citations should be non-empty (we just uploaded a relevant doc).
CITATION_COUNT=$(echo "$QA_RESP" | python3 -c "
import json, sys
data = json.load(sys.stdin)
print(len(data.get('data', {}).get('citations', [])))")

if [ "$CITATION_COUNT" -lt 1 ]; then
    fail "Q&A returned no citations (expected at least 1 since we uploaded a relevant document)"
    exit 5
fi
ok "citations: $CITATION_COUNT"

# --- Cleanup -------------------------------------------------------------------

log "cleaning up smoke-test document..."
call_api DELETE "/api/v1/documents/${DOC_ID}" >/dev/null
ok "deleted document id=$DOC_ID"

# --- Done ----------------------------------------------------------------------

printf '\n\033[1;32m✓ smoke test passed\033[0m\n'
printf '  total elapsed: ~%ds\n\n' "$ELAPSED"

exit 0
