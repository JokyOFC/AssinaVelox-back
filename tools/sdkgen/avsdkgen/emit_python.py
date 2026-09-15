"""Saída Python: dataclasses (respostas), TypedDicts (corpos), cliente e testes gerados."""

from __future__ import annotations

import json
import keyword
from pathlib import Path

from .common import GENERATED, UPLOAD_SAMPLE, body_sample, checked_fields, idempotency_note, path_sample, response_note, summary
from .model import Api, ObjDef, Operation, TypeRef, snake

PRIMS = {"string": "str", "integer": "int", "number": "float", "boolean": "bool"}
RESERVED = {"self", "body", "file", "timeout", "headers", "idempotency_key"}


def ident(name: str) -> str:
    return name + "_" if keyword.iskeyword(name) else name


def base(t: TypeRef, role: str, prefix: str) -> str:
    if t.kind == "prim":
        if role == "request" and t.enum and t.prim == "string":
            return "Literal[" + ", ".join(json.dumps(v, ensure_ascii=False) for v in t.enum) + "]"
        return PRIMS[t.prim or "string"]
    if t.kind == "named":
        return prefix + (t.name or "")
    if t.kind == "array":
        return f"list[{py_type(t.item, role, prefix)}]"
    if t.kind == "map":
        return f"dict[str, {py_type(t.item, role, prefix)}]"
    if t.kind == "union":
        return " | ".join(PRIMS[m] for m in t.members)
    return "Any"


def py_type(t: TypeRef | None, role: str, prefix: str = "") -> str:
    if t is None:
        return "Any"
    text = base(t, role, prefix)
    return f"{text} | None" if t.nullable and text != "Any" else text


def convert(t: TypeRef, expr: str) -> str:
    if t.kind == "named":
        return f"_obj({t.name}, {expr})"
    if t.kind == "array" and t.item is not None and t.item.kind == "named":
        return f"_list({t.item.name}, {expr})"
    if t.kind == "map" and t.item is not None and t.item.kind == "named":
        return f"_map({t.item.name}, {expr})"
    return expr


def known_values(t: TypeRef) -> str:
    if t.kind == "prim" and t.enum and len(t.enum) <= 10 and all(len(str(v)) <= 40 for v in t.enum):
        return "Valores conhecidos: " + ", ".join(json.dumps(v, ensure_ascii=False) for v in t.enum) + " (a lista pode crescer)."
    return ""


def emit_version(api: Api) -> str:
    return (
        f'"""{GENERATED}"""\n\n'
        f'SDK_VERSION = "{api.sdk_version}"\n'
        f'API_VERSION = "{api.api_version}"\n'
        f"API_MAJOR = {api.api_major}\n"
        f'SPEC_SHA256 = "{api.fingerprint}"\n'
    )


def emit_response(obj: ObjDef) -> list[str]:
    lines = ["", "", "@dataclasses.dataclass(frozen=True, kw_only=True)", f"class {obj.name}:"]
    doc = obj.description or f"{obj.name} (API v1)."
    lines.append(f'    """{doc.splitlines()[0]}"""')
    lines.append("")
    for f in obj.fields:
        note = " ".join(filter(None, [f.description, known_values(f.type)]))
        if note:
            lines.append(f"    #: {note}")
        annotation = py_type(f.type, "response")
        if not f.required and not annotation.endswith("| None") and annotation != "Any":
            annotation += " | None"
        default = "" if f.required else " = None"
        lines.append(f"    {ident(f.wire)}: {annotation}{default}")
    lines.append("    #: Resposta original, com campos que esta versão do SDK ainda não conhece.")
    lines.append("    raw: dict[str, Any] = dataclasses.field(default_factory=dict, repr=False, compare=False)")
    lines.append("")
    lines.append("    @classmethod")
    lines.append(f'    def from_dict(cls, data: Mapping[str, Any]) -> "{obj.name}":')
    lines.append("        return cls(")
    for f in obj.fields:
        lines.append(f"            {ident(f.wire)}={convert(f.type, f'data.get({json.dumps(f.wire)})')},")
    lines.append("            raw=dict(data),")
    lines.append("        )")
    return lines


