"""Verificação da assinatura dos webhooks de saída da AssinaVelox.

Mesmo algoritmo de ``App\\Services\\Webhooks\\WebhookSignature::verify()`` (docs/fase-2/webhooks.md §4),
conferido pelos vetores gerados pelo PHP real (sdks/testdata/webhook-signature-vectors.json):

    X-AssinaVelox-Signature: v1=<hex(HMAC-SHA256(segredo, "{timestamp}.{corpo bruto}"))>

- a chave do HMAC é o segredo inteiro, como mostrado (inclui ``whsec_``);
- assine e confira sobre os BYTES BRUTOS do corpo, antes de qualquer parse de JSON;
- janela de tempo: recuse se ``|agora - timestamp| > 300`` s (replay);
- durante a rotação do segredo o cabeçalho traz duas assinaturas (``v1=<novo>, v1=<anterior>``):
  basta uma conferir;
- comparação em tempo constante.
"""

from __future__ import annotations

import hashlib
import hmac
import json
import re
import time
from typing import Any, Mapping, Sequence

from .errors import WebhookSignatureError

VERSION = "v1"
SECRET_PREFIX = "whsec_"
SIGNATURE_HEADER = "X-AssinaVelox-Signature"
TIMESTAMP_HEADER = "X-AssinaVelox-Timestamp"
DELIVERY_HEADER = "X-AssinaVelox-Delivery-Id"
EVENT_HEADER = "X-AssinaVelox-Event"
EVENT_ID_HEADER = "X-AssinaVelox-Event-Id"
ATTEMPT_HEADER = "X-AssinaVelox-Attempt"
DEFAULT_TOLERANCE_SECONDS = 300

# Os mesmos caracteres que o trim() do PHP remove (e só eles).
_PHP_TRIM = " \t\n\r\x00\x0b"
# preg_match('/^\d{1,12}$/'): dígitos ASCII e, como no PHP, uma quebra de linha final opcional.
_TIMESTAMP = re.compile(r"[0-9]{1,12}\n?\Z")


def _bytes(value: bytes | bytearray | memoryview | str) -> bytes:
    return value.encode("utf-8") if isinstance(value, str) else bytes(value)


def compute_signature(secret: str, timestamp: int, raw_body: bytes | str) -> str:
    """``hex(HMAC-SHA256(segredo, "{timestamp}.{corpo}"))``."""
    message = f"{int(timestamp)}.".encode("ascii") + _bytes(raw_body)
    return hmac.new(secret.encode("utf-8"), message, hashlib.sha256).hexdigest()


def signature_header(secrets: Sequence[str], timestamp: int, raw_body: bytes | str) -> str:
    """Valor do cabeçalho como a plataforma envia (útil em testes do seu receptor)."""
    return ", ".join(f"{VERSION}={compute_signature(secret, timestamp, raw_body)}" for secret in secrets)


def verify_signature(
    secret: str,
    signature_header: str | None,
    timestamp_header: str | None,
    raw_body: bytes | str,
    *,
    now: int | None = None,
    tolerance: int = DEFAULT_TOLERANCE_SECONDS,
) -> bool:
    """True se alguma assinatura ``v1`` conferir e o timestamp estiver dentro da janela."""
    if not isinstance(timestamp_header, str) or _TIMESTAMP.match(timestamp_header) is None:
        return False
    timestamp = int(timestamp_header.rstrip("\n"))
    current = int(time.time()) if now is None else int(now)
    if abs(current - timestamp) > tolerance:
        return False
    expected = compute_signature(secret, timestamp, raw_body).encode("ascii")
    for part in (signature_header or "").split(","):
        version, _, value = part.strip(_PHP_TRIM).partition("=")
        if version == VERSION and hmac.compare_digest(expected, value.encode("utf-8")):
            return True
    return False


def _header(headers: Mapping[str, str], name: str) -> str | None:
    lowered = name.lower()
    for key, value in headers.items():
        if key.lower() == lowered:
            return value
    return None


def construct_event(
    raw_body: bytes | str,
    headers: Mapping[str, str],
    secret: str,
    *,
    tolerance: int = DEFAULT_TOLERANCE_SECONDS,
    now: int | None = None,
) -> dict[str, Any]:
    """Confere a assinatura e devolve o evento decodificado; senão, WebhookSignatureError.

    Deduplique pelo cabeçalho ``X-AssinaVelox-Delivery-Id`` (igual em todas as tentativas).
    """
    valid = verify_signature(
        secret,
        _header(headers, SIGNATURE_HEADER),
        _header(headers, TIMESTAMP_HEADER),
        raw_body,
        now=now,
        tolerance=tolerance,
    )
    if not valid:
        raise WebhookSignatureError("Assinatura do webhook inválida ou fora da janela de tempo.")
    event = json.loads(_bytes(raw_body).decode("utf-8"))
    if not isinstance(event, dict):
        raise WebhookSignatureError("O corpo do webhook não é um objeto JSON.")
    return event
