"""Servidor falso da API v1 para os testes dos SDKs (só biblioteca padrão).

Responde com base em sdks/openapi/v1.json + tools/sdkgen/overrides.json e confere cada pedido:
método e caminho, Authorization Bearer, User-Agent, Accept, Idempotency-Key (obrigatória ou
aceita, conforme a rota), parâmetros de query conhecidos e corpo validado contra o esquema
(propriedades desconhecidas recusadas). Pedido fora do contrato → 400
`urn:assinavelox:problem:fake-contract-violation` com a lista de problemas.

Cenários (para os testes de erro):
  token `fake-401`       → 401 unauthenticated
  token `fake-429`       → 429 rate-limited com Retry-After: 7
  token `fake-502-html`  → 502 com corpo HTML (erro que não é RFC 9457)
  token `fake-slow`      → responde depois de 2 s (teste de tempo esgotado)
  id `01FAKENOTFOUND000000000000` em qualquer parâmetro de caminho → 404 not-found
  corpo JSON com `"title": "fake-422"` → 422 validation-failed com `errors.title`

Introspecção: cabeçalho `X-Fake-Trace: <id>` no pedido; `GET /__fake/requests?trace=<id>`
devolve o que o servidor recebeu (método, caminho, query, cabeçalhos, corpo, arquivos).

    python tools/sdkgen/fake_server.py [--host 127.0.0.1] [--port 0]
    → imprime `FAKE_API_URL=http://127.0.0.1:<porta>/api/v1`
"""

from __future__ import annotations

import argparse
import hashlib
import json
import re
import sys
import threading
import time
import uuid
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from typing import Any
from urllib.parse import parse_qsl, unquote, urlsplit

sys.path.insert(0, str(Path(__file__).resolve().parent))

from avsdkgen import spec as specmod  # noqa: E402
from avsdkgen.model import Operation, build  # noqa: E402
from avsdkgen.schema import ULID_SAMPLE, resolve, sample, validate  # noqa: E402

BASE = "/api/v1"
NOT_FOUND_ID = "01FAKENOTFOUND000000000000"
USER_AGENT = re.compile(r"^assinavelox-(php|node|python)/\d+\.\d+\.\d+ \(api-v\d+; ")
IDEMPOTENCY_KEY = re.compile(r"[\x21-\x7e]{1,255}")
PDF_BYTES = b"%PDF-1.4\n% arquivo falso do servidor de testes dos SDKs\n%%EOF\n"
MAX_TRACES = 2000

Response = tuple[int, dict[str, str], bytes]


class FakeApi:
    def __init__(self) -> None:
        spec = specmod.load_spec()
        overrides = specmod.load_overrides()
        self.api = build(spec, overrides, specmod.source_fingerprint(spec, overrides))
        self.root = self.api.root
        self.routes = [
            (re.compile("^" + re.sub(r"\{(\w+)\}", r"(?P<\1>[^/]+)", op.path) + "$"), op) for op in self.api.operations
        ]
        self._records: dict[str, list[dict[str, Any]]] = {}
        self._lock = threading.Lock()

    def record(self, trace: str, entry: dict[str, Any]) -> None:
        with self._lock:
            if len(self._records) >= MAX_TRACES:
                self._records.pop(next(iter(self._records)))
            self._records.setdefault(trace, []).append(entry)

    def records(self, trace: str) -> list[dict[str, Any]]:
        with self._lock:
            return list(self._records.get(trace, []))


def parse_multipart(content_type: str, body: bytes) -> tuple[dict[str, dict[str, Any]], list[str]]:
    """Leitor exato de multipart/form-data (sem normalizar quebras de linha do arquivo)."""
    problems: list[str] = []
    found = re.search(r'boundary="?([^";]+)"?', content_type)
    if not found:
        return {}, ["multipart sem boundary"]
    delimiter = b"--" + found.group(1).encode("latin-1")
    sections = body.split(delimiter)
    if not sections or sections[0] not in (b"", b"\r\n"):
        problems.append("multipart com conteúdo antes do primeiro boundary")
    if not body.endswith(delimiter + b"--\r\n") and not body.endswith(delimiter + b"--"):
        problems.append("multipart sem o boundary final")
    parts: dict[str, dict[str, Any]] = {}
    for section in sections[1:]:
        if section.startswith(b"--"):
            break
        if not section.startswith(b"\r\n"):
            problems.append("parte sem CRLF depois do boundary")
            continue
        head, separator, content = section[2:].partition(b"\r\n\r\n")
        if not separator:
            problems.append("parte sem cabeçalhos")
            continue
        if content.endswith(b"\r\n"):
            content = content[:-2]
        else:
            problems.append("parte sem CRLF antes do boundary")
        headers: dict[str, str] = {}
        for line in head.decode("utf-8", "replace").split("\r\n"):
            name, _, value = line.partition(":")
            headers[name.strip().lower()] = value.strip()
        disposition = headers.get("content-disposition", "")
        name_match = re.search(r'(?:^|;)\s*name="([^"]*)"', disposition)
        file_match = re.search(r'filename="([^"]*)"', disposition)
        if not disposition.startswith("form-data") or not name_match:
            problems.append(f"Content-Disposition inválido: {disposition!r}")
            continue
        parts[name_match.group(1)] = {
            "filename": file_match.group(1) if file_match else None,
            "content_type": headers.get("content-type"),
            "size": len(content),
            "sha256": hashlib.sha256(content).hexdigest(),
        }
    return parts, problems


