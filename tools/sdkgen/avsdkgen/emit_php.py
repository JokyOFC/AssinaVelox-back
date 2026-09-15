"""Saída PHP (8.1+, sem dependências, PSR-4 `AssinaVelox\\Sdk\\`): modelos, cliente e testes gerados."""

from __future__ import annotations

import json
from pathlib import Path

from .common import GENERATED, UPLOAD_SAMPLE, body_sample, checked_fields, idempotency_note, path_sample, response_note, summary
from .model import Api, ObjDef, Operation, TypeRef, camel

PRIMS = {"string": "string", "integer": "int", "number": "float", "boolean": "bool"}


def php_str(value: str) -> str:
    return "'" + value.replace("\\", "\\\\").replace("'", "\\'") + "'"


def php_bytes(value: bytes) -> str:
    return '"' + "".join(f"\\x{byte:02x}" for byte in value) + '"'


def native(t: TypeRef) -> str:
    if t.kind == "prim":
        base = PRIMS[t.prim or "string"]
    elif t.kind == "named":
        base = t.name or "mixed"
    elif t.kind in ("array", "map"):
        base = "array"
    elif t.kind == "union":
        base = "|".join(PRIMS[m] for m in t.members)
    else:
        return "mixed"
    if not t.nullable:
        return base
    return base + "|null" if "|" in base else "?" + base


def doc(t: TypeRef | None, api: Api, role: str, prefix: str = "") -> str:
    if t is None:
        return "mixed"
    if t.kind == "prim":
        if role == "request" and t.enum and t.prim == "string":
            base = "|".join(php_str(str(v)) for v in t.enum)
        else:
            base = PRIMS[t.prim or "string"]
    elif t.kind == "named":
        if role == "request" and t.name in api.requests:
            base = shape(api.requests[t.name], api)
        else:
            base = prefix + (t.name or "mixed")
    elif t.kind == "array":
        base = f"list<{doc(t.item, api, role, prefix)}>"
    elif t.kind == "map":
        base = f"array<string, {doc(t.item, api, role, prefix)}>"
    elif t.kind == "union":
        base = "|".join(PRIMS[m] for m in t.members)
    else:
        return "mixed"
    return f"{base}|null" if t.nullable else base


def shape(obj: ObjDef, api: Api) -> str:
    parts = [f"{f.wire}{'' if f.required else '?'}: {doc(f.type, api, 'request')}" for f in obj.fields]
    return "array{" + ", ".join(parts) + "}"


def emit_version(api: Api) -> str:
    return f"""<?php

declare(strict_types=1);

namespace AssinaVelox\\Sdk;

/**
 * Versões do SDK e da API. {GENERATED}
 */
final class Version
{{
    public const SDK = '{api.sdk_version}';

    public const API = '{api.api_version}';

    public const API_MAJOR = {api.api_major};

    /** Impressão digital (SHA-256) da especificação de onde este SDK foi gerado. */
    public const SPEC_SHA256 = '{api.fingerprint}';
}}
"""


def convert(t: TypeRef, key: str, required: bool) -> str:
    access = f"$data[{php_str(key)}]"
    fallback = "[]" if required and not t.nullable else "null"
    if t.kind == "named":
        return f"isset({access}) && is_array({access}) ? {t.name}::fromArray({access}) : null"
    if t.kind == "array" and t.item is not None and t.item.kind == "named":
        return f"isset({access}) && is_array({access}) ? Hydrator::listOf({t.item.name}::class, {access}) : {fallback}"
    if t.kind in ("array", "map"):
        return f"{access} ?? {fallback}"
    return f"{access} ?? null"


def emit_model(obj: ObjDef, api: Api) -> str:
    params: list[str] = []
    docs: list[str] = []
    assigns: list[str] = []
    for f in obj.fields:
        prop = camel(f.wire)
        t = TypeRef(**{**f.type.__dict__, "nullable": f.type.nullable or not f.required})
        params.append(f"        public readonly {native(t)} ${prop},")
        described = doc(t, api, "response")
        if t.kind in ("array", "map") or f.description:
            docs.append(f"     * @param  {described}  ${prop}" + (f"  {f.description.splitlines()[0]}" if f.description else ""))
        assigns.append(f"            {prop}: {convert(f.type, f.wire, f.required)},")
    description = (obj.description or f"{obj.name} (API v1).").splitlines()[0]
    docs.append("     * @param  array<string, mixed>  $raw  Resposta original, com campos que esta versão do SDK ainda não conhece.")
    lines = [
        "<?php",
        "",
        "declare(strict_types=1);",
        "",
        "namespace AssinaVelox\\Sdk\\Model;",
        "",
        "/**",
        f" * {description}",
        " *",
        f" * {GENERATED}",
        " */",
        f"final class {obj.name} implements \\JsonSerializable",
        "{",
        "    /**",
        *docs,
        "     */",
        "    public function __construct(",
        *params,
        "        public readonly array $raw = [],",
        "    ) {",
        "    }",
        "",
        "    /**",
        "     * @param  array<string, mixed>  $data",
        "     */",
        "    public static function fromArray(array $data): self",
        "    {",
        "        return new self(",
        *assigns,
        "            raw: $data,",
        "        );",
        "    }",
        "",
        "    /**",
        "     * @return array<string, mixed>",
        "     */",
        "    public function jsonSerialize(): array",
        "    {",
        "        return $this->raw;",
        "    }",
        "}",
    ]
    return "\n".join(lines) + "\n"


