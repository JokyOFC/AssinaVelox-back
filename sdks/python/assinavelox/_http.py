"""Transporte HTTP do SDK (urllib da biblioteca padrão). Uso interno."""

from __future__ import annotations

import json
import platform
import re
import socket
import urllib.error
import urllib.parse
import urllib.request
import uuid
from dataclasses import dataclass
from typing import Any, Iterable, Mapping

from ._version import API_MAJOR, SDK_VERSION
from .errors import ApiError, InvalidRequestError, NetworkError, RequestTimeoutError
from .files import FileUpload

IDEMPOTENCY_KEY = re.compile(r"[\x21-\x7e]{1,255}")
_UNSAFE_HEADER = re.compile(r"[\r\n\x00]")


@dataclass(frozen=True)
class RawResponse:
    status: int
    headers: dict[str, str]
    body: bytes

    def json(self) -> Any:
        return json.loads(self.body.decode("utf-8")) if self.body else None


class _NoRedirect(urllib.request.HTTPRedirectHandler):
    """Redirecionamento não é seguido: o token nunca vai para outro endereço."""

    def redirect_request(self, req, fp, code, msg, headers, newurl):  # type: ignore[no-untyped-def]
        return None


def _scalar(value: Any) -> str:
    if isinstance(value, bool):
        return "true" if value else "false"
    return str(value)


def _quote_param(value: str) -> str:
    return value.replace('"', "%22").replace("\r", "%0D").replace("\n", "%0A")


