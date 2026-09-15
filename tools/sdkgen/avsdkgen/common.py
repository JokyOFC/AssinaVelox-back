"""Partes comuns às três saídas: textos, exemplos de argumentos e checagens."""

from __future__ import annotations

from typing import Any

from .model import Api, FieldDef, ObjDef, Operation
from .schema import ULID_SAMPLE, sample

GENERATED = "Gerado por tools/sdkgen a partir de sdks/openapi/v1.json — não edite; rode python tools/sdkgen/sdkgen.py generate."

UPLOAD_SAMPLE = b"%PDF-1.4\r\n% teste do SDK\x00\xff\r\n%%EOF\n"


def path_sample(wire: str, schema: dict[str, Any]) -> str:
    if schema.get("enum"):
        return str(schema["enum"][0])
    if wire == "event":
        return "envelope.sent"
    return ULID_SAMPLE


def body_sample(op: Operation, api: Api) -> Any:
    if op.body is None or op.body.kind != "json":
        return None
    return sample(op.body.schema, api.root, "corpo", minimal=True)


def checked_fields(obj: ObjDef) -> list[FieldDef]:
    """Campos obrigatórios, não nulos e escalares: os testes gerados conferem que foram lidos."""
    return [f for f in obj.fields if f.required and not f.type.nullable and f.type.kind == "prim"]


def summary(op: Operation) -> str:
    text = (op.summary or op.id).replace("*/", "*\\/").strip()
    return text[:1].upper() + text[1:]


def idempotency_note(op: Operation) -> str:
    if op.idempotency == "required":
        return "Idempotency-Key obrigatória: o SDK gera um UUID v4 se você não passar uma chave."
    if op.idempotency == "optional":
        return "Aceita Idempotency-Key (opcional)."
    return ""


def response_note(op: Operation) -> str:
    kind = op.response.kind
    if kind == "page":
        return "Paginada por cursor."
    if kind == "binary":
        return "Devolve os bytes do arquivo."
    return ""