def emit_request(obj: ObjDef) -> list[str]:
    lines = ["", "", f"class {obj.name}(TypedDict, total=False):"]
    required = [f.wire for f in obj.fields if f.required]
    doc = obj.description.splitlines()[0] if obj.description else f"Corpo {obj.name}."
    if required:
        doc += " Obrigatórios: " + ", ".join(required) + "."
    lines.append(f'    """{doc}"""')
    lines.append("")
    for f in obj.fields:
        if f.description:
            lines.append(f"    #: {f.description.splitlines()[0]}")
        lines.append(f"    {f.wire}: {py_type(f.type, 'request')}")
    return lines


def emit_models(api: Api) -> str:
    out = [
        f'"""Tipos da API v1. {GENERATED}',
        "",
        "Respostas: dataclasses imutáveis com `from_dict` (campos desconhecidos ficam em `raw`).",
        'Corpos de pedido: TypedDict (dicionários comuns; `total=False`, veja "Obrigatórios").',
        '"""',
        "",
        "from __future__ import annotations",
        "",
        "import dataclasses",
        "from typing import Any, Literal, Mapping, TypedDict",
        "",
        "",
        "def _obj(cls: Any, value: Any) -> Any:",
        "    return cls.from_dict(value) if isinstance(value, Mapping) else None",
        "",
        "",
        "def _list(cls: Any, value: Any) -> Any:",
        "    if not isinstance(value, list):",
        "        return None",
        "    return [cls.from_dict(item) for item in value if isinstance(item, Mapping)]",
        "",
        "",
        "def _map(cls: Any, value: Any) -> Any:",
        "    if not isinstance(value, Mapping):",
        "        return None",
        "    return {key: cls.from_dict(item) for key, item in value.items() if isinstance(item, Mapping)}",
    ]
    for obj in api.responses.values():
        out.extend(emit_response(obj))
    for obj in api.requests.values():
        out.extend(emit_request(obj))
    names = sorted(list(api.responses) + list(api.requests))
    out.extend(["", "", "__all__ = ["] + [f'    "{n}",' for n in names] + ["]"])
    return "\n".join(out) + "\n"


def return_type(op: Operation) -> str:
    r = op.response
    if r.kind == "empty":
        return "None"
    if r.kind == "binary":
        return "DownloadedFile"
    if r.kind == "raw":
        return "Any"
    item = py_type(r.type, "response", "models.")
    if r.kind == "page":
        return f"Page[{item}]"
    inner = f"list[{item}]" if r.kind == "list" else item
    return f"ApiResult[{inner}]" if r.has_meta else inner


def query_type(param) -> str:  # noqa: ANN001
    if param.repeat:
        item = py_type(param.type.item if param.type.item else None, "request")
        return f"Sequence[{item}] | {item} | None"
    text = py_type(param.type, "request")
    return text if text.endswith("| None") else f"{text} | None"