class HttpClient:
    def __init__(
        self,
        base_url: str,
        token: str,
        timeout: float = 30.0,
        headers: Mapping[str, str] | None = None,
    ) -> None:
        if not isinstance(base_url, str) or not re.match(r"^https?://[^/\s]+", base_url):
            raise InvalidRequestError("base_url precisa ser http(s)://…/api/v1 da sua instalação.")
        if not isinstance(token, str) or not token or re.search(r"\s", token):
            raise InvalidRequestError("token vazio ou com espaço: use o texto exibido na criação da chave.")
        if timeout <= 0:
            raise InvalidRequestError("timeout precisa ser maior que zero.")
        self.base_url = base_url.rstrip("/")
        self.__token = token
        self.timeout = float(timeout)
        self.default_headers = self._check_headers(headers or {})
        self.user_agent = f"assinavelox-python/{SDK_VERSION} (api-v{API_MAJOR}; python/{platform.python_version()})"
        self._opener = urllib.request.build_opener(_NoRedirect)

    def __repr__(self) -> str:
        return f"HttpClient(base_url={self.base_url!r})"

    @staticmethod
    def _check_headers(headers: Mapping[str, str]) -> dict[str, str]:
        for name, value in headers.items():
            if _UNSAFE_HEADER.search(str(name)) or _UNSAFE_HEADER.search(str(value)):
                raise InvalidRequestError(f"Cabeçalho {name!r} com quebra de linha.")
        return {str(k): str(v) for k, v in headers.items()}

    def url(self, path: str, path_params: Mapping[str, Any], query: Iterable[tuple[str, Any, bool]]) -> str:
        for name, value in path_params.items():
            if not isinstance(value, str) or value == "":
                raise InvalidRequestError(f"Parâmetro {name!r} obrigatório (texto não vazio).")
            path = path.replace("{" + name + "}", urllib.parse.quote(value, safe=""))
        pairs: list[tuple[str, str]] = []
        for wire, value, repeat in query:
            if value is None:
                continue
            if repeat:
                values = [value] if isinstance(value, str) else list(value)
                pairs.extend((wire, _scalar(v)) for v in values)
            else:
                pairs.append((wire, _scalar(value)))
        suffix = ("?" + urllib.parse.urlencode(pairs)) if pairs else ""
        return self.base_url + path + suffix

    def request(
        self,
        method: str,
        path: str,
        *,
        path_params: Mapping[str, Any] | None = None,
        query: Iterable[tuple[str, Any, bool]] = (),
        json_body: Any = None,
        has_body: bool = False,
        files: Mapping[str, FileUpload] | None = None,
        idempotency: str | None = None,
        idempotency_key: str | None = None,
        timeout: float | None = None,
        headers: Mapping[str, str] | None = None,
        accept: str = "application/json",
    ) -> RawResponse:
        url = self.url(path, path_params or {}, query)
        final = dict(self.default_headers)
        final.update(self._check_headers(headers or {}))
        final["Authorization"] = f"Bearer {self.__token}"
        final["Accept"] = accept
        final["User-Agent"] = self.user_agent

        if idempotency_key is None and idempotency == "required":
            idempotency_key = str(uuid.uuid4())
        if idempotency_key is not None:
            if not IDEMPOTENCY_KEY.fullmatch(idempotency_key):
                raise InvalidRequestError("Idempotency-Key: de 1 a 255 caracteres ASCII visíveis, sem espaços.")
            final["Idempotency-Key"] = idempotency_key

        data: bytes | None = None
        if files is not None:
            data, content_type = self._multipart(files)
            final["Content-Type"] = content_type
        elif has_body and json_body is not None:
            data = json.dumps(json_body, ensure_ascii=False, separators=(",", ":")).encode("utf-8")
            final["Content-Type"] = "application/json"

        request = urllib.request.Request(url, data=data, method=method)
        for name, value in final.items():
            request.add_header(name, value)

        limit = self.timeout if timeout is None else float(timeout)
        try:
            with self._opener.open(request, timeout=limit) as response:
                status = int(response.status)
                response_headers = {k.lower(): v for k, v in response.headers.items()}
                body = response.read()
        except urllib.error.HTTPError as exc:
            status = int(exc.code)
            response_headers = {k.lower(): v for k, v in (exc.headers or {}).items()}
            try:
                body = exc.read()
            except OSError:
                body = b""
        except (socket.timeout, TimeoutError):
            raise RequestTimeoutError(f"Tempo esgotado ({limit:g} s) em {method} {path}.") from None
        except urllib.error.URLError as exc:
            if isinstance(exc.reason, (socket.timeout, TimeoutError)):
                raise RequestTimeoutError(f"Tempo esgotado ({limit:g} s) em {method} {path}.") from None
            raise NetworkError(f"Falha de conexão em {method} {path}: {exc.reason}") from None
        except OSError as exc:
            raise NetworkError(f"Falha de conexão em {method} {path}: {exc}") from None

        if status < 200 or status >= 300:
            raise ApiError.from_response(status, response_headers, body)
        return RawResponse(status, response_headers, body)

    @staticmethod
    def _multipart(files: Mapping[str, FileUpload]) -> tuple[bytes, str]:
        boundary = "AssinaVeloxSdk" + uuid.uuid4().hex
        chunks: list[bytes] = []
        for name, upload in files.items():
            if _UNSAFE_HEADER.search(upload.content_type):
                raise InvalidRequestError("content_type do arquivo com quebra de linha.")
            chunks.append(f"--{boundary}\r\n".encode("ascii"))
            chunks.append(
                (
                    f'Content-Disposition: form-data; name="{_quote_param(name)}"; '
                    f'filename="{_quote_param(upload.filename)}"\r\n'
                    f"Content-Type: {upload.content_type}\r\n\r\n"
                ).encode("utf-8")
            )
            chunks.append(bytes(upload.content))
            chunks.append(b"\r\n")
        chunks.append(f"--{boundary}--\r\n".encode("ascii"))
        return b"".join(chunks), f"multipart/form-data; boundary={boundary}"


def data_of(response: RawResponse) -> Any:
    body = response.json()
    return body.get("data") if isinstance(body, dict) else None


def meta_of(response: RawResponse) -> dict[str, Any]:
    body = response.json()
    meta = body.get("meta") if isinstance(body, dict) else None
    return meta if isinstance(meta, dict) else {}
