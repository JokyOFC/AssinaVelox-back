"""Saída TypeScript (ESM, fetch nativo, Node 18+): tipos, cliente e testes gerados (node --test)."""

from __future__ import annotations

import json
import re
from pathlib import Path

from .common import GENERATED, UPLOAD_SAMPLE, body_sample, checked_fields, idempotency_note, path_sample, response_note, summary
from .model import Api, ObjDef, Operation, Param, TypeRef

PRIMS = {"string": "string", "integer": "number", "number": "number", "boolean": "boolean"}
JS_TYPEOF = {"string": "string", "integer": "number", "number": "number", "boolean": "boolean"}


def ts_str(value: str) -> str:
    return "'" + value.replace("\\", "\\\\").replace("'", "\\'").replace("\n", "\\n") + "'"


def key(name: str) -> str:
    return name if re.fullmatch(r"[A-Za-z_$][A-Za-z0-9_$]*", name) else ts_str(name)


def ts_type(t: TypeRef | None, role: str, prefix: str = "") -> str:
    if t is None:
        return "unknown"
    if t.kind == "prim":
        if role == "request" and t.enum and t.prim == "string":
            base = " | ".join(ts_str(str(v)) for v in t.enum)
        else:
            base = PRIMS[t.prim or "string"]
    elif t.kind == "named":
        base = prefix + (t.name or "unknown")
    elif t.kind == "array":
        inner = ts_type(t.item, role, prefix)
        base = f"({inner})[]" if " " in inner else f"{inner}[]"
    elif t.kind == "map":
        base = f"Record<string, {ts_type(t.item, role, prefix)}>"
    elif t.kind == "union":
        base = " | ".join(dict.fromkeys(PRIMS[m] for m in t.members))
    else:
        return "unknown"
    return f"{base} | null" if t.nullable else base


def jsdoc(text: str, indent: str = "") -> list[str]:
    text = text.replace("*/", "*\\/").strip()
    if not text:
        return []
    lines = text.splitlines()
    if len(lines) == 1:
        return [f"{indent}/** {lines[0]} */"]
    return [f"{indent}/**", *[f"{indent} * {line}".rstrip() for line in lines], f"{indent} */"]


def emit_version(api: Api) -> str:
    return (
        f"// {GENERATED}\n\n"
        f"export const SDK_VERSION = '{api.sdk_version}';\n"
        f"export const API_VERSION = '{api.api_version}';\n"
        f"export const API_MAJOR = {api.api_major};\n"
        "/** Impressão digital (SHA-256) da especificação de onde este SDK foi gerado. */\n"
        f"export const SPEC_SHA256 = '{api.fingerprint}';\n"
    )


def known_values(t: TypeRef) -> str:
    if t.kind == "prim" and t.enum and len(t.enum) <= 10 and all(len(str(v)) <= 40 for v in t.enum):
        return "Valores conhecidos: " + ", ".join(f"`{v}`" for v in t.enum) + " (a lista pode crescer)."
    return ""


def emit_object(obj: ObjDef) -> list[str]:
    lines = [""]
    description = obj.description or f"{obj.name} (API v1)."
    if obj.role == "request":
        required = [f.wire for f in obj.fields if f.required]
        if required:
            description = description.splitlines()[0] + "\n\nObrigatórios: " + ", ".join(required) + "."
    lines += jsdoc(description.splitlines()[0] if obj.role == "response" else description)
    lines.append(f"export interface {obj.name} {{")
    for f in obj.fields:
        note = " ".join(filter(None, [f.description.splitlines()[0] if f.description else "", known_values(f.type) if obj.role == "response" else ""]))
        lines += jsdoc(note, "    ")
        optional = "" if f.required else "?"
        lines.append(f"    {key(f.wire)}{optional}: {ts_type(f.type, obj.role)};")
    lines.append("}")
    return lines


def query_interface(op: Operation) -> list[str]:
    lines = ["", f"/** Parâmetros de consulta de `{op.method}`. */", f"export interface {op.pascal}Query {{"]
    for q in op.query_params:
        lines += jsdoc(q.description, "    ")
        if q.repeat:
            item = ts_type(q.type.item, "request") if q.type.item else "string"
            text = f"({item})[]" if " " in item else f"{item}[]"
            lines.append(f"    {key(q.name)}?: {text} | null;")
        else:
            text = ts_type(q.type, "request")
            lines.append(f"    {key(q.name)}?: {text if text.endswith('| null') else text + ' | null'};")
    lines.append("}")
    return lines


def emit_types(api: Api) -> str:
    lines = [f"// {GENERATED}", "//", "// Respostas e corpos de pedido da API v1. Campos novos podem aparecer (a v1 só", "// cresce de forma compatível): ignore o que não conhecer."]
    for obj in api.responses.values():
        lines += emit_object(obj)
    for obj in api.requests.values():
        lines += emit_object(obj)
    for op in api.operations:
        if op.query_params:
            lines += query_interface(op)
    return "\n".join(lines) + "\n"


