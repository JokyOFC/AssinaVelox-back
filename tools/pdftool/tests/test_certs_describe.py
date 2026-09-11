"""``describe_pkcs12`` with a missing file (integração I-2C).

Regression test for the defect reported by K-A1: ``certs.describe_pkcs12`` raised
``InputRejected`` without importing it, so a missing operator PFX produced a ``NameError``
(exit 3, generic) instead of the contract's ``missing_input`` rejection (exit 4).
"""

from __future__ import annotations

from pathlib import Path

import pytest

from pdftool.certs import describe_pkcs12
from pdftool.errors import EXIT_INPUT_REJECTED, InputRejected

PASS_ENV = "DESCRIBE_MISSING_PASS"
PASSPHRASE = "senha-que-nao-pode-vazar-Q7"


def test_missing_pfx_is_rejected_with_the_contract_code(monkeypatch: pytest.MonkeyPatch, tmp_path: Path) -> None:
    monkeypatch.setenv(PASS_ENV, PASSPHRASE)

    with pytest.raises(InputRejected) as caught:
        describe_pkcs12(tmp_path / "nao-existe.pfx", PASS_ENV)

    error = caught.value
    assert error.code == "missing_input"
    assert error.exit_code == EXIT_INPUT_REJECTED
    assert PASSPHRASE not in error.message
    assert PASSPHRASE not in str(error.to_json())