class Handler(BaseHTTPRequestHandler):
    server_version = "AssinaVeloxFake/1.0"
    fake: FakeApi

    def log_message(self, format: str, *args: Any) -> None:  # noqa: A002 - assinatura da biblioteca
        return

    def do_GET(self) -> None:  # noqa: N802
        self._handle()

    do_POST = do_PUT = do_PATCH = do_DELETE = do_GET  # noqa: N815

    # ---- infraestrutura ----------------------------------------------------------------

    def _read_body(self) -> bytes:
        if "chunked" in (self.headers.get("Transfer-Encoding") or "").lower():
            chunks = []
            while True:
                size = int(self.rfile.readline().strip().split(b";")[0] or b"0", 16)
                if size == 0:
                    self.rfile.readline()
                    break
                chunks.append(self.rfile.read(size))
                self.rfile.readline()
            return b"".join(chunks)
        length = int(self.headers.get("Content-Length") or 0)
        return self.rfile.read(length) if length > 0 else b""

    def _send(self, response: Response) -> None:
        status, headers, payload = response
        self.send_response(status)
        for name, value in headers.items():
            self.send_header(name, value)
        self.send_header("Content-Length", str(len(payload)))
        self.end_headers()
        self.wfile.write(payload)

    def _problem(
        self,
        status: int,
        slug: str,
        title: str,
        detail: str | None = None,
        extra: dict[str, Any] | None = None,
        headers: dict[str, str] | None = None,
    ) -> Response:
        correlation = str(uuid.uuid4())
        body: dict[str, Any] = {"type": f"urn:assinavelox:problem:{slug}", "title": title, "status": status}
        if detail:
            body["detail"] = detail
        body["instance"] = urlsplit(self.path).path
        body.update(extra or {})
        body["correlation_id"] = correlation
        result = {
            "Content-Type": "application/problem+json",
            "X-Correlation-Id": correlation,
            "Cache-Control": "no-store",
        }
        result.update(headers or {})
        return status, result, json.dumps(body, ensure_ascii=False).encode("utf-8")

    # ---- pedido ------------------------------------------------------------------------

    def _handle(self) -> None:
        split = urlsplit(self.path)
        query = parse_qsl(split.query, keep_blank_values=True)
        headers = {name.lower(): value for name, value in self.headers.items()}
        body = self._read_body()

        if split.path.startswith("/__fake/"):
            self._send(self._introspect(split.path, dict(query)))
            return

        entry: dict[str, Any] = {
            "method": self.command,
            "path": split.path,
            "query": [[k, v] for k, v in query],
            "headers": headers,
            "operation": None,
            "violations": [],
        }
        response = self._dispatch(entry, split.path, query, headers, body)
        entry["status"] = response[0]
        trace = headers.get("x-fake-trace")
        if trace:
            self.fake.record(trace, entry)
        self._send(response)

    def _introspect(self, path: str, query: dict[str, str]) -> Response:
        if path == "/__fake/requests":
            payload = json.dumps(self.fake.records(query.get("trace", "")), ensure_ascii=False).encode("utf-8")
            return 200, {"Content-Type": "application/json"}, payload
        if path == "/__fake/health":
            payload = json.dumps({"ok": True, "fingerprint": self.fake.api.fingerprint}).encode("utf-8")
            return 200, {"Content-Type": "application/json"}, payload
        return 404, {"Content-Type": "text/plain"}, b"rota de introspeccao desconhecida"

    def _dispatch(
        self,
        entry: dict[str, Any],
        path: str,
        query: list[tuple[str, str]],
        headers: dict[str, str],
        body: bytes,
    ) -> Response:
        violations: list[str] = entry["violations"]
        if not path.startswith(BASE + "/"):
            violations.append(f"caminho fora de {BASE}: {path}")
            return self._problem(404, "not-found", "Recurso não encontrado")
        relative = path[len(BASE) :]
        matches = [(m, op) for regex, op in self.fake.routes if (m := regex.match(relative))]
        if not matches:
            violations.append(f"rota desconhecida: {relative}")
            return self._problem(404, "not-found", "Recurso não encontrado")
        same = [(m, op) for m, op in matches if op.http == self.command]
        if not same:
            allow = ", ".join(sorted({op.http for _, op in matches}))
            return self._problem(405, "method-not-allowed", "Método não permitido", headers={"Allow": allow})
        match, op = same[0]
        entry["operation"] = op.id

        auth = re.fullmatch(r"Bearer (\S+)", headers.get("authorization", ""))
        if auth is None:
            violations.append("sem Authorization: Bearer")
            return self._problem(
                401, "unauthenticated", "Autenticação necessária", headers={"WWW-Authenticate": "Bearer"}
            )
        token = auth.group(1)
        if token == "fake-401":
            return self._problem(
                401,
                "unauthenticated",
                "Autenticação necessária",
                'Envie um token de API válido no cabeçalho "Authorization: Bearer".',
                headers={"WWW-Authenticate": "Bearer"},
            )
        if token == "fake-429":
            return self._problem(
                429,
                "rate-limited",
                "Muitas requisições",
                'O limite de requisições foi atingido. Aguarde o tempo indicado em "Retry-After".',
                headers={"Retry-After": "7", "RateLimit-Limit": "120", "RateLimit-Remaining": "0", "RateLimit-Reset": "7"},
            )
        if token == "fake-502-html":
            return 502, {"Content-Type": "text/html; charset=utf-8"}, b"<html><body><h1>502 Bad Gateway</h1></body></html>"
        if token == "fake-slow":
            time.sleep(2.0)

        user_agent = headers.get("user-agent", "")
        if not USER_AGENT.match(user_agent):
            violations.append(f"User-Agent fora do formato: {user_agent!r}")
        if op.response.kind != "binary" and "application/json" not in headers.get("accept", ""):
            violations.append("Accept sem application/json")

        key = headers.get("idempotency-key")
        entry["idempotency_key"] = key
        if key is None and op.idempotency == "required":
            return self._problem(
                400,
                "idempotency-key-missing",
                "Cabeçalho Idempotency-Key obrigatório",
                'Envie um cabeçalho "Idempotency-Key" com um valor único (ex.: um UUID) em cada criação ou envio.',
            )
        if key is not None and not IDEMPOTENCY_KEY.fullmatch(key):
            return self._problem(
                400,
                "idempotency-key-invalid",
                "Idempotency-Key inválida",
                "A chave deve ter de 1 a 255 caracteres ASCII visíveis, sem espaços.",
            )

        path_values = {name: unquote(value) for name, value in match.groupdict().items()}
        entry["path_params"] = path_values
        for param in op.path_params:
            value = path_values[param.wire]
            if value == NOT_FOUND_ID or (param.schema.get("enum") and value not in param.schema["enum"]):
                return self._problem(404, "not-found", "Recurso não encontrado")

        grouped: dict[str, list[str]] = {}
        for name, value in query:
            grouped.setdefault(name, []).append(value)
        known = {p.wire: p for p in op.query_params}
        for name, values in grouped.items():
            param = known.get(name)
            if param is None:
                violations.append(f"parâmetro de query desconhecido: {name}")
                continue
            if param.repeat:
                candidate: Any = values
            else:
                if len(values) > 1:
                    violations.append(f"parâmetro {name} repetido")
                candidate = values[-1]
                declared = param.schema.get("type")
                declared = declared if isinstance(declared, list) else [declared]
                if "integer" in declared:
                    try:
                        candidate = int(candidate)
                    except ValueError:
                        violations.append(f"query.{name}: não é inteiro")
                        continue
            violations.extend(validate(candidate, param.schema, self.fake.root, f"query.{name}"))

        content_type = headers.get("content-type", "")
        if op.body is None:
            if body:
                violations.append("corpo enviado numa operação sem corpo")
        elif op.body.kind == "json":
            if not body:
                if op.body.required:
                    violations.append("corpo obrigatório ausente")
            else:
                if not content_type.startswith("application/json"):
                    violations.append(f"Content-Type {content_type!r} num corpo JSON")
                try:
                    payload = json.loads(body.decode("utf-8"))
                except (UnicodeDecodeError, json.JSONDecodeError):
                    violations.append("corpo não é JSON válido em UTF-8")
                else:
                    entry["json"] = payload
                    violations.extend(validate(payload, op.body.schema, self.fake.root, "$body", strict=True))
                    if isinstance(payload, dict) and payload.get("title") == "fake-422":
                        return self._problem(
                            422,
                            "validation-failed",
                            "Dados inválidos",
                            "Um ou mais campos não passaram na validação.",
                            extra={"errors": {"title": ["O campo título deve ter pelo menos 3 caracteres."]}},
                        )
        else:
            if not content_type.startswith("multipart/form-data"):
                violations.append(f"Content-Type {content_type!r} num upload")
            parts, problems = parse_multipart(content_type, body)
            violations.extend(problems)
            entry["files"] = parts
            for name in op.body.file_fields:
                if name not in parts:
                    violations.append(f"arquivo {name} ausente no multipart")
                elif not parts[name]["filename"]:
                    violations.append(f"arquivo {name} sem filename")
            for name in parts:
                if name not in op.body.file_fields:
                    violations.append(f"campo multipart desconhecido: {name}")

        if violations:
            return self._problem(
                400,
                "fake-contract-violation",
                "Pedido fora do contrato (servidor falso)",
                "; ".join(violations[:10]),
                extra={"violations": violations},
            )
        return self._success(op, grouped, relative)

    def _success(self, op: Operation, grouped: dict[str, list[str]], relative: str) -> Response:
        status = op.response.statuses[0]
        host = self.headers.get("Host") or "127.0.0.1"
        headers = {
            "X-Correlation-Id": str(uuid.uuid4()),
            "RateLimit-Limit": "120",
            "RateLimit-Remaining": "119",
            "RateLimit-Reset": "60",
        }
        kind = op.response.kind
        if kind == "empty":
            return 204, headers, b""
        if kind == "binary":
            headers["Content-Type"] = "application/pdf"
            headers["Content-Disposition"] = 'attachment; filename="AV-000123-original.pdf"'
            return 200, headers, PDF_BYTES

        schema = op.response.schema or {}
        root = self.fake.root
        if kind == "page":
            cursor = (grouped.get("cursor") or [None])[-1]
            per_page = int((grouped.get("per_page") or ["25"])[-1])
            if cursor is None:
                count, next_cursor, prev_cursor = 2, "fake-cursor-2", None
            elif cursor == "fake-cursor-2":
                count, next_cursor, prev_cursor = 1, None, "fake-cursor-1"
            else:
                return self._problem(
                    422,
                    "validation-failed",
                    "Dados inválidos",
                    "Um ou mais campos não passaram na validação.",
                    extra={"errors": {"cursor": ["Cursor inválido."]}},
                )
            items = resolve(schema["properties"]["data"], root)["items"]
            url = f"http://{host}{BASE}{relative}"
            payload: Any = {
                "data": [sample(items, root, "item") for _ in range(count)],
                "links": {
                    "first": None,
                    "last": None,
                    "prev": f"{url}?cursor={prev_cursor}" if prev_cursor else None,
                    "next": f"{url}?cursor={next_cursor}" if next_cursor else None,
                },
                "meta": {"path": url, "per_page": per_page, "next_cursor": next_cursor, "prev_cursor": prev_cursor},
            }
        else:
            payload = sample(schema, root, "resposta")

        problems = validate(payload, schema, root, "$resposta")
        if problems:
            return self._problem(500, "internal-error", "Erro interno", "servidor falso: " + "; ".join(problems[:5]))
        if status == 201:
            headers["Location"] = f"http://{host}{BASE}{relative}/{ULID_SAMPLE}"
        headers["Content-Type"] = "application/json"
        return status, headers, json.dumps(payload, ensure_ascii=False).encode("utf-8")


