"""Especificação OpenAPI da API v1: exportação, normalização e impressão digital."""

from __future__ import annotations

import copy
import hashlib
import json
import os
import subprocess
import tempfile
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[3]
SPEC_PATH = ROOT / "sdks" / "openapi" / "v1.json"
OVERRIDES_PATH = ROOT / "tools" / "sdkgen" / "overrides.json"
VECTORS_PATH = ROOT / "sdks" / "testdata" / "webhook-signature-vectors.json"


def load_json(path: Path | str) -> Any:
    return json.loads(Path(path).read_text(encoding="utf-8"))


def load_spec() -> dict[str, Any]:
    return load_json(SPEC_PATH)


def load_overrides() -> dict[str, Any]:
    return load_json(OVERRIDES_PATH)


def canonical(value: Any) -> bytes:
    """Forma canônica (chaves ordenadas, sem espaços): independe da formatação do arquivo."""
    return json.dumps(value, sort_keys=True, separators=(",", ":"), ensure_ascii=False).encode("utf-8")


def fingerprint(spec: dict[str, Any]) -> str:
    return hashlib.sha256(canonical(spec)).hexdigest()


def source_fingerprint(spec: dict[str, Any], overrides: dict[str, Any]) -> str:
    """Impressão digital da ORIGEM dos SDKs: especificação + overrides.json."""
    return hashlib.sha256(canonical({"overrides": overrides, "spec": spec})).hexdigest()


def normalize(spec: dict[str, Any], overrides: dict[str, Any]) -> dict[str, Any]:
    """O único dado da máquina que muda a exportação é o `servers` (APP_URL)."""
    result = copy.deepcopy(spec)
    server = overrides["server"]
    result["servers"] = [{"url": server["url"], "description": server["description"]}]
    return result


def dump(spec: dict[str, Any]) -> str:
    return json.dumps(spec, indent=4, sort_keys=True, ensure_ascii=False) + "\n"


def export(php: str = "php") -> dict[str, Any]:
    """`php artisan scramble:export` com os valores de configuração fixados em overrides.json."""
    overrides = load_overrides()
    env = dict(os.environ)
    for pin in overrides["exportPins"]:
        env[pin["env"]] = str(pin["value"])

    with tempfile.TemporaryDirectory() as tmp:
        target = Path(tmp) / "v1.json"
        subprocess.run(
            [php, "artisan", "scramble:export", f"--path={target}"],
            cwd=ROOT,
            env=env,
            check=True,
            stdout=subprocess.DEVNULL,
        )
        spec = load_json(target)

    return normalize(spec, overrides)
