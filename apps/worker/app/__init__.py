"""BmsSiteOps async worker.

Hosts the FastAPI application that exposes:

- /health           liveness probe (no auth)
- /internal/*       endpoints called by the Laravel API (HMAC-authenticated)
- /mcp/*            Model Context Protocol server (Sprint 7)

It also runs the async collector loop (TRMM, Niagara, BACnet) that polls
configured data sources and pushes normalized events into the database
and into Redis pub/sub for the Laravel side to consume.
"""

__version__ = "0.1.0"
