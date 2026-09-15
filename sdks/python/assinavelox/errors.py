"""Erros do SDK. Todos herdam de :class:`AssinaVeloxError`."""

from __future__ import annotations

import json
from typing import Any, Mapping

PROBLEM_PREFIX = "urn:assinavelox:problem:"


class AssinaVeloxError(Exception):
    """Base de todos os erros do SDK."""


class InvalidRequestError(AssinaVeloxError, ValueError):
    """O pedido foi recusado pelo próprio SDK, antes de sair (ex.: Idempotency-Key inválida)."""


class NetworkError(AssinaVeloxError):
    """Falha de rede: conexão recusada, DNS, TLS ou conexão interrompida."""


class RequestTimeoutError(NetworkError):
    """O tempo limite da requisição se esgotou."""


class WebhookSignatureError(AssinaVeloxError):
    """A assinatura do webhook não conferiu ou está fora da janela de tempo."""


class ApiError(AssinaVeloxError):
    """Resposta de erro da API (RFC 9457, `application/problem+json`).

    `type` é uma URN estável (`urn:assinavelox:problem:{slug}`): compare como texto. Um `type`
    desconhecido deve ser tratado pelo `status`. Quando a resposta não é RFC 9457 (ex.: um
    proxy devolveu HTML), `type` é `about:blank` e `title` é genérico.
    """

    def __init__(
        self,
        *,
        status: int,
        type: str,  # noqa: A002 - nome do campo na RFC 9457
        title: str,
        detail: str | None = None,
        instance: str | None = None,
        correlation_id: str | None = None,
        errors: Mapping[str, list[str]] | None = None,
        problem: Mapping[str, Any] | None = None,
        headers: Mapping[str, str] | None = None,
    ) -> None:
        self.status = status
        self.type = type
        self.title = title
        self.detail = detail
        self.instance = instance
        self.correlation_id = correlation_id
        self.errors: dict[str, list[str]] = dict(errors or {})
        self.problem: dict[str, Any] = dict(problem or {})
        self.headers: dict[str, str] = {k.lower(): v for k, v in (headers or {}).items()}
        super().__init__(self._message())

    def _message(self) -> str:
        text = f"{self.status} {self.title}"
        if self.detail:
            text += f": {self.detail}"
        text += f" ({self.type})"
        if self.correlation_id:
            text += f" [correlation_id {self.correlation_id}]"
        return text

    @property
    def slug(self) -> str | None:
        """Sufixo do `type` (`not-found`, `validation-failed`...), ou None fora do padrão."""
        return self.type[len(PROBLEM_PREFIX) :] if self.type.startswith(PROBLEM_PREFIX) else None

    def has_type(self, slug: str) -> bool:
        return self.slug == slug

    @property
    def retry_after(self) -> int | None:
        """Segundos do cabeçalho `Retry-After` (429, 409 em processamento, 503)."""
        value = self.headers.get("retry-after", "").strip()
        # `str.isdigit()` aceita dígitos Unicode ('²'), que `int()` recusa: só ASCII, como o
        # `ctype_digit` do SDK PHP e o `/^\d+$/` do SDK Node.
        return int(value) if value.isascii() and value.isdigit() else None

    @classmethod
    def from_response(cls, status: int, headers: Mapping[str, str], body: bytes) -> "ApiError":
        lowered = {k.lower(): v for k, v in headers.items()}
        problem: Any = None
        if body and "json" in lowered.get("content-type", ""):
            try:
                problem = json.loads(body.decode("utf-8"))
            except (UnicodeDecodeError, ValueError):
                problem = None
        if isinstance(problem, dict) and isinstance(problem.get("type"), str) and isinstance(problem.get("title"), str):
            errors = problem.get("errors")
            detail = problem.get("detail")
            instance = problem.get("instance")
            correlation = problem.get("correlation_id") or lowered.get("x-correlation-id")
            return cls(
                status=status,
                type=problem["type"],
                title=problem["title"],
                detail=detail if isinstance(detail, str) else None,
                instance=instance if isinstance(instance, str) else None,
                correlation_id=correlation if isinstance(correlation, str) else None,
                errors=errors if isinstance(errors, dict) else None,
                problem=problem,
                headers=lowered,
            )
        return cls(
            status=status,
            type="about:blank",
            title=f"Erro HTTP {status}",
            correlation_id=lowered.get("x-correlation-id"),
            headers=lowered,
        )