def emit_method(op: Operation) -> list[str]:
    for name in [snake(p.wire) for p in op.path_params] + [q.name for q in op.query_params]:
        if name in RESERVED:
            raise ValueError(f"{op.id}: parâmetro {name} colide com um nome do SDK Python")
    args = ["self"]
    args += [f"{ident(snake(p.wire))}: {py_type(p.type, 'request')}" for p in op.path_params]
    if op.body is not None and op.body.kind == "json":
        body_type = py_type(op.body.type, "request", "models.")
        args.append(f"body: {body_type}" if op.body.required else f"body: {body_type} | None = None")
    elif op.body is not None:
        args.append("file: FileUpload")
    args.append("*")
    args += [f"{ident(q.name)}: {query_type(q)} = None" for q in op.query_params]
    if op.idempotency:
        args.append("idempotency_key: str | None = None")
    args += ["timeout: float | None = None", "headers: Mapping[str, str] | None = None"]

    lines = ["", f"    def {op.snake}(", *[f"        {a}," for a in args], f"    ) -> {return_type(op)}:"]
    doc = [f"{summary(op)} — ``{op.http} {op.path}``."]
    doc += [n for n in (idempotency_note(op), response_note(op)) if n]
    if op.response.kind == "page":
        doc.append("Use ``.auto_paging_iter()`` para percorrer todas as páginas.")
    lines.append('        """' + "\n\n        ".join(doc) + '\n        """')

    call = ["        response = self._http.request(", f'            "{op.http}",', f'            "{op.path}",']
    if op.path_params:
        pairs = ", ".join(f'"{p.wire}": {ident(snake(p.wire))}' for p in op.path_params)
        call.append(f"            path_params={{{pairs}}},")
    if op.query_params:
        items = ", ".join(f'("{q.wire}", {ident(q.name)}, {q.repeat})' for q in op.query_params)
        call.append(f"            query=[{items}],")
    if op.body is not None and op.body.kind == "json":
        call.append("            json_body=body,")
        call.append("            has_body=True,")
    elif op.body is not None:
        call.append(f'            files={{"{op.body.file_fields[0]}": file}},')
    if op.idempotency:
        call.append(f'            idempotency="{op.idempotency}",')
        call.append("            idempotency_key=idempotency_key,")
    call += ["            timeout=timeout,", "            headers=headers,"]
    if op.response.kind == "binary":
        call.append('            accept="*/*",')
    call.append("        )")
    lines += call

    r = op.response
    named = r.type is not None and r.type.kind == "named"
    if r.kind == "empty":
        lines.append("        return None")
    elif r.kind == "binary":
        lines.append("        return DownloadedFile.from_response(response.headers, response.body)")
    elif r.kind == "raw":
        lines.append("        return response.json()")
    elif r.kind == "page":
        kwargs = ", ".join(f"{ident(q.name)}={ident(q.name)}" for q in op.query_params if q.name != "cursor")
        path_args = ", ".join(ident(snake(p.wire)) for p in op.path_params)
        call_args = ", ".join(filter(None, [path_args, kwargs, "cursor=cursor", "timeout=timeout", "headers=headers"]))
        converter = f"models.{r.type.name}.from_dict" if named and r.type else "_identity"
        lines += [
            "",
            f"        def _next(cursor: str) -> {return_type(op)}:",
            f"            return self.{op.snake}({call_args})",
            "",
            f"        return Page.from_json(response.json(), {converter}, _next)",
        ]
    else:
        if r.kind == "list":
            value = (
                f"[models.{r.type.name}.from_dict(item) for item in (data_of(response) or [])]"
                if named and r.type
                else "list(data_of(response) or [])"
            )
        else:
            value = f"models.{r.type.name}.from_dict(data_of(response) or {{}})" if named and r.type else "data_of(response)"
        if r.has_meta:
            lines.append(f"        return ApiResult(data={value}, meta=meta_of(response))")
        else:
            lines.append(f"        return {value}")
    return lines


def emit_client(api: Api) -> str:
    out = [
        f'"""Cliente da API v1. {GENERATED}"""',
        "",
        "from __future__ import annotations",
        "",
        "from typing import Any, Literal, Mapping, Sequence",
        "",
        "from . import models",
        "from ._http import HttpClient, data_of, meta_of",
        "from .files import DownloadedFile, FileUpload",
        "from .pagination import ApiResult, Page",
        "",
        "",
        "def _identity(item: Any) -> Any:",
        "    return item",
        "",
        "",
        "class AssinaVelox:",
        '    """Cliente da API v1 da AssinaVelox.',
        "",
        "    ``base_url`` é o endereço da sua instalação terminando em ``/api/v1``; ``token`` é o texto",
        "    da chave criada em Integrações → Chaves (exibido uma única vez). O token nunca aparece em",
        "    ``repr()`` nem em mensagens de erro. Redirecionamentos não são seguidos.",
        '    """',
        "",
        "    def __init__(",
        "        self,",
        "        *,",
        "        base_url: str,",
        "        token: str,",
        "        timeout: float = 30.0,",
        "        headers: Mapping[str, str] | None = None,",
        "    ) -> None:",
        "        self._http = HttpClient(base_url, token, timeout, headers)",
        "",
        "    def __repr__(self) -> str:",
        '        return f"AssinaVelox(base_url={self._http.base_url!r})"',
        "",
        "    @property",
        "    def base_url(self) -> str:",
        "        return self._http.base_url",
    ]
    for op in api.operations:
        out.extend(emit_method(op))
    return "\n".join(out) + "\n"


