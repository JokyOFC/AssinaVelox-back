"""Signature time stamp (PAdES B-T) issued by the OPERATOR TSA, in-process.

``pdftool sign --tsa-pfx ... --tsa-serial N`` plugs :class:`OperatorLocalTimeStamper` into
pyHanko's ``PdfSigner``. The token is issued by :mod:`pdftool.tsa` with the serial that the
platform allocated from its database sequence, so there is exactly ONE real token per serial:

* pyHanko asks the time stamper for a "dummy" response to estimate the signature size
  before signing. A second real token would reuse the serial; instead
  :meth:`async_dummy_response` builds the same structure with a zero-filled, worst-case
  length signature. It is never embedded and never leaves this process.
* the real request is answered once; any second real request is refused.

Declared profile: the output of ``sign`` keeps ``"profile": "PAdES-B-B"`` (roadmap T2 —
no profile beyond B-B is ANNOUNCED before independent external validation). The technical
fact that a signature time stamp was embedded is reported separately, under
``signature_timestamp``, with the label "carimbo do tempo da operadora — não é ICP-Brasil".
"""

from __future__ import annotations

from pathlib import Path
from typing import Any, Dict, Optional

from asn1crypto import cms, tsp
from pyhanko.sign.timestamps.api import TimeStamper
from pyhanko.sign.timestamps.common_utils import dummy_digest

from pdftool.errors import ProcessingError
from pdftool.tsa import (
    TSA_KIND,
    TSA_LABEL,
    TsaCredentials,
    build_token,
    check_request,
    describe_token,
    granted_response,
    load_tsa_credentials,
    validate_policy_oid,
    validate_serial,
)

UNANNOUNCED_PROFILE = "PAdES-B-T"


class OperatorLocalTimeStamper(TimeStamper):
    def __init__(self, creds: TsaCredentials, serial: int, policy_oid: str, accuracy_ms: int = 1000):
        super().__init__(include_nonce=True)
        self.creds = creds
        self.serial = validate_serial(serial)
        self.policy_oid = validate_policy_oid(policy_oid)
        self.accuracy_ms = accuracy_ms
        self.issued: Optional[Dict[str, Any]] = None

    async def async_dummy_response(self, md_algorithm) -> cms.ContentInfo:
        try:
            return self._dummy_response_cache[md_algorithm]
        except KeyError:
            pass
        nonce, req = self.request_cms(dummy_digest(md_algorithm), md_algorithm)
        token, _ = build_token(req, self.creds, self.serial, self.policy_oid, self.accuracy_ms, dry_run=True)
        self._register_dummy(md_algorithm, token)
        del nonce
        return token

    async def async_request_tsa_response(self, req: tsp.TimeStampReq) -> tsp.TimeStampResp:
        if self.issued is not None:
            raise ProcessingError("tsa_serial_already_used", "the allocated TSA serial was already used for a token")
        check_request(req, self.policy_oid)
        token, tst_info = build_token(req, self.creds, self.serial, self.policy_oid, self.accuracy_ms)
        self.issued = describe_token(token, tst_info, self.creds.cert)
        return granted_response(token)

    def report(self) -> Optional[Dict[str, Any]]:
        if self.issued is None:
            return None
        return {
            **self.issued,
            "tsa_kind": TSA_KIND,
            "label": TSA_LABEL,
            "tsa_certificate_test": self.creds.is_test,
            "unannounced_profile": UNANNOUNCED_PROFILE,
            "announced": False,
        }


def operator_timestamper(tsa_pfx: Path, tsa_pass_env: str, serial: int, policy_oid: str, accuracy_ms: int = 1000) -> OperatorLocalTimeStamper:
    return OperatorLocalTimeStamper(load_tsa_credentials(tsa_pfx, tsa_pass_env), serial, policy_oid, accuracy_ms)
