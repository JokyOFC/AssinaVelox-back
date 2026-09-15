"""Apoio dos testes: servidor falso (FAKE_API_URL ou iniciado aqui) e leitura do que ele recebeu."""

from __future__ import annotations

import json
import os
import re
import sys
import urllib.request
import uuid
from pathlib import Path
from typing import Any

REPO = Path(__file__).resolve().parents[3]
VECTORS = REPO / "sdks" / "testdata" / "webhook-signature-vectors.json"
UUID_V4 = re.compile(r"^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$")

_url: str | None = None
_server: Any = None
_direct = urllib.request.build_opener(urllib.request.ProxyHandler({}))


def fake_url() -> str:
    """URL base do servidor falso. Sem FAKE_API_URL, sobe um na própria thread de teste."""
    global _url, _server
    if _url:
        return _url
    configured = os.environ.get("FAKE_API_URL")
    if configured:
        _url = configured.rstrip("/")
        return _url
    sys.path.insert(0, str(REPO / "tools" / "sdkgen"))
    import fake_server  # noqa: PLC0415

    _server, _url = fake_server.start_in_thread()
    return _url


def origin() -> str:
    url = fake_url()
    return url[: url.index("/api/v1")]


def new_trace() -> tuple[dict[str, str], str]:
    trace = uuid.uuid4().hex
    return {"X-Fake-Trace": trace}, trace


def fetch_trace(trace: str) -> list[dict[str, Any]]:
    with _direct.open(f"{origin()}/__fake/requests?trace={trace}", timeout=10) as response:
        return json.loads(response.read().decode("utf-8"))


def free_port_url() -> str:
    """Endereço sem ninguém escutando (conexão recusada)."""
    import socket

    with socket.socket() as sock:
        sock.bind(("127.0.0.1", 0))
        port = sock.getsockname()[1]
    return f"http://127.0.0.1:{port}/api/v1"


def load_vectors() -> dict[str, Any]:
    return json.loads(VECTORS.read_text(encoding="utf-8"))
