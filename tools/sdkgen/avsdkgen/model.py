"""Representação intermediária (operações e tipos) montada da especificação + overrides.json.

As três saídas (PHP, TypeScript, Python) e o servidor falso leem só isto, para que os SDKs e
o servidor concordem sobre cada operação.
"""

from __future__ import annotations

import re
from dataclasses import dataclass, field
from typing import Any

from .schema import pointer, resolve, types_of

PRIMITIVES = ("string", "integer", "number", "boolean")


@dataclass
class TypeRef:
    kind: str  # prim | named | array | map | union | any
    prim: str | None = None
    name: str | None = None
    item: "TypeRef | None" = None
    members: tuple[str, ...] = ()
    nullable: bool = False
    enum: tuple[Any, ...] = ()


ANY = TypeRef("any", nullable=True)


@dataclass
class FieldDef:
    wire: str
    type: TypeRef
    required: bool
    description: str = ""


@dataclass
class ObjDef:
    name: str
    role: str  # response | request
    description: str
    fields: list[FieldDef] = field(default_factory=list)


@dataclass
class Param:
    wire: str
    name: str
    type: TypeRef
    required: bool
    description: str = ""
    repeat: bool = False
    schema: dict[str, Any] = field(default_factory=dict)


@dataclass
class Body:
    kind: str  # json | multipart
    type: TypeRef
    required: bool
    schema: dict[str, Any]
    file_fields: list[str] = field(default_factory=list)


@dataclass
class ResponseSpec:
    kind: str  # empty | binary | data | list | page | raw
    type: TypeRef | None
    has_meta: bool
    statuses: list[int]
    schema: dict[str, Any] | None


@dataclass
class Operation:
    id: str
    method: str
    http: str
    path: str
    summary: str
    description: str
    path_params: list[Param]
    query_params: list[Param]
    body: Body | None
    idempotency: str | None
    response: ResponseSpec

    @property
    def snake(self) -> str:
        return snake(self.method)

    @property
    def pascal(self) -> str:
        return self.method[0].upper() + self.method[1:]


@dataclass
class Api:
    title: str
    api_version: str
    api_major: int
    sdk_version: str
    fingerprint: str
    operations: list[Operation]
    responses: dict[str, ObjDef]
    requests: dict[str, ObjDef]
    root: dict[str, Any]


def snake(name: str) -> str:
    return re.sub(r"(?<!^)([A-Z])", r"_\1", name).lower()


def pascal(name: str) -> str:
    return "".join(part[:1].upper() + part[1:] for part in re.split(r"[^A-Za-z0-9]+", name) if part)


def camel(name: str) -> str:
    value = pascal(name)
    return value[:1].lower() + value[1:]


def singular(name: str) -> str:
    if name.endswith("ies"):
        return name[:-3] + "y"
    if name.endswith("s") and not name.endswith("ss"):
        return name[:-1]
    return name