def return_type(op: Operation) -> str:
    r = op.response
    if r.kind == "empty":
        return "void"
    if r.kind == "binary":
        return "DownloadedFile"
    if r.kind == "raw":
        return "unknown"
    item = ts_type(r.type, "response", "T.")
    if r.kind == "page":
        return f"Page<{item}>"
    inner = (f"({item})[]" if " " in item else f"{item}[]") if r.kind == "list" else item
    return f"ApiResult<{inner}>" if r.has_meta else inner


def path_param_type(p: Param) -> str:
    if p.schema.get("enum"):
        return " | ".join(ts_str(str(v)) for v in p.schema["enum"])
    return "string"


def emit_method(op: Operation) -> list[str]:
    args = [f"{p.name}: {path_param_type(p)}" for p in op.path_params]
    if op.body is not None and op.body.kind == "json":
        body_type = ts_type(op.body.type, "request", "T.")
        args.append(f"body: {body_type}" if op.body.required else f"body?: {body_type}")
    elif op.body is not None:
        args.append("file: FileUpload")
    if op.query_params:
        args.append(f"query: T.{op.pascal}Query = {{}}")
    args.append("options: RequestOptions = {}")

    notes = [n for n in (idempotency_note(op), response_note(op)) if n]
    if op.response.kind == "page":
        notes.append("`for await` percorre todas as páginas.")
    doc = f"{summary(op)} — `{op.http} {op.path}`." + ("\n\n" + " ".join(notes) if notes else "")
    lines = [""]
    lines += jsdoc(doc, "    ")
    lines.append(f"    async {op.method}({', '.join(args)}): Promise<{return_type(op)}> {{")

    spec = [f"method: '{op.http}'", f"path: '{op.path}'"]
    if op.path_params:
        spec.append("pathParams: { " + ", ".join(f"{key(p.wire)}: {p.name}" for p in op.path_params) + " }")
    if op.query_params:
        spec.append("query")
        spec.append(
            "querySpec: { "
            + ", ".join(f"{key(q.name)}: [{ts_str(q.wire)}, {'true' if q.repeat else 'false'}] as const" for q in op.query_params)
            + " }"
        )
    if op.body is not None and op.body.kind == "json":
        spec += ["json: body", "hasBody: true"]
    elif op.body is not None:
        spec += ["file", f"fileField: '{op.body.file_fields[0]}'"]
    if op.idempotency:
        spec.append(f"idempotency: '{op.idempotency}'")
    spec.append("options")
    if op.response.kind == "binary":
        spec.append("accept: '*/*'")

    r = op.response
    assign = "" if r.kind == "empty" else "const response = "
    lines.append(f"        {assign}await this.#http.request({{ {', '.join(spec)} }});")
    item = ts_type(r.type, "response", "T.")
    if r.kind == "binary":
        lines.append("        return DownloadedFile.fromResponse(response.headers, response.body);")
    elif r.kind == "raw":
        lines.append("        return jsonOf(response);")
    elif r.kind == "page":
        path_args = "".join(f"{p.name}, " for p in op.path_params)
        lines.append(
            f"        return Page.fromJson<{item}>(jsonOf(response), (cursor) => this.{op.method}({path_args}{{ ...query, cursor }}, options));"
        )
    elif r.kind != "empty":
        if r.kind == "list":
            value = f"(dataOf(response) ?? []) as {return_type(op) if not r.has_meta else (f'({item})[]' if ' ' in item else f'{item}[]')}"
        else:
            value = f"dataOf(response) as {item}"
        if r.has_meta:
            lines.append(f"        return {{ data: {value}, meta: metaOf(response) }};")
        else:
            lines.append(f"        return {value};")
    lines.append("    }")
    return lines


def emit_client(api: Api) -> str:
    needs = {"dataOf", "jsonOf", "metaOf"}
    lines = [
        f"// {GENERATED}",
        "",
        "import { DownloadedFile, type FileUpload } from './files.js';",
        "import {",
        "    dataOf,",
        "    HttpClient,",
        "    jsonOf,",
        "    metaOf,",
        "    type ClientOptions,",
        "    type RequestOptions,",
        "} from './http.js';",
        "import { Page, type ApiResult } from './page.js';",
        "import type * as T from './types.js';",
        "",
        "/**",
        " * Cliente da API v1 da AssinaVelox.",
        " *",
        " * ```ts",
        " * const client = new AssinaVelox({ baseUrl: 'https://sua-instalacao.example/api/v1', token: process.env.ASSINAVELOX_TOKEN! });",
        " * ```",
        " *",
        " * O token fica num campo privado (não aparece em `console.log`). Redirecionamentos",
        " * não são seguidos. Erros da API viram `ApiError` (RFC 9457).",
        " */",
        "export class AssinaVelox {",
        "    readonly #http: HttpClient;",
        "",
        "    constructor(options: ClientOptions) {",
        "        this.#http = new HttpClient(options);",
        "    }",
        "",
        "    get baseUrl(): string {",
        "        return this.#http.baseUrl;",
        "    }",
    ]
    for op in api.operations:
        lines += emit_method(op)
    lines.append("}")
    del needs
    return "\n".join(lines) + "\n"