def emit_hydrator() -> str:
    return f"""<?php

declare(strict_types=1);

namespace AssinaVelox\\Sdk\\Model;

/**
 * Apoio dos modelos (uso interno). {GENERATED}
 */
final class Hydrator
{{
    /**
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @param  array<mixed>  $items
     * @return list<T>
     */
    public static function listOf(string $class, array $items): array
    {{
        $result = [];

        foreach ($items as $item) {{
            if (is_array($item)) {{
                $result[] = $class::fromArray($item);
            }}
        }}

        return $result;
    }}
}}
"""


def return_info(op: Operation, api: Api) -> tuple[str, str]:
    """(tipo nativo, tipo do phpdoc)."""
    r = op.response
    if r.kind == "empty":
        return "void", "void"
    if r.kind == "binary":
        return "DownloadedFile", "DownloadedFile"
    if r.kind == "raw":
        return "mixed", "mixed"
    item = doc(r.type, api, "response", "Model\\")
    if r.kind == "page":
        return "Page", f"Page<{item}>"
    inner_doc = f"list<{item}>" if r.kind == "list" else item
    inner_native = "array" if r.kind == "list" or (r.type and r.type.kind in ("map", "array")) else (
        f"Model\\{r.type.name}" if r.type and r.type.kind == "named" else "mixed"
    )
    if r.has_meta:
        return "ApiResult", f"ApiResult<{inner_doc}>"
    return inner_native, inner_doc


def emit_method(op: Operation, api: Api) -> list[str]:
    args: list[str] = []
    param_docs: list[str] = []
    for p in op.path_params:
        args.append(f"string ${camel(p.wire)}")
        if p.schema.get("enum"):
            param_docs.append(f"     * @param  {'|'.join(php_str(str(v)) for v in p.schema['enum'])}  ${camel(p.wire)}")
    if op.body is not None and op.body.kind == "json":
        body_doc = doc(op.body.type, api, "request")
        if op.body.required:
            args.append("array|object $body")
            param_docs.append(f"     * @param  {body_doc}|object  $body")
        else:
            args.append("array|object|null $body = null")
            param_docs.append(f"     * @param  {body_doc}|object|null  $body")
    elif op.body is not None:
        args.append("FileUpload $file")
    if op.query_params:
        args.append("array $query = []")
        fields = []
        for q in op.query_params:
            if q.repeat:
                item = doc(q.type.item, api, "request") if q.type.item else "string"
                fields.append(f"{q.name}?: list<{item}>|{item}|null")
            else:
                text = doc(q.type, api, "request")
                fields.append(f"{q.name}?: {text}" + ("" if text.endswith("|null") else "|null"))
        param_docs.append("     * @param  array{" + ", ".join(fields) + "}  $query")
    args.append("?RequestOptions $options = null")

    native_ret, doc_ret = return_info(op, api)
    notes = [n for n in (idempotency_note(op), response_note(op)) if n]
    if op.response.kind == "page":
        notes.append("Use autoPagingIterator() para percorrer todas as páginas.")
    lines = ["", "    /**", f"     * {summary(op)} — {op.http} {op.path}."]
    if notes:
        lines += ["     *", f"     * {' '.join(notes)}"]
    lines.append("     *")
    lines += param_docs
    if doc_ret not in ("void", native_ret):
        lines.append(f"     * @return {doc_ret}")
    lines += ["     */", f"    public function {op.method}({', '.join(args)}): {native_ret}", "    {"]

    call = [f"method: '{op.http}'", f"path: '{op.path}'"]
    if op.path_params:
        call.append("pathParams: [" + ", ".join(f"'{p.wire}' => ${camel(p.wire)}" for p in op.path_params) + "]")
    if op.query_params:
        call.append("query: $query")
        call.append("querySpec: [" + ", ".join(f"'{q.name}' => ['{q.wire}', {'true' if q.repeat else 'false'}]" for q in op.query_params) + "]")
    if op.body is not None and op.body.kind == "json":
        call += ["json: $body", "hasBody: true"]
    elif op.body is not None:
        call += ["file: $file", f"fileField: '{op.body.file_fields[0]}'"]
    if op.idempotency:
        call.append(f"idempotency: '{op.idempotency}'")
    call.append("options: $options")
    if op.response.kind == "binary":
        call.append("accept: '*/*'")

    r = op.response
    prefix = "" if r.kind == "empty" else "$response = "
    lines.append(f"        {prefix}$this->http->request(")
    lines += [f"            {c}," for c in call]
    lines.append("        );")

    named = r.type is not None and r.type.kind == "named"
    if r.kind == "empty":
        pass
    elif r.kind == "binary":
        lines += ["", "        return DownloadedFile::fromResponse($response);"]
    elif r.kind == "raw":
        lines += ["", "        return $response->json();"]
    elif r.kind == "page":
        path_args = "".join(f"${camel(p.wire)}, " for p in op.path_params)
        convert_fn = (
            f"static fn (array $item): Model\\{r.type.name} => Model\\{r.type.name}::fromArray($item)"
            if named and r.type
            else "static fn (mixed $item): mixed => $item"
        )
        lines += [
            "",
            "        return Page::fromResponse(",
            "            $response->json(),",
            f"            {convert_fn},",
            f"            fn (string $cursor): Page => $this->{op.method}({path_args}['cursor' => $cursor] + $query, $options),",
            "        );",
        ]
    else:
        if r.kind == "list":
            value = (
                f"Model\\Hydrator::listOf(Model\\{r.type.name}::class, self::items($response->data()))"
                if named and r.type
                else "self::items($response->data())"
            )
        elif named and r.type:
            value = f"Model\\{r.type.name}::fromArray(self::object($response->data()))"
        else:
            value = "self::object($response->data())"
        if r.has_meta:
            value = f"new ApiResult({value}, $response->meta())"
        lines += ["", f"        return {value};"]
    lines.append("    }")
    return lines


