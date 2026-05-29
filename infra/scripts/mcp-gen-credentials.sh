#!/usr/bin/env bash
# =============================================================================
# BmsSiteOps — generate a bcrypt hash for the MCP basic-auth credential.
# =============================================================================
#
# Caddy's `basic_auth` directive stores bcrypt hashes, NOT plaintext passwords.
# This helper wraps `caddy hash-password` from the official caddy:2-alpine
# image so you don't need caddy installed locally.
#
# Usage:
#
#     make mcp-gen-credentials
#
#     # Or directly:
#     ./infra/scripts/mcp-gen-credentials.sh
#
# After getting the hash:
#
#   1. Copy infra/caddy/mcp-basic-auth.conf.example to mcp-basic-auth.conf
#   2. Set MCP_BASIC_AUTH_HASH=<the hash> in .env on the prod host
#   3. Restart caddy: make prod-restart
# =============================================================================

set -euo pipefail

# Sanity: docker present?
if ! command -v docker >/dev/null 2>&1; then
    echo "✗ docker is required but not found on PATH" >&2
    exit 1
fi

printf '\n\033[1mBmsSiteOps — generate MCP basic-auth credential\033[0m\n\n'
printf 'Enter a strong password for the MCP endpoint.\n'
printf 'Suggested: %s\n\n' "$(openssl rand -base64 24 2>/dev/null || echo 'use a 24+ char passphrase')"

# Read silently so the password doesn't echo to the terminal.
read -srp "Password: " password
echo ""
read -srp "Confirm:  " password_confirm
echo ""

if [ "$password" != "$password_confirm" ]; then
    echo ""
    echo "✗ passwords do not match" >&2
    exit 2
fi

if [ "${#password}" -lt 12 ]; then
    echo ""
    echo "✗ password must be at least 12 characters" >&2
    exit 3
fi

echo ""
echo "Generating bcrypt hash (cost factor 14 — this takes a few seconds)..."
echo ""

hash=$(printf '%s' "$password" \
    | docker run -i --rm caddy:2-alpine \
        caddy hash-password --plaintext-from-stdin)

cat <<EOF

==============================================================================
Your bcrypt hash:

    $hash

==============================================================================

Next steps:

  1. Add to .env on the prod host:

       MCP_BASIC_AUTH_HASH=$hash

  2. Enable basic_auth by replacing the stub with the example:

       cp infra/caddy/mcp-basic-auth.conf.example infra/caddy/mcp-basic-auth.conf

  3. Restart caddy:

       make prod-restart

  4. Test the protection:

       # Without credentials -> 401:
       curl -i https://\${MCP_HOST}/sse

       # With credentials -> 200:
       curl -i -u operator:<your password> https://\${MCP_HOST}/sse

==============================================================================

EOF
