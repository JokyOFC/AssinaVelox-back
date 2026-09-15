"""Cliente da API v1. Gerado por tools/sdkgen a partir de sdks/openapi/v1.json — não edite; rode python tools/sdkgen/sdkgen.py generate."""

from __future__ import annotations

from typing import Any, Literal, Mapping, Sequence

from . import models
from ._http import HttpClient, data_of, meta_of
from .files import DownloadedFile, FileUpload
from .pagination import ApiResult, Page


def _identity(item: Any) -> Any:
    return item


class AssinaVelox:
    """Cliente da API v1 da AssinaVelox.

    ``base_url`` é o endereço da sua instalação terminando em ``/api/v1``; ``token`` é o texto
    da chave criada em Integrações → Chaves (exibido uma única vez). O token nunca aparece em
    ``repr()`` nem em mensagens de erro. Redirecionamentos não são seguidos.
    """

    def __init__(
        self,
        *,
        base_url: str,
        token: str,
        timeout: float = 30.0,
        headers: Mapping[str, str] | None = None,
    ) -> None:
        self._http = HttpClient(base_url, token, timeout, headers)

    def __repr__(self) -> str:
        return f"AssinaVelox(base_url={self._http.base_url!r})"

    @property
    def base_url(self) -> str:
        return self._http.base_url

    def list_envelopes(
        self,
        *,
        per_page: int | None = None,
        cursor: str | None = None,
        status: Sequence[Literal["draft", "preparing", "ready", "in_progress", "finalizing", "completed", "refused", "expired", "canceled"]] | Literal["draft", "preparing", "ready", "in_progress", "finalizing", "completed", "refused", "expired", "canceled"] | None = None,
        folder: str | None = None,
        q: str | None = None,
        created_after: str | None = None,
        created_before: str | None = None,
        updated_after: str | None = None,
        timeout: float | None = None,
        headers: Mapping[str, str] | None = None,
    ) -> Page[models.Envelope]:
        """Listar documentos — ``GET /envelopes``.

        Paginada por cursor.

        Use ``.auto_paging_iter()`` para percorrer todas as páginas.
        """
        response = self._http.request(
            "GET",
            "/envelopes",
            query=[("per_page", per_page, False), ("cursor", cursor, False), ("status[]", status, True), ("folder", folder, False), ("q", q, False), ("created_after", created_after, False), ("created_before", created_before, False), ("updated_after", updated_after, False)],
            timeout=timeout,
            headers=headers,
        )

        def _next(cursor: str) -> Page[models.Envelope]:
            return self.list_envelopes(per_page=per_page, status=status, folder=folder, q=q, created_after=created_after, created_before=created_before, updated_after=updated_after, cursor=cursor, timeout=timeout, headers=headers)

        return Page.from_json(response.json(), models.Envelope.from_dict, _next)

    def create_envelope(
        self,
        body: models.StoreEnvelopeRequest,
        *,
        idempotency_key: str | None = None,
        timeout: float | None = None,
        headers: Mapping[str, str] | None = None,
    ) -> models.Envelope:
        """Criar rascunho — ``POST /envelopes``.

        Idempotency-Key obrigatória: o SDK gera um UUID v4 se você não passar uma chave.
        """
        response = self._http.request(
            "POST",
            "/envelopes",
            json_body=body,
            has_body=True,
            idempotency="required",
            idempotency_key=idempotency_key,
            timeout=timeout,
            headers=headers,
        )
        return models.Envelope.from_dict(data_of(response) or {})

    def get_envelope(
        self,
        envelope: str,
        *,
        timeout: float | None = None,
        headers: Mapping[str, str] | None = None,
    ) -> models.Envelope:
        """Detalhar documento — ``GET /envelopes/{envelope}``.
        """
        response = self._http.request(
            "GET",
            "/envelopes/{envelope}",
            path_params={"envelope": envelope},
            timeout=timeout,
            headers=headers,
        )
        return models.Envelope.from_dict(data_of(response) or {})

    def upload_document(
        self,
        envelope: str,
        file: FileUpload,
        *,
        idempotency_key: str | None = None,
        timeout: float | None = None,
        headers: Mapping[str, str] | None = None,
    ) -> models.Document:
        """Enviar arquivo — ``POST /envelopes/{envelope}/documents``.

        Idempotency-Key obrigatória: o SDK gera um UUID v4 se você não passar uma chave.
        """
        response = self._http.request(
            "POST",
            "/envelopes/{envelope}/documents",
            path_params={"envelope": envelope},
            files={"file": file},
            idempotency="required",
            idempotency_key=idempotency_key,
            timeout=timeout,
            headers=headers,
        )
        return models.Document.from_dict(data_of(response) or {})

    def list_recipients(
        self,
        envelope: str,
        *,
        timeout: float | None = None,
        headers: Mapping[str, str] | None = None,
    ) -> list[models.Recipient]:
        """Situação dos participantes — ``GET /envelopes/{envelope}/recipients``.
        """
        response = self._http.request(
            "GET",
            "/envelopes/{envelope}/recipients",
            path_params={"envelope": envelope},
            timeout=timeout,
            headers=headers,
        )
        return [models.Recipient.from_dict(item) for item in (data_of(response) or [])]

    def sync_recipients(
        self,
        envelope: str,
        body: models.SyncEnvelopeRecipientsRequest,
        *,
        idempotency_key: str | None = None,
        timeout: float | None = None,
        headers: Mapping[str, str] | None = None,
    ) -> list[models.Recipient]:
        """Definir participantes — ``PUT /envelopes/{envelope}/recipients``.

        Aceita Idempotency-Key (opcional).
        """
        response = self._http.request(
            "PUT",
            "/envelopes/{envelope}/recipients",
            path_params={"envelope": envelope},
            json_body=body,
            has_body=True,
            idempotency="optional",
            idempotency_key=idempotency_key,
            timeout=timeout,
            headers=headers,
        )
        return [models.Recipient.from_dict(item) for item in (data_of(response) or [])]

    def list_fields(
        self,
        envelope: str,
        *,
        timeout: float | None = None,
        headers: Mapping[str, str] | None = None,
    ) -> list[models.Field]:
        """Listar campos — ``GET /envelopes/{envelope}/fields``.
        """
        response = self._http.request(
            "GET",
            "/envelopes/{envelope}/fields",
            path_params={"envelope": envelope},
            timeout=timeout,
            headers=headers,
        )
        return [models.Field.from_dict(item) for item in (data_of(response) or [])]

    def sync_fields(
        self,
        envelope: str,
        body: models.SyncEnvelopeFieldsRequest,
        *,
        idempotency_key: str | None = None,
        timeout: float | None = None,
        headers: Mapping[str, str] | None = None,
    ) -> list[models.Field]:
        """Definir campos — ``PUT /envelopes/{envelope}/fields``.

        Aceita Idempotency-Key (opcional).
        """
        response = self._http.request(
            "PUT",
            "/envelopes/{envelope}/fields",
            path_params={"envelope": envelope},
            json_body=body,
            has_body=True,
            idempotency="optional",
            idempotency_key=idempotency_key,
            timeout=timeout,
            headers=headers,
        )
        return [models.Field.from_dict(item) for item in (data_of(response) or [])]

    def send_envelope(
        self,
        envelope: str,
        *,
        idempotency_key: str | None = None,
        timeout: float | None = None,
        headers: Mapping[str, str] | None = None,
    ) -> ApiResult[models.Envelope]:
        """Enviar para assinatura — ``POST /envelopes/{envelope}/send``.

        Idempotency-Key obrigatória: o SDK gera um UUID v4 se você não passar uma chave.
        """
        response = self._http.request(
            "POST",
            "/envelopes/{envelope}/send",
            path_params={"envelope": envelope},
            idempotency="required",
            idempotency_key=idempotency_key,
            timeout=timeout,
            headers=headers,
        )
        return ApiResult(data=models.Envelope.from_dict(data_of(response) or {}), meta=meta_of(response))

    def cancel_envelope(
        self,
        envelope: str,
        body: models.CancelEnvelopeRequest | None = None,
        *,
        idempotency_key: str | None = None,
        timeout: float | None = None,
        headers: Mapping[str, str] | None = None,
    ) -> ApiResult[models.Envelope]:
        """Cancelar documento — ``POST /envelopes/{envelope}/cancel``.

        Aceita Idempotency-Key (opcional).
        """
        response = self._http.request(
            "POST",
            "/envelopes/{envelope}/cancel",
            path_params={"envelope": envelope},
            json_body=body,
            has_body=True,
            idempotency="optional",
            idempotency_key=idempotency_key,
            timeout=timeout,
            headers=headers,
        )
        return ApiResult(data=models.Envelope.from_dict(data_of(response) or {}), meta=meta_of(response))

    def download_file(
        self,
        envelope: str,
        type: Literal["original", "signed", "evidence"],
        *,
        document: str | None = None,
        timeout: float | None = None,
        headers: Mapping[str, str] | None = None,
    ) -> DownloadedFile:
        """Baixar arquivo — ``GET /envelopes/{envelope}/files/{type}``.

        Devolve os bytes do arquivo.
        """
        response = self._http.request(
            "GET",
            "/envelopes/{envelope}/files/{type}",
            path_params={"envelope": envelope, "type": type},
            query=[("document", document, False)],
            timeout=timeout,
            headers=headers,
            accept="*/*",
        )
        return DownloadedFile.from_response(response.headers, response.body)

    def list_events(
        self,
        envelope: str,
        *,
        per_page: int | None = None,
        cursor: str | None = None,
        timeout: float | None = None,
        headers: Mapping[str, str] | None = None,
    ) -> Page[models.Event]:
        """Eventos da trilha — ``GET /envelopes/{envelope}/events``.

        Paginada por cursor.

        Use ``.auto_paging_iter()`` para percorrer todas as páginas.
        """
        response = self._http.request(
            "GET",
            "/envelopes/{envelope}/events",
            path_params={"envelope": envelope},
            query=[("per_page", per_page, False), ("cursor", cursor, False)],
            timeout=timeout,
            headers=headers,
        )

        def _next(cursor: str) -> Page[models.Event]:
            return self.list_events(envelope, per_page=per_page, cursor=cursor, timeout=timeout, headers=headers)

        return Page.from_json(response.json(), models.Event.from_dict, _next)

    def get_verification(
        self,
        envelope: str,
        *,
        timeout: float | None = None,
        headers: Mapping[str, str] | None = None,
    ) -> dict[str, Any]:
        """Registro de verificação — ``GET /envelopes/{envelope}/verification``.
        """
        response = self._http.request(
            "GET",
            "/envelopes/{envelope}/verification",
            path_params={"envelope": envelope},
            timeout=timeout,
            headers=headers,
        )
        return data_of(response)

    def list_templates(
        self,
        *,
        per_page: int | None = None,
        cursor: str | None = None,
        timeout: float | None = None,
        headers: Mapping[str, str] | None = None,
    ) -> Page[models.Template]:
        """Listar modelos — ``GET /templates``.

        Paginada por cursor.

        Use ``.auto_paging_iter()`` para percorrer todas as páginas.
        """
        response = self._http.request(
            "GET",
            "/templates",
            query=[("per_page", per_page, False), ("cursor", cursor, False)],
            timeout=timeout,
            headers=headers,
        )

        def _next(cursor: str) -> Page[models.Template]:
            return self.list_templates(per_page=per_page, cursor=cursor, timeout=timeout, headers=headers)

        return Page.from_json(response.json(), models.Template.from_dict, _next)

    def get_template(
        self,
        template: str,
        *,
        timeout: float | None = None,
        headers: Mapping[str, str] | None = None,
    ) -> models.Template:
        """Detalhar modelo — ``GET /templates/{template}``.
        """
        response = self._http.request(
            "GET",
            "/templates/{template}",
            path_params={"template": template},
            timeout=timeout,
            headers=headers,
        )
        return models.Template.from_dict(data_of(response) or {})

    def create_envelope_from_template(
        self,
        template: str,
        body: models.GenerateEnvelopeFromTemplateRequest | None = None,
        *,
        idempotency_key: str | None = None,
        timeout: float | None = None,
        headers: Mapping[str, str] | None = None,
    ) -> models.Envelope:
        """Gerar documento a partir do modelo — ``POST /templates/{template}/envelopes``.

        Idempotency-Key obrigatória: o SDK gera um UUID v4 se você não passar uma chave.
        """
        response = self._http.request(
            "POST",
            "/templates/{template}/envelopes",
            path_params={"template": template},
            json_body=body,
            has_body=True,
            idempotency="required",
            idempotency_key=idempotency_key,
            timeout=timeout,
            headers=headers,
        )
        return models.Envelope.from_dict(data_of(response) or {})

    def list_webhook_events(
        self,
        *,
        timeout: float | None = None,
        headers: Mapping[str, str] | None = None,
    ) -> list[Any]:
        """Eventos que podem ser assinados (`*` = todos, inclusive os que forem criados depois) — ``GET /webhook-events``.
        """
        response = self._http.request(
            "GET",
            "/webhook-events",
            timeout=timeout,
            headers=headers,
        )
        return list(data_of(response) or [])

    def get_webhook_event_sample(
        self,
        event: str,
        *,
        timeout: float | None = None,
        headers: Mapping[str, str] | None = None,
    ) -> ApiResult[list[Any]]:
        """Payload de exemplo de um evento, no formato `{data: [payload]}` — lista com um item, que é o que os editores de gatilho esperam para mapear campos. Nenhum dado real — ``GET /webhook-events/{event}/sample``.
        """
        response = self._http.request(
            "GET",
            "/webhook-events/{event}/sample",
            path_params={"event": event},
            timeout=timeout,
            headers=headers,
        )
        return ApiResult(data=list(data_of(response) or []), meta=meta_of(response))

    def list_webhook_subscriptions(
        self,
        *,
        timeout: float | None = None,
        headers: Mapping[str, str] | None = None,
    ) -> ApiResult[list[models.WebhookSubscription]]:
        """Assinaturas ativas deste token — ``GET /webhook-subscriptions``.
        """
        response = self._http.request(
            "GET",
            "/webhook-subscriptions",
            timeout=timeout,
            headers=headers,
        )
        return ApiResult(data=[models.WebhookSubscription.from_dict(item) for item in (data_of(response) or [])], meta=meta_of(response))

    def create_webhook_subscription(
        self,
        body: models.CreateWebhookSubscriptionRequest,
        *,
        idempotency_key: str | None = None,
        timeout: float | None = None,
        headers: Mapping[str, str] | None = None,
    ) -> ApiResult[models.WebhookSubscription]:
        """Assina um evento (ou vários) numa URL HTTPS pública — ``POST /webhook-subscriptions``.

        Idempotency-Key obrigatória: o SDK gera um UUID v4 se você não passar uma chave.
        """
        response = self._http.request(
            "POST",
            "/webhook-subscriptions",
            json_body=body,
            has_body=True,
            idempotency="required",
            idempotency_key=idempotency_key,
            timeout=timeout,
            headers=headers,
        )
        return ApiResult(data=models.WebhookSubscription.from_dict(data_of(response) or {}), meta=meta_of(response))

    def delete_webhook_subscription(
        self,
        subscription: str,
        *,
        timeout: float | None = None,
        headers: Mapping[str, str] | None = None,
    ) -> None:
        """Remove a assinatura (o "unsubscribe" do REST Hooks). 204; 404 se não for deste token — ``DELETE /webhook-subscriptions/{subscription}``.
        """
        response = self._http.request(
            "DELETE",
            "/webhook-subscriptions/{subscription}",
            path_params={"subscription": subscription},
            timeout=timeout,
            headers=headers,
        )
        return None

    def create_embedded_session(
        self,
        envelope: str,
        recipient: str,
        body: models.StoreEmbeddedSigningSessionRequest,
        *,
        idempotency_key: str | None = None,
        timeout: float | None = None,
        headers: Mapping[str, str] | None = None,
    ) -> models.EmbeddedSigningSession:
        """Criar sessão de assinatura embutida — ``POST /envelopes/{envelope}/recipients/{recipient}/embedded-sessions``.

        Idempotency-Key obrigatória: o SDK gera um UUID v4 se você não passar uma chave.
        """
        response = self._http.request(
            "POST",
            "/envelopes/{envelope}/recipients/{recipient}/embedded-sessions",
            path_params={"envelope": envelope, "recipient": recipient},
            json_body=body,
            has_body=True,
            idempotency="required",
            idempotency_key=idempotency_key,
            timeout=timeout,
            headers=headers,
        )
        return models.EmbeddedSigningSession.from_dict(data_of(response) or {})

    def get_embedded_session(
        self,
        envelope: str,
        recipient: str,
        embedded_session: str,
        *,
        timeout: float | None = None,
        headers: Mapping[str, str] | None = None,
    ) -> models.EmbeddedSigningSession:
        """Situação da sessão embutida — ``GET /envelopes/{envelope}/recipients/{recipient}/embedded-sessions/{embeddedSession}``.
        """
        response = self._http.request(
            "GET",
            "/envelopes/{envelope}/recipients/{recipient}/embedded-sessions/{embeddedSession}",
            path_params={"envelope": envelope, "recipient": recipient, "embeddedSession": embedded_session},
            timeout=timeout,
            headers=headers,
        )
        return models.EmbeddedSigningSession.from_dict(data_of(response) or {})

    def revoke_embedded_session(
        self,
        envelope: str,
        recipient: str,
        embedded_session: str,
        *,
        timeout: float | None = None,
        headers: Mapping[str, str] | None = None,
    ) -> models.EmbeddedSigningSession:
        """Revogar sessão embutida — ``DELETE /envelopes/{envelope}/recipients/{recipient}/embedded-sessions/{embeddedSession}``.
        """
        response = self._http.request(
            "DELETE",
            "/envelopes/{envelope}/recipients/{recipient}/embedded-sessions/{embeddedSession}",
            path_params={"envelope": envelope, "recipient": recipient, "embeddedSession": embedded_session},
            timeout=timeout,
            headers=headers,
        )
        return models.EmbeddedSigningSession.from_dict(data_of(response) or {})