def emit_client(api: Api) -> str:
    lines = [
        "<?php",
        "",
        "declare(strict_types=1);",
        "",
        "namespace AssinaVelox\\Sdk;",
        "",
        "use AssinaVelox\\Sdk\\Http\\CurlTransport;",
        "use AssinaVelox\\Sdk\\Http\\HttpClient;",
        "use AssinaVelox\\Sdk\\Http\\StreamTransport;",
        "use AssinaVelox\\Sdk\\Http\\Transport;",
        "",
        "/**",
        " * Cliente da API v1 da AssinaVelox.",
        " *",
        " *     $client = new Client('https://sua-instalacao.example/api/v1', getenv('ASSINAVELOX_TOKEN'));",
        " *",
        " * O token nunca aparece em var_dump()/print_r() nem em mensagens de erro. Redirecionamentos",
        " * não são seguidos. Erros da API viram Exception\\ApiException (RFC 9457).",
        " *",
        f" * {GENERATED}",
        " */",
        "final class Client",
        "{",
        "    private readonly HttpClient $http;",
        "",
        "    /**",
        "     * @param  string  $baseUrl  Endereço da sua instalação, terminando em /api/v1.",
        "     * @param  string  $token  Texto da chave criada em Integrações → Chaves (exibido uma única vez).",
        "     * @param  float  $timeout  Tempo máximo de cada requisição, em segundos.",
        "     * @param  array<string, string>  $headers  Cabeçalhos extras em todas as requisições.",
        "     * @param  Transport|null  $transport  Padrão: curl, se a extensão existir; senão, streams.",
        "     */",
        "    public function __construct(",
        "        string $baseUrl,",
        "        #[\\SensitiveParameter] string $token,",
        "        float $timeout = 30.0,",
        "        array $headers = [],",
        "        ?Transport $transport = null,",
        "    ) {",
        "        $this->http = new HttpClient($baseUrl, $token, $timeout, $headers, $transport ?? (extension_loaded('curl') ? new CurlTransport : new StreamTransport));",
        "    }",
        "",
        "    public function baseUrl(): string",
        "    {",
        "        return $this->http->baseUrl();",
        "    }",
        "",
        "    /**",
        "     * @return array<string, mixed>",
        "     */",
        "    public function __debugInfo(): array",
        "    {",
        "        return ['baseUrl' => $this->http->baseUrl()];",
        "    }",
    ]
    for op in api.operations:
        lines += emit_method(op, api)
    lines += [
        "",
        "    /**",
        "     * @return array<string, mixed>",
        "     */",
        "    private static function object(mixed $value): array",
        "    {",
        "        return is_array($value) ? $value : [];",
        "    }",
        "",
        "    /**",
        "     * @return list<mixed>",
        "     */",
        "    private static function items(mixed $value): array",
        "    {",
        "        return is_array($value) ? array_values($value) : [];",
        "    }",
        "}",
    ]
    return "\n".join(lines) + "\n"