def emit_tests(api: Api) -> str:
    out = [
        f'"""Cada operação da especificação contra o servidor falso. {GENERATED}"""',
        "",
        "from __future__ import annotations",
        "",
        "import json",
        "import unittest",
        "",
        "from assinavelox import ApiResult, AssinaVelox, DownloadedFile, FileUpload, Page, models",
        "",
        "from tests._support import UUID_V4, fake_url, fetch_trace, new_trace",
        "",
        "",
        "class GeneratedOperationsTest(unittest.TestCase):",
        "    def setUp(self) -> None:",
        '        self.client = AssinaVelox(base_url=fake_url(), token="tok_teste_sdk")',
        "",
        "    def check_trace(self, trace: str, operation: str, idempotency: str | None) -> None:",
        "        records = fetch_trace(trace)",
        '        self.assertTrue(records, "o servidor falso não recebeu o pedido")',
        '        self.assertEqual(records[0]["operation"], operation)',
        '        self.assertEqual(records[0]["violations"], [])',
        '        key = records[0]["headers"].get("idempotency-key")',
        '        if idempotency == "required":',
        '            self.assertRegex(key or "", UUID_V4)',
        '        elif idempotency == "optional":',
        "            self.assertIsNone(key)",
    ]
    for op in api.operations:
        args = [json.dumps(path_sample(p.wire, p.schema)) for p in op.path_params]
        if op.body is not None and op.body.kind == "json":
            args.append(f"json.loads({json.dumps(json.dumps(body_sample(op, api), ensure_ascii=False), ensure_ascii=False)})")
        elif op.body is not None:
            args.append(f'FileUpload(content={UPLOAD_SAMPLE!r}, filename="contrato.pdf", content_type="application/pdf")')
        args.append("headers=headers")
        out += [
            "",
            f"    def test_{op.snake}(self) -> None:",
            "        headers, trace = new_trace()",
            f"        result = self.client.{op.snake}({', '.join(args)})",
        ]
        out += [f"        {line}" for line in assertions(api, op)]
        idem = f'"{op.idempotency}"' if op.idempotency else "None"
        out.append(f'        self.check_trace(trace, "{op.id}", {idem})')
    out += ["", "", 'if __name__ == "__main__":', "    unittest.main()"]
    return "\n".join(out) + "\n"


def named_checks(api: Api, name: str, target: str) -> list[str]:
    obj = api.responses.get(name)
    lines = [f"self.assertIsInstance({target}, models.{name})"]
    if obj is not None:
        lines += [f"self.assertIsNotNone({target}.{ident(f.wire)})" for f in checked_fields(obj)]
        lines.append(f"self.assertTrue({target}.raw)")
    return lines


def assertions(api: Api, op: Operation) -> list[str]:
    r = op.response
    named = r.type.name if r.type is not None and r.type.kind == "named" else None
    if r.kind == "empty":
        return ["self.assertIsNone(result)"]
    if r.kind == "binary":
        return [
            "self.assertIsInstance(result, DownloadedFile)",
            'self.assertTrue(result.content.startswith(b"%PDF"))',
            'self.assertEqual(result.content_type, "application/pdf")',
            'self.assertEqual(result.filename, "AV-000123-original.pdf")',
        ]
    if r.kind == "raw":
        return ["self.assertIsNotNone(result)"]
    if r.kind == "page":
        lines = [
            "self.assertIsInstance(result, Page)",
            "self.assertEqual(len(result.data), 2)",
            'self.assertEqual(result.next_cursor, "fake-cursor-2")',
        ]
        if named:
            lines += named_checks(api, named, "result.data[0]")
        lines += [
            "following = result.next_page()",
            "assert following is not None",
            "self.assertEqual(len(following.data), 1)",
            "self.assertFalse(following.has_more)",
            "self.assertIsNone(following.next_page())",
            "self.assertEqual(len(list(result.auto_paging_iter())), 3)",
        ]
        return lines
    lines: list[str] = []
    target = "result"
    if r.has_meta:
        lines += ["self.assertIsInstance(result, ApiResult)", "self.assertIsInstance(result.meta, dict)"]
        target = "result.data"
    if r.kind == "list":
        lines += [f"self.assertIsInstance({target}, list)", f"self.assertGreaterEqual(len({target}), 1)"]
        if named:
            lines += named_checks(api, named, f"{target}[0]")
    elif named:
        lines += named_checks(api, named, target)
    else:
        lines.append(f"self.assertIsInstance({target}, dict)")
    return lines


def emit(api: Api, root: Path) -> dict[Path, str]:
    return {
        root / "assinavelox" / "_version.py": emit_version(api),
        root / "assinavelox" / "models.py": emit_models(api),
        root / "assinavelox" / "_client.py": emit_client(api),
        root / "tests" / "test_operations_generated.py": emit_tests(api),
    }