class Builder:
    def __init__(self, spec: dict[str, Any], overrides: dict[str, Any]):
        self.spec = spec
        self.overrides = overrides
        self.root: dict[str, Any] = dict(spec)
        self.root["x-sdk"] = {"schemas": overrides.get("schemas", {})}
        self.types: dict[str, dict[str, ObjDef]] = {"response": {}, "request": {}}

    # ---- tipos -------------------------------------------------------------------------

    def ref_name(self, ref: str, role: str) -> str:
        base = ref.rsplit("/", 1)[-1]
        if role == "response" and base.endswith("Resource") and len(base) > len("Resource"):
            base = base[: -len("Resource")]
        return base

    def is_object(self, schema: dict[str, Any]) -> bool:
        return "object" in types_of(schema) or ("properties" in schema and not types_of(schema))

    def merge_objects(self, objects: list[dict[str, Any]]) -> dict[str, Any]:
        """anyOf de objetos (ex.: detalhe × listagem) vira um objeto; obrigatório = interseção."""
        properties: dict[str, Any] = {}
        required: set[str] | None = None
        description = ""
        for obj in objects:
            for key, value in (obj.get("properties") or {}).items():
                properties.setdefault(key, value)
            req = set(obj.get("required") or [])
            required = req if required is None else required & req
            description = description or obj.get("description", "")
        return {
            "type": "object",
            "properties": dict(sorted(properties.items())),
            "required": sorted(required or set()),
            "description": description,
        }

    def to_type(self, schema: Any, ctx: str, role: str) -> TypeRef:
        if not isinstance(schema, dict) or not schema:
            return TypeRef("any", nullable=True)
        if "$ref" in schema:
            name = self.ref_name(schema["$ref"], role)
            return self.to_type(resolve(schema, self.root), name, role)

        variants = schema.get("anyOf") or schema.get("oneOf")
        if variants:
            resolved = [resolve(v, self.root) for v in variants]
            nullable = any(types_of(v) == ["null"] for v in resolved)
            rest = [v for v in resolved if types_of(v) != ["null"]]
            if rest and all(self.is_object(v) and v.get("properties") for v in rest):
                merged = self.to_type(self.merge_objects(rest), ctx, role)
                merged.nullable = merged.nullable or nullable
                return merged
            prims = [t for v in rest for t in types_of(v)]
            if rest and all(t in PRIMITIVES + ("null",) for t in prims) and not any(v.get("properties") for v in rest):
                members = tuple(dict.fromkeys(t for t in prims if t != "null"))
                return TypeRef("union", members=members, nullable=nullable or "null" in prims)
            return TypeRef("any", nullable=True)

        declared = types_of(schema)
        nullable = "null" in declared
        kinds = [t for t in declared if t != "null"]
        enum = tuple(v for v in schema.get("enum") or [] if v is not None)

        if not kinds:
            if schema.get("properties"):
                kinds = ["object"]
            elif "const" in schema:
                kinds = [
                    {bool: "boolean", int: "integer", float: "number", str: "string"}.get(type(schema["const"]), "string")
                ]
            else:
                return TypeRef("any", nullable=True)

        if len(kinds) > 1:
            if all(t in PRIMITIVES for t in kinds):
                return TypeRef("union", members=tuple(kinds), nullable=nullable)
            return TypeRef("any", nullable=True)

        kind = kinds[0]
        if kind in PRIMITIVES:
            return TypeRef("prim", prim=kind, nullable=nullable, enum=enum)
        if kind == "array":
            items = schema.get("items")
            item = self.to_type(items, ctx, role) if isinstance(items, dict) and items else TypeRef("any", nullable=True)
            return TypeRef("array", item=item, nullable=nullable)
        if kind == "object":
            if schema.get("properties"):
                self.register(ctx, schema, role)
                return TypeRef("named", name=ctx, nullable=nullable)
            extra = schema.get("additionalProperties")
            item = self.to_type(extra, ctx, role) if isinstance(extra, dict) and extra else TypeRef("any", nullable=True)
            return TypeRef("map", item=item, nullable=nullable)
        return TypeRef("any", nullable=True)

    def register(self, name: str, schema: dict[str, Any], role: str) -> None:
        registry = self.types[role]
        if name in registry:
            return
        obj = ObjDef(name=name, role=role, description=(schema.get("description") or "").strip())
        registry[name] = obj
        required = set(schema.get("required") or [])
        for key, sub in (schema.get("properties") or {}).items():
            resolved = resolve(sub, self.root)
            container = isinstance(resolved, dict) and (
                "array" in types_of(resolved)
                or ("object" in types_of(resolved) and not resolved.get("properties") and resolved.get("additionalProperties"))
            )
            child = name + pascal(singular(key) if container else key)
            ref_type = self.to_type(sub, child, role)
            description = ""
            if isinstance(sub, dict):
                description = (sub.get("description") or (resolved or {}).get("description") or "").strip()
            obj.fields.append(FieldDef(key, ref_type, key in required, description))

    # ---- operações ---------------------------------------------------------------------

    def response(self, op_id: str, op: dict[str, Any], ov: dict[str, Any], method: str) -> ResponseSpec:
        if ov.get("binary"):
            return ResponseSpec("binary", None, False, [200], None)

        if "response" in ov:
            statuses = [int(s) for s in ov["response"]["statuses"]]
            schema: Any = ov["response"]["schema"]
        else:
            codes = sorted(code for code in op.get("responses", {}) if code.startswith("2"))
            if not codes:
                raise ValueError(f"{op_id}: sem resposta de sucesso")
            statuses = [int(c) for c in codes]
            first = resolve(op["responses"][codes[0]], self.root)
            content = (first or {}).get("content") or {}
            schema = (content.get("application/json") or {}).get("schema")
            if schema is None:
                return ResponseSpec("empty", None, False, statuses, None)

        resolved = resolve(schema, self.root)
        candidate = self.data_variant(resolved)
        if candidate is None:
            return ResponseSpec("raw", TypeRef("any", nullable=True), False, statuses, resolved)

        properties = candidate["properties"]
        meta = resolve(properties.get("meta"), self.root) if properties.get("meta") else None
        data = properties["data"]
        base = pascal(method)

        if meta and "next_cursor" in (meta.get("properties") or {}):
            items = resolve(data, self.root).get("items")
            item = self.to_type(items, base + "Item", "response")
            return ResponseSpec("page", item, True, statuses, candidate)

        data_type = self.to_type(data, base + "Data", "response")
        if data_type.kind == "array":
            return ResponseSpec("list", data_type.item, meta is not None, statuses, candidate)
        return ResponseSpec("data", data_type, meta is not None, statuses, candidate)

    def data_variant(self, schema: Any) -> dict[str, Any] | None:
        if not isinstance(schema, dict):
            return None
        if "data" in (schema.get("properties") or {}):
            return schema
        for variant in schema.get("anyOf") or schema.get("oneOf") or []:
            found = self.data_variant(resolve(variant, self.root))
            if found is not None:
                return found
        return None

    def params(self, op: dict[str, Any], ov: dict[str, Any]) -> tuple[list[Param], list[Param]]:
        path_params: list[Param] = []
        query_params: list[Param] = []
        path_enums = ov.get("pathEnums", {})
        raw = list(op.get("parameters") or []) + [dict(p, **{"in": "query"}) for p in ov.get("extraQuery", [])]
        for parameter in raw:
            parameter = resolve(parameter, self.root)
            schema = dict(parameter.get("schema") or {})
            wire = parameter["name"]
            if parameter["in"] == "path":
                if wire in path_enums:
                    schema["enum"] = path_enums[wire]
                ref = self.to_type(schema, "Param", "request")
                path_params.append(
                    Param(wire, camel(wire), ref, True, (parameter.get("description") or "").strip(), False, schema)
                )
            elif parameter["in"] == "query":
                repeat = wire.endswith("[]")
                name = wire[:-2] if repeat else wire
                if "array" in types_of(schema) and "enum" in schema and "items" in schema:
                    # Buraco do Scramble: repete o `enum` dos itens no próprio array (docs/fase-3/sdks.md §4).
                    del schema["enum"]
                ref = self.to_type(schema, "Param", "request")
                query_params.append(
                    Param(
                        wire,
                        name,
                        ref,
                        bool(parameter.get("required")),
                        (parameter.get("description") or "").strip(),
                        repeat,
                        schema,
                    )
                )
        return path_params, query_params

    def body(self, op_id: str, op: dict[str, Any], method: str) -> Body | None:
        request_body = op.get("requestBody")
        if not request_body:
            return None
        content = request_body.get("content") or {}
        required = bool(request_body.get("required"))
        if "application/json" in content:
            schema = content["application/json"]["schema"]
            name = self.ref_name(schema["$ref"], "request") if "$ref" in schema else pascal(method) + "Request"
            return Body("json", self.to_type(schema, name, "request"), required, schema)
        if "multipart/form-data" in content:
            schema = content["multipart/form-data"]["schema"]
            resolved = resolve(schema, self.root)
            files = [k for k, v in (resolved.get("properties") or {}).items() if v.get("format") == "binary"]
            others = [k for k in (resolved.get("properties") or {}) if k not in files]
            if len(files) != 1 or others:
                raise ValueError(f"{op_id}: multipart com campos além de um arquivo ainda não é suportado")
            return Body("multipart", TypeRef("any"), required, schema, files)
        raise ValueError(f"{op_id}: tipo de corpo não suportado: {list(content)}")

    def build(self) -> list[Operation]:
        ops_ov = self.overrides.get("operations", {})
        operations: list[Operation] = []
        for path, item in sorted(self.spec["paths"].items()):
            for http, op in item.items():
                if http not in ("get", "post", "put", "patch", "delete"):
                    continue
                op_id = op["operationId"]
                ov = ops_ov.get(op_id)
                if ov is None:
                    raise ValueError(
                        f"Operação nova sem nome no SDK: {op_id}. Acrescente-a em tools/sdkgen/overrides.json (operations)."
                    )
                method = ov["method"]
                path_params, query_params = self.params(op, ov)
                operations.append(
                    Operation(
                        id=op_id,
                        method=method,
                        http=http.upper(),
                        path=path,
                        summary=(op.get("summary") or "").strip().replace("\n", " "),
                        description=(op.get("description") or "").strip(),
                        path_params=path_params,
                        query_params=query_params,
                        body=self.body(op_id, op, method),
                        idempotency=ov.get("idempotency"),
                        response=self.response(op_id, op, ov, method),
                    )
                )
        missing = sorted(set(ops_ov) - {o.id for o in operations})
        if missing:
            raise ValueError(f"overrides.json cita operações que não existem mais: {missing}")
        order = list(ops_ov)
        operations.sort(key=lambda o: order.index(o.id))
        return operations


