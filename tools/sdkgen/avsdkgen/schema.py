"""Subconjunto de JSON Schema usado pela especificação: validação e exemplos determinísticos.

Serve ao servidor falso (confere o pedido do SDK e monta a resposta) e aos testes gerados
(corpo mínimo válido de cada operação).
"""

from __future__ import annotations

import json
import re
from typing import Any

ULID_SAMPLE = "01J00000000000000000000000"
DATE_SAMPLE = "2026-01-15T13:00:00Z"

_ISO = re.compile(r"^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})?)?$")

_TYPE_CHECKS = {
    "null": lambda v: v is None,
    "boolean": lambda v: isinstance(v, bool),
    "integer": lambda v: isinstance(v, int) and not isinstance(v, bool),
    "number": lambda v: isinstance(v, (int, float)) and not isinstance(v, bool),
    "string": lambda v: isinstance(v, str),
    "array": lambda v: isinstance(v, list),
    "object": lambda v: isinstance(v, dict),
}


def pointer(root: dict[str, Any], ref: str) -> Any:
    if not ref.startswith("#/"):
        raise ValueError(f"referência não suportada: {ref}")
    node: Any = root
    for part in ref[2:].split("/"):
        node = node[part.replace("~1", "/").replace("~0", "~")]
    return node


def resolve(schema: Any, root: dict[str, Any]) -> Any:
    guard = 0
    while isinstance(schema, dict) and "$ref" in schema:
        schema = pointer(root, schema["$ref"])
        guard += 1
        if guard > 32:
            raise ValueError("referência circular")
    return schema


def types_of(schema: dict[str, Any]) -> list[str]:
    value = schema.get("type")
    if value is None:
        return []
    return list(value) if isinstance(value, list) else [value]


def json_type(value: Any) -> str:
    for name in ("null", "boolean", "integer", "number", "string", "array", "object"):
        if _TYPE_CHECKS[name](value):
            return name
    return type(value).__name__


def validate(value: Any, schema: Any, root: dict[str, Any], path: str = "$", strict: bool = False) -> list[str]:
    """Lista de problemas (vazia = válido). `strict` recusa propriedades não descritas."""
    errors: list[str] = []
    _validate(value, schema, root, path, strict, errors)
    return errors


def _validate(value: Any, schema: Any, root: dict[str, Any], path: str, strict: bool, errors: list[str]) -> None:
    if not isinstance(schema, dict) or not schema:
        return
    if "$ref" in schema:
        _validate(value, pointer(root, schema["$ref"]), root, path, strict, errors)
        return

    variants = schema.get("anyOf") or schema.get("oneOf")
    if variants:
        collected: list[list[str]] = []
        for variant in variants:
            sub: list[str] = []
            _validate(value, variant, root, path, strict, sub)
            if not sub:
                return
            collected.append(sub)
        best = min(collected, key=len)
        errors.append(f"{path}: não corresponde a nenhuma variante ({'; '.join(best[:3])})")
        return

    if "const" in schema and value != schema["const"]:
        errors.append(f"{path}: esperado {json.dumps(schema['const'], ensure_ascii=False)}")

    declared = types_of(schema)
    if declared and not any(_TYPE_CHECKS[t](value) for t in declared if t in _TYPE_CHECKS):
        errors.append(f"{path}: tipo {json_type(value)}, esperado {'|'.join(declared)}")
        return

    if "enum" in schema and value not in schema["enum"]:
        errors.append(f"{path}: valor fora da lista {schema['enum']}")

    if isinstance(value, str):
        if "minLength" in schema and len(value) < schema["minLength"]:
            errors.append(f"{path}: menos de {schema['minLength']} caracteres")
        if "maxLength" in schema and len(value) > schema["maxLength"]:
            errors.append(f"{path}: mais de {schema['maxLength']} caracteres")
        if "pattern" in schema and re.search(schema["pattern"], value) is None:
            errors.append(f"{path}: não segue o padrão {schema['pattern']}")
        if schema.get("format") == "email" and re.fullmatch(r"[^@\s]+@[^@\s]+", value) is None:
            errors.append(f"{path}: e-mail inválido")
        if schema.get("format") == "date-time" and _ISO.match(value) is None:
            errors.append(f"{path}: data inválida")

    if _TYPE_CHECKS["number"](value):
        if "minimum" in schema and value < schema["minimum"]:
            errors.append(f"{path}: menor que {schema['minimum']}")
        if "maximum" in schema and value > schema["maximum"]:
            errors.append(f"{path}: maior que {schema['maximum']}")

    if isinstance(value, list):
        if "minItems" in schema and len(value) < schema["minItems"]:
            errors.append(f"{path}: menos de {schema['minItems']} itens")
        if "maxItems" in schema and len(value) > schema["maxItems"]:
            errors.append(f"{path}: mais de {schema['maxItems']} itens")
        if schema.get("uniqueItems"):
            seen = [json.dumps(item, sort_keys=True) for item in value]
            if len(seen) != len(set(seen)):
                errors.append(f"{path}: itens repetidos")
        items = schema.get("items")
        if isinstance(items, dict):
            for index, item in enumerate(value):
                _validate(item, items, root, f"{path}[{index}]", strict, errors)

    if isinstance(value, dict):
        properties = schema.get("properties") or {}
        for name in schema.get("required") or []:
            if name not in value:
                errors.append(f"{path}.{name}: obrigatório")
        extra = schema.get("additionalProperties")
        for name, item in value.items():
            if name in properties:
                _validate(item, properties[name], root, f"{path}.{name}", strict, errors)
            elif isinstance(extra, dict):
                _validate(item, extra, root, f"{path}.{name}", strict, errors)
            elif extra is False or (strict and extra is None and properties):
                errors.append(f"{path}.{name}: propriedade desconhecida")


