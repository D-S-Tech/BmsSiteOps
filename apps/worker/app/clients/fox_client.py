"""Experimental Fox protocol client for Niagara.

HONEST STATUS — read before trusting this module:

The Fox protocol is proprietary, binary-ish, and has no clean public
specification. The pieces here are split by how confidently they can be
verified WITHOUT a real JACE:

  * Typed-scalar value codec (encode_value / decode_value) — Niagara's
    documented `type:value` scalar encoding. Deterministic and unit-tested.
    This is real, trustworthy code.

  * The live Fox session — TCP connect, greeting, digest authentication, and
    BQL subscription — is NOT implemented. It requires reverse-engineering
    validated against real hardware; a self-consistent mock would prove
    nothing. connect()/read_points() therefore raise NotImplementedError with
    a clear message until completed and field-validated on a JACE.

The Niagara collector's fox-path MAPPING (FoxPoint -> device/event) is fully
unit-tested via a fake FoxTransport, independent of this client.
"""

from __future__ import annotations

from app.clients.fox import FoxPoint, FoxTransport

_EXPERIMENTAL = (
    "Fox live session is experimental and not implemented — it must be "
    "completed and validated against a real JACE. The Fox value codec in this "
    "module is usable; the network session is not."
)


class FoxValueError(ValueError):
    """Raised when a Fox typed-scalar token cannot be decoded."""


def encode_value(value: bool | int | float | str | None) -> str:
    """Encode a Python scalar into Niagara's Fox `type:value` form.

    Prefixes: b=bool, i=int, r=real(float), s=string, n=null. (bool is checked
    before int because bool is a subclass of int in Python.)
    """
    if value is None:
        return "n:"
    if isinstance(value, bool):
        return f"b:{'true' if value else 'false'}"
    if isinstance(value, int):
        return f"i:{value}"
    if isinstance(value, float):
        return f"r:{value}"
    return f"s:{value}"


def decode_value(token: str) -> bool | int | float | str | None:
    """Decode a Niagara Fox `type:value` token into a Python scalar."""
    prefix, sep, raw = token.partition(":")
    if not sep:
        raise FoxValueError(f"malformed Fox value token: {token!r}")

    match prefix:
        case "n":
            return None
        case "b":
            return raw == "true"
        case "i" | "l":
            try:
                return int(raw)
            except ValueError as exc:
                raise FoxValueError(f"bad int token: {token!r}") from exc
        case "r" | "d":
            try:
                return float(raw)
            except ValueError as exc:
                raise FoxValueError(f"bad real token: {token!r}") from exc
        case "s":
            return raw
        case _:
            raise FoxValueError(f"unknown Fox value type prefix: {prefix!r}")


class FoxClient(FoxTransport):
    """Fox transport for a single Niagara station — live session experimental."""

    DEFAULT_PORT = 1911  # fox://  (foxs:// TLS is 4911)

    def __init__(
        self,
        host: str,
        username: str,
        password: str,
        *,
        port: int = DEFAULT_PORT,
    ) -> None:
        # Construction performs NO I/O (Collector contract).
        self._host = host
        self._username = username
        self._password = password
        self._port = port

    async def read_points(self) -> list[FoxPoint]:
        raise NotImplementedError(_EXPERIMENTAL)

    async def close(self) -> None:
        # No live session to close yet.
        return None
