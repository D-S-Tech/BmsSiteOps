"""Inbound HMAC verification for Laravel -> worker calls.

Mirror of the outbound signing in app/clients/signing.py — same scheme, just
verifying instead of producing. Laravel signs every request to the worker
/qa/* endpoints with:

    timestamp = current Unix seconds
    signature = hash_hmac('sha256', f"{timestamp}.{body}", WORKER_INTERNAL_KEY)
    headers   X-Worker-Timestamp + X-Worker-Signature

We reject if any of the headers is missing, the timestamp drifts more than
`max_clock_skew` seconds from the worker's clock (replay-attack window), or
the signature doesn't match.
"""

from __future__ import annotations

import hashlib
import hmac
import time

from fastapi import HTTPException, Request, status

from app.config import settings


async def verify_worker_signature(request: Request) -> None:
    """FastAPI dependency — call as `Depends(verify_worker_signature)`.

    Reads the body once (caches on the Request so route handlers can still
    parse it normally afterwards) and verifies the X-Worker-Timestamp /
    X-Worker-Signature pair.
    """
    cfg = settings()

    timestamp_header = request.headers.get("x-worker-timestamp")
    signature_header = request.headers.get("x-worker-signature")
    if not timestamp_header or not signature_header:
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="Missing X-Worker-Timestamp or X-Worker-Signature header.",
        )

    try:
        timestamp = int(timestamp_header)
    except ValueError as exc:
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="X-Worker-Timestamp must be an integer.",
        ) from exc

    skew = abs(int(time.time()) - timestamp)
    if skew > cfg.worker_max_clock_skew:
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail=f"Timestamp drift {skew}s exceeds the allowed clock skew.",
        )

    body_bytes = await request.body()
    payload = f"{timestamp}.".encode() + body_bytes
    expected = hmac.new(
        cfg.worker_internal_key.get_secret_value().encode("utf-8"),
        payload,
        hashlib.sha256,
    ).hexdigest()

    if not hmac.compare_digest(expected, signature_header):
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="Invalid signature.",
        )
