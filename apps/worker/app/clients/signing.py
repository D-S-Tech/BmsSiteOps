"""Shared HMAC signing for internal worker -> Laravel API calls.

The Laravel VerifyWorkerSignature middleware expects:

    payload   = "{timestamp}.{raw_body}"
    signature = hex( hmac_sha256(WORKER_INTERNAL_KEY, payload) )

sent as X-Worker-Timestamp / X-Worker-Signature headers. For GET requests the
raw body is the empty string.
"""

from __future__ import annotations

import hashlib
import hmac
import time


def signed_headers(internal_key: str, body: str) -> dict[str, str]:
    """Return the signed headers for a request body (use '' for GET)."""
    timestamp = str(int(time.time()))
    signature = hmac.new(
        internal_key.encode(),
        f"{timestamp}.{body}".encode(),
        hashlib.sha256,
    ).hexdigest()
    return {
        "X-Worker-Timestamp": timestamp,
        "X-Worker-Signature": signature,
        "Content-Type": "application/json",
    }