def build(spec: dict[str, Any], overrides: dict[str, Any], fingerprint: str) -> Api:
    builder = Builder(spec, overrides)
    operations = builder.build()
    api_version = str(spec.get("info", {}).get("version", "1.0.0"))
    sdk = overrides["sdk"]
    api_major = int(sdk["apiMajor"])
    if int(str(sdk["version"]).split(".")[0]) != api_major:
        raise ValueError("A versão maior do SDK precisa ser igual à da API (v1 → 1.x.y).")
    if int(api_version.split(".")[0]) != api_major:
        raise ValueError(f"info.version da especificação ({api_version}) não é da API v{api_major}.")
    return Api(
        title=str(spec.get("info", {}).get("title", "")),
        api_version=api_version,
        api_major=api_major,
        sdk_version=str(sdk["version"]),
        fingerprint=fingerprint,
        operations=operations,
        responses=dict(sorted(builder.types["response"].items())),
        requests=dict(sorted(builder.types["request"].items())),
        root=builder.root,
    )


__all__ = [
    "ANY",
    "Api",
    "Body",
    "FieldDef",
    "ObjDef",
    "Operation",
    "Param",
    "ResponseSpec",
    "TypeRef",
    "build",
    "camel",
    "pascal",
    "pointer",
    "snake",
]