def _string_sample(schema: dict[str, Any], name: str) -> str:
    fmt = schema.get("format")
    if fmt == "date-time" or name.endswith(("_at", "_after", "_before")):
        return DATE_SAMPLE
    if fmt == "email" or name == "email":
        return "ana@example.com"
    if "url" in name:
        return "https://integracao.example/webhooks/assinavelox"
    minimum = int(schema.get("minLength", 0))
    maximum = schema.get("maxLength")
    if (minimum == 26 and maximum == 26) or name == "id" or name.endswith("_id"):
        return ULID_SAMPLE
    if schema.get("pattern") == "^\\d*$":
        return "1234"[: maximum or 4]
    text = f"exemplo de {name}"
    if len(text) < minimum:
        text = text + "x" * (minimum - len(text))
    if maximum is not None:
        text = text[: int(maximum)]
    return text


def sample(schema: Any, root: dict[str, Any], name: str = "valor", minimal: bool = False, depth: int = 0) -> Any:
    """Valor determinístico que valida contra o esquema. `minimal`: só propriedades obrigatórias."""
    if depth > 16 or not isinstance(schema, dict) or not schema:
        return "valor"
    if "$ref" in schema:
        return sample(pointer(root, schema["$ref"]), root, name, minimal, depth + 1)

    variants = schema.get("anyOf") or schema.get("oneOf")
    if variants:
        options = [resolve(v, root) for v in variants]
        options = [v for v in options if types_of(v) != ["null"]]
        if not options:
            return None
        best = max(options, key=lambda v: len(v.get("properties") or {}))
        return sample(best, root, name, minimal, depth + 1)

    if "const" in schema:
        return schema["const"]
    enum = [v for v in schema.get("enum") or [] if v is not None]
    if enum:
        return enum[0]

    declared = [t for t in types_of(schema) if t != "null"]
    if not declared:
        if "properties" in schema:
            declared = ["object"]
        elif types_of(schema) == ["null"]:
            return None
        else:
            return "valor"
    kind = declared[0]

    if kind == "string":
        return _string_sample(schema, name)
    if kind == "integer":
        value = max(1, int(schema.get("minimum", 1)))
        if "maximum" in schema:
            value = min(value, int(schema["maximum"]))
        return value
    if kind == "number":
        value = 0.5
        if "minimum" in schema and schema["minimum"] > value:
            value = schema["minimum"]
        if "maximum" in schema and schema["maximum"] < value:
            value = schema["maximum"]
        return value
    if kind == "boolean":
        return True
    if kind == "array":
        if schema.get("prefixItems"):
            return [sample(item, root, name, minimal, depth + 1) for item in schema["prefixItems"]]
        items = schema.get("items")
        count = max(int(schema.get("minItems", 0)), 1)
        singular = name[:-1] if name.endswith("s") else name
        return [sample(items, root, singular, minimal, depth + 1) if items else "valor" for _ in range(count)]
    if kind == "object":
        properties = schema.get("properties") or {}
        required = set(schema.get("required") or [])
        if properties:
            return {
                key: sample(sub, root, key, minimal, depth + 1)
                for key, sub in properties.items()
                if not minimal or key in required
            }
        extra = schema.get("additionalProperties")
        if minimal:
            return {}
        if isinstance(extra, dict) and extra:
            return {"chave": sample(extra, root, "chave", minimal, depth + 1)}
        return {"chave": "valor"}
    return "valor"