def named_checks(api: Api, name: str, target: str) -> list[str]:
    lines = [f"$t->instanceOf(Model\\{name}::class, {target});"]
    obj = api.responses.get(name)
    if obj is not None:
        lines += [f"$t->true({target}->{camel(f.wire)} !== null, 'campo {f.wire} lido');" for f in checked_fields(obj)]
        lines.append(f"$t->true({target}->raw !== []);")
    return lines


def assertions(api: Api, op: Operation) -> list[str]:
    r = op.response
    named = r.type.name if r.type is not None and r.type.kind == "named" else None
    if r.kind == "empty":
        return []
    if r.kind == "binary":
        return [
            "$t->instanceOf(DownloadedFile::class, $result);",
            "$t->true(str_starts_with($result->content, '%PDF'));",
            "$t->same('application/pdf', $result->contentType);",
            "$t->same('AV-000123-original.pdf', $result->filename);",
        ]
    if r.kind == "raw":
        return ["$t->true($result !== null);"]
    if r.kind == "page":
        lines = [
            "$t->instanceOf(Page::class, $result);",
            "$t->same(2, count($result->data));",
            "$t->same('fake-cursor-2', $result->nextCursor());",
        ]
        if named:
            lines += named_checks(api, named, "$result->data[0]")
        lines += [
            "$following = $result->nextPage();",
            "$t->true($following !== null);",
            "$t->same(1, count($following->data));",
            "$t->false($following->hasMore());",
            "$t->same(null, $following->nextPage());",
            "$t->same(3, iterator_count($result->autoPagingIterator()));",
        ]
        return lines
    lines: list[str] = []
    target = "$result"
    if r.has_meta:
        lines += ["$t->instanceOf(ApiResult::class, $result);", "$t->true(is_array($result->meta));"]
        target = "$result->data"
    if r.kind == "list":
        lines += [f"$t->true(is_array({target}) && count({target}) >= 1);"]
        if named:
            lines += named_checks(api, named, f"{target}[0]")
    elif named:
        lines += named_checks(api, named, target)
    else:
        lines.append(f"$t->true(is_array({target}));")
    return lines


def emit_tests(api: Api) -> str:
    lines = [
        "<?php",
        "",
        "declare(strict_types=1);",
        "",
        "use AssinaVelox\\Sdk\\ApiResult;",
        "use AssinaVelox\\Sdk\\DownloadedFile;",
        "use AssinaVelox\\Sdk\\FileUpload;",
        "use AssinaVelox\\Sdk\\Model;",
        "use AssinaVelox\\Sdk\\Page;",
        "",
        "/*",
        f" * Cada operação da especificação contra o servidor falso. {GENERATED}",
        " */",
    ]
    for op in api.operations:
        args = [php_str(path_sample(p.wire, p.schema)) for p in op.path_params]
        if op.body is not None and op.body.kind == "json":
            args.append(f"json_decode({php_str(json.dumps(body_sample(op, api), ensure_ascii=False))}, true, 512, JSON_THROW_ON_ERROR)")
        elif op.body is not None:
            args.append(f"FileUpload::fromString({php_bytes(UPLOAD_SAMPLE)}, 'contrato.pdf', 'application/pdf')")
        if op.query_params:
            args.append("[]")
        args.append("$options")
        call = f"$t->client()->{op.method}({', '.join(args)});"
        body = [
            "    [$options, $trace] = $t->trace();",
            f"    {call}" if op.response.kind == "empty" else f"    $result = {call}",
        ]
        body += [f"    {line}" for line in assertions(api, op)]
        idem = php_str(op.idempotency) if op.idempotency else "null"
        body.append(f"    $t->checkTrace($trace, '{op.id}', {idem});")
        lines += ["", f"SdkTest::add('operação {op.method} ({op.id})', static function (TestContext $t): void {{", *body, "});"]
    return "\n".join(lines) + "\n"


def emit(api: Api, root: Path) -> dict[Path, str]:
    files = {
        root / "src" / "Version.php": emit_version(api),
        root / "src" / "Client.php": emit_client(api),
        root / "src" / "Model" / "Hydrator.php": emit_hydrator(),
        root / "tests" / "generated_operations.php": emit_tests(api),
    }
    for obj in api.responses.values():
        files[root / "src" / "Model" / f"{obj.name}.php"] = emit_model(obj, api)
    return files