class FakeHttpServer(ThreadingHTTPServer):
    daemon_threads = True

    def handle_error(self, request: Any, client_address: Any) -> None:
        # O cliente que desiste por tempo esgotado fecha a conexão: não é erro do servidor.
        if isinstance(sys.exc_info()[1], (ConnectionError, TimeoutError)):
            return
        super().handle_error(request, client_address)


def make_server(host: str = "127.0.0.1", port: int = 0) -> FakeHttpServer:
    handler = type("BoundHandler", (Handler,), {"fake": FakeApi()})
    return FakeHttpServer((host, port), handler)


def start_in_thread(host: str = "127.0.0.1", port: int = 0) -> tuple[FakeHttpServer, str]:
    server = make_server(host, port)
    threading.Thread(target=server.serve_forever, daemon=True).start()
    return server, f"http://{host}:{server.server_address[1]}{BASE}"


def main() -> int:
    parser = argparse.ArgumentParser(description="Servidor falso da API v1 (testes dos SDKs)")
    parser.add_argument("--host", default="127.0.0.1")
    parser.add_argument("--port", type=int, default=0)
    args = parser.parse_args()
    server = make_server(args.host, args.port)
    print(f"FAKE_API_URL=http://{args.host}:{server.server_address[1]}{BASE}", flush=True)
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        pass
    finally:
        server.server_close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
