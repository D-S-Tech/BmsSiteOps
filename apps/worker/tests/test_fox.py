"""Tests for the Fox typed-scalar value codec and status mapping.

These cover the deterministic, verifiable parts of the Fox support: the
value encoding and the BStatus -> device/severity mapping. The live Fox
session is experimental and intentionally not exercised here.
"""

from __future__ import annotations

import pytest

from app.clients.fox import fox_device_status, fox_event_severity
from app.clients.fox_client import FoxValueError, decode_value, encode_value


@pytest.mark.parametrize(
    ("value", "token"),
    [
        (None, "n:"),
        (True, "b:true"),
        (False, "b:false"),
        (42, "i:42"),
        (-7, "i:-7"),
        (72.5, "r:72.5"),
        ("hello", "s:hello"),
    ],
)
def test_encode_value(value: object, token: str) -> None:
    assert encode_value(value) == token  # type: ignore[arg-type]


def test_round_trip_scalars() -> None:
    for value in (None, True, False, 0, 123, -9, 3.14, "zone temp"):
        assert decode_value(encode_value(value)) == value  # type: ignore[arg-type]


def test_decode_long_and_double_prefixes() -> None:
    assert decode_value("l:9001") == 9001
    assert decode_value("d:1.25") == 1.25


def test_bool_is_encoded_before_int() -> None:
    # bool is a subclass of int — must encode as b:, not i:
    assert encode_value(True) == "b:true"
    assert encode_value(False) == "b:false"


def test_decode_rejects_malformed_token() -> None:
    with pytest.raises(FoxValueError):
        decode_value("no-colon-here")


def test_decode_rejects_unknown_prefix() -> None:
    with pytest.raises(FoxValueError):
        decode_value("z:whatever")


def test_decode_rejects_bad_numbers() -> None:
    with pytest.raises(FoxValueError):
        decode_value("i:notanint")
    with pytest.raises(FoxValueError):
        decode_value("r:notafloat")


@pytest.mark.parametrize(
    ("status", "expected"),
    [
        ("ok", "online"),
        ("alarm", "online"),
        ("down", "offline"),
        ("disabled", "offline"),
    ],
)
def test_fox_device_status(status: str, expected: str) -> None:
    assert fox_device_status(status) == expected


@pytest.mark.parametrize(
    ("status", "expected"),
    [
        ("ok", None),
        ("alarm", "critical"),
        ("unackedAlarm", "critical"),
        ("fault", "warning"),
        ("stale", "warning"),
        ("overridden", "warning"),
    ],
)
def test_fox_event_severity(status: str, expected: str | None) -> None:
    assert fox_event_severity(status) == expected