def named_checks(api: Api, name: str, target: str) -> list[str]:
    lines = [f"assert.equal(typeof {target}, 'object');"]
    obj = api.responses.get(name)
    if obj is not None:
        lines += [
            f"assert.equal(typeof {target}[{ts_str(f.wire)}], '{JS_TYPEOF[f.type.prim or 'string']}', 'campo {f.wire}');"
            for f in checked_fields(obj)
        ]
    return lines


def assertions(api: Api, op: Operation) -> list[str]:
    r = op.response
    named = r.type.name if r.type is not None and r.type.kind == "named" else None
    if r.kind == "empty":
        return ["assert.equal(result, undefined);"]
    if r.kind == "binary":
        return [
            "assert.ok(result instanceof DownloadedFile);",
            "assert.equal(Buffer.from(result.content.subarray(0, 4)).toString(), '%PDF');",
            "assert.equal(result.contentType, 'application/pdf');",
            "assert.equal(result.filename, 'AV-000123-original.pdf');",
        ]
    if r.kind == "raw":
        return ["assert.notEqual(result, null);"]
    if r.kind == "page":
        lines = [
            "assert.ok(result instanceof Page);",
            "assert.equal(result.data.length, 2);",
            "assert.equal(result.nextCursor, 'fake-cursor-2');",
        ]
        if named:
            lines += named_checks(api, named, "result.data[0]")
        lines += [
            "const following = await result.nextPage();",
            "assert.ok(following !== null);",
            "assert.equal(following.data.length, 1);",
            "assert.equal(following.hasMore, false);",
            "assert.equal(await following.nextPage(), null);",
            "let total = 0;",
            "for await (const _item of result) total++;",
            "assert.equal(total, 3);",
        ]
        return lines
    lines: list[str] = []
    target = "result"
    if r.has_meta:
        lines += ["assert.equal(typeof result.meta, 'object');"]
        target = "result.data"
    if r.kind == "list":
        lines += [f"assert.ok(Array.isArray({target}) && {target}.length >= 1);"]
        if named:
            lines += named_checks(api, named, f"{target}[0]")
    elif named:
        lines += named_checks(api, named, target)
    else:
        lines.append(f"assert.equal(typeof {target}, 'object');")
    return lines


def emit_tests(api: Api) -> str:
    lines = [
        f"// Cada operação da especificação contra o servidor falso. {GENERATED}",
        "",
        "import assert from 'node:assert/strict';",
        "import { test } from 'node:test';",
        "",
        "import { AssinaVelox, DownloadedFile, Page } from '../dist/index.js';",
        "import { fakeUrl, fetchTrace, newTrace, UUID_V4 } from './support.mjs';",
        "",
        "const client = () =>",
        "    new AssinaVelox({ baseUrl: fakeUrl(), token: 'tok_teste_sdk' });",
        "",
        "async function checkTrace(trace, operation, idempotency) {",
        "    const records = await fetchTrace(trace);",
        "    assert.ok(records.length > 0, 'o servidor falso não recebeu o pedido');",
        "    assert.equal(records[0].operation, operation);",
        "    assert.deepEqual(records[0].violations, []);",
        "    const key = records[0].headers['idempotency-key'];",
        "    if (idempotency === 'required') {",
        "        assert.match(key ?? '', UUID_V4);",
        "    } else if (idempotency === 'optional') {",
        "        assert.equal(key, undefined);",
        "    }",
        "}",
    ]
    for op in api.operations:
        args = [ts_str(path_sample(p.wire, p.schema)) for p in op.path_params]
        if op.body is not None and op.body.kind == "json":
            args.append(json.dumps(body_sample(op, api), ensure_ascii=False))
        elif op.body is not None:
            data = ", ".join(str(b) for b in UPLOAD_SAMPLE)
            args.append(f"{{ content: new Uint8Array([{data}]), filename: 'contrato.pdf', contentType: 'application/pdf' }}")
        if op.query_params:
            args.append("{}")
        args.append("options")
        idem = ts_str(op.idempotency) if op.idempotency else "null"
        lines += [
            "",
            f"void test({ts_str(f'operação {op.method} ({op.id})')}, async () => {{",
            "    const [options, trace] = newTrace();",
            f"    const result = await client().{op.method}({', '.join(args)});",
            *[f"    {line}" for line in assertions(api, op)],
            f"    await checkTrace(trace, '{op.id}', {idem});",
            "});",
        ]
    return "\n".join(lines) + "\n"


def emit(api: Api, root: Path) -> dict[Path, str]:
    return {
        root / "src" / "version.ts": emit_version(api),
        root / "src" / "types.ts": emit_types(api),
        root / "src" / "client.ts": emit_client(api),
        root / "test" / "operations.generated.test.mjs": emit_tests(api),
    }
