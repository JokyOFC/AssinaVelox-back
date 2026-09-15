"""Tipos da API v1. Gerado por tools/sdkgen a partir de sdks/openapi/v1.json — não edite; rode python tools/sdkgen/sdkgen.py generate.

Respostas: dataclasses imutáveis com `from_dict` (campos desconhecidos ficam em `raw`).
Corpos de pedido: TypedDict (dicionários comuns; `total=False`, veja "Obrigatórios").
"""

from __future__ import annotations

import dataclasses
from typing import Any, Literal, Mapping, TypedDict


def _obj(cls: Any, value: Any) -> Any:
    return cls.from_dict(value) if isinstance(value, Mapping) else None


def _list(cls: Any, value: Any) -> Any:
    if not isinstance(value, list):
        return None
    return [cls.from_dict(item) for item in value if isinstance(item, Mapping)]


def _map(cls: Any, value: Any) -> Any:
    if not isinstance(value, Mapping):
        return None
    return {key: cls.from_dict(item) for key, item in value.items() if isinstance(item, Mapping)}


@dataclasses.dataclass(frozen=True, kw_only=True)
class Document:
    """Document (API v1)."""

    created_at: str
    failure: DocumentFailure | None
    id: str
    name: str
    object: str
    original_filename: str
    pages: int | None
    position: int
    #: Valores conhecidos: "Enviado", "Convertendo…", "Pronto", "Falha ao processar", "Bloqueado" (a lista pode crescer).
    processing_label: str
    processing_status: str
    ready: bool
    sha256: DocumentSha256
    size_bytes: int | None
    source_type: str
    #: Resposta original, com campos que esta versão do SDK ainda não conhece.
    raw: dict[str, Any] = dataclasses.field(default_factory=dict, repr=False, compare=False)

    @classmethod
    def from_dict(cls, data: Mapping[str, Any]) -> "Document":
        return cls(
            created_at=data.get("created_at"),
            failure=_obj(DocumentFailure, data.get("failure")),
            id=data.get("id"),
            name=data.get("name"),
            object=data.get("object"),
            original_filename=data.get("original_filename"),
            pages=data.get("pages"),
            position=data.get("position"),
            processing_label=data.get("processing_label"),
            processing_status=data.get("processing_status"),
            ready=data.get("ready"),
            sha256=_obj(DocumentSha256, data.get("sha256")),
            size_bytes=data.get("size_bytes"),
            source_type=data.get("source_type"),
            raw=dict(data),
        )


@dataclasses.dataclass(frozen=True, kw_only=True)
class DocumentFailure:
    """DocumentFailure (API v1)."""

    code: str | None
    message: str | None
    #: Resposta original, com campos que esta versão do SDK ainda não conhece.
    raw: dict[str, Any] = dataclasses.field(default_factory=dict, repr=False, compare=False)

    @classmethod
    def from_dict(cls, data: Mapping[str, Any]) -> "DocumentFailure":
        return cls(
            code=data.get("code"),
            message=data.get("message"),
            raw=dict(data),
        )


@dataclasses.dataclass(frozen=True, kw_only=True)
class DocumentSha256:
    """DocumentSha256 (API v1)."""

    final: str | None
    original: str | None
    sent: str | None
    #: Resposta original, com campos que esta versão do SDK ainda não conhece.
    raw: dict[str, Any] = dataclasses.field(default_factory=dict, repr=False, compare=False)

    @classmethod
    def from_dict(cls, data: Mapping[str, Any]) -> "DocumentSha256":
        return cls(
            final=data.get("final"),
            original=data.get("original"),
            sent=data.get("sent"),
            raw=dict(data),
        )


@dataclasses.dataclass(frozen=True, kw_only=True)
class EmbeddedSigningSession:
    """Sessão de assinatura embutida (widget, docs/fase-3/widget-embutido.md). `url` (uso único, com o token no fragmento) só vem na criação."""

    id: str
    object: str
    envelope_id: str | None
    recipient_id: str | None
    origin: str
    #: Valores conhecidos: "pending", "active", "completed", "refused", "expired", "revoked", "closed" (a lista pode crescer).
    status: str
    expires_at: str | None
    used_at: str | None
    completed_at: str | None
    revoked_at: str | None
    created_at: str | None
    #: Endereço de uso único para o iframe; só na criação.
    url: str | None = None
    #: Resposta original, com campos que esta versão do SDK ainda não conhece.
    raw: dict[str, Any] = dataclasses.field(default_factory=dict, repr=False, compare=False)

    @classmethod
    def from_dict(cls, data: Mapping[str, Any]) -> "EmbeddedSigningSession":
        return cls(
            id=data.get("id"),
            object=data.get("object"),
            envelope_id=data.get("envelope_id"),
            recipient_id=data.get("recipient_id"),
            origin=data.get("origin"),
            status=data.get("status"),
            expires_at=data.get("expires_at"),
            used_at=data.get("used_at"),
            completed_at=data.get("completed_at"),
            revoked_at=data.get("revoked_at"),
            created_at=data.get("created_at"),
            url=data.get("url"),
            raw=dict(data),
        )


@dataclasses.dataclass(frozen=True, kw_only=True)
class Envelope:
    """Envelope (API v1)."""

    #: Só no detalhe.
    cancel_reason: str | None = None
    canceled_at: str | None
    completed_at: str | None
    created_at: str
    created_by: EnvelopeCreatedBy
    display_code: str
    #: Só no detalhe.
    documents: list[Document] | None = None
    #: Só no detalhe.
    expiration_days: int | None = None
    expired_at: str | None
    expires_at: str | None
    folder: EnvelopeFolder | None
    id: str
    #: Só no detalhe.
    links: EnvelopeLinks | None = None
    #: Só no detalhe.
    message: str | None = None
    object: str
    #: Só no detalhe.
    recipients: list[Recipient] | None = None
    recipients_count: int
    refused_at: str | None
    #: Só no detalhe.
    send_copy_to_all: bool | None = None
    sent_at: str | None
    signature_status: str | None
    signature_status_label: str | None
    signed_count: int
    signing_order: str
    status: str
    #: Valores conhecidos: "Assinado", "Concluído", "Rascunho", "Rascunho · processando", "Aguardando", "Em andamento · finalizando", "Recusado", "Expirado", "Cancelado", "Em andamento" (a lista pode crescer).
    status_label: str
    title: str
    updated_at: str
    verification_code: str | None
    viewers_count: int
    #: Resposta original, com campos que esta versão do SDK ainda não conhece.
    raw: dict[str, Any] = dataclasses.field(default_factory=dict, repr=False, compare=False)

    @classmethod
    def from_dict(cls, data: Mapping[str, Any]) -> "Envelope":
        return cls(
            cancel_reason=data.get("cancel_reason"),
            canceled_at=data.get("canceled_at"),
            completed_at=data.get("completed_at"),
            created_at=data.get("created_at"),
            created_by=_obj(EnvelopeCreatedBy, data.get("created_by")),
            display_code=data.get("display_code"),
            documents=_list(Document, data.get("documents")),
            expiration_days=data.get("expiration_days"),
            expired_at=data.get("expired_at"),
            expires_at=data.get("expires_at"),
            folder=_obj(EnvelopeFolder, data.get("folder")),
            id=data.get("id"),
            links=_obj(EnvelopeLinks, data.get("links")),
            message=data.get("message"),
            object=data.get("object"),
            recipients=_list(Recipient, data.get("recipients")),
            recipients_count=data.get("recipients_count"),
            refused_at=data.get("refused_at"),
            send_copy_to_all=data.get("send_copy_to_all"),
            sent_at=data.get("sent_at"),
            signature_status=data.get("signature_status"),
            signature_status_label=data.get("signature_status_label"),
            signed_count=data.get("signed_count"),
            signing_order=data.get("signing_order"),
            status=data.get("status"),
            status_label=data.get("status_label"),
            title=data.get("title"),
            updated_at=data.get("updated_at"),
            verification_code=data.get("verification_code"),
            viewers_count=data.get("viewers_count"),
            raw=dict(data),
        )


@dataclasses.dataclass(frozen=True, kw_only=True)
class EnvelopeCreatedBy:
    """EnvelopeCreatedBy (API v1)."""

    name: str
    #: Resposta original, com campos que esta versão do SDK ainda não conhece.
    raw: dict[str, Any] = dataclasses.field(default_factory=dict, repr=False, compare=False)

    @classmethod
    def from_dict(cls, data: Mapping[str, Any]) -> "EnvelopeCreatedBy":
        return cls(
            name=data.get("name"),
            raw=dict(data),
        )


@dataclasses.dataclass(frozen=True, kw_only=True)
class EnvelopeFolder:
    """EnvelopeFolder (API v1)."""

    id: str
    name: str
    #: Resposta original, com campos que esta versão do SDK ainda não conhece.
    raw: dict[str, Any] = dataclasses.field(default_factory=dict, repr=False, compare=False)

    @classmethod
    def from_dict(cls, data: Mapping[str, Any]) -> "EnvelopeFolder":
        return cls(
            id=data.get("id"),
            name=data.get("name"),
            raw=dict(data),
        )


@dataclasses.dataclass(frozen=True, kw_only=True)
class EnvelopeLinks:
    """Só no detalhe."""

    events: str
    evidence_file: str | None
    fields: str
    original_file: str
    recipients: str
    self: str
    signed_file: str | None
    verification: str | None
    #: Resposta original, com campos que esta versão do SDK ainda não conhece.
    raw: dict[str, Any] = dataclasses.field(default_factory=dict, repr=False, compare=False)

    @classmethod
    def from_dict(cls, data: Mapping[str, Any]) -> "EnvelopeLinks":
        return cls(
            events=data.get("events"),
            evidence_file=data.get("evidence_file"),
            fields=data.get("fields"),
            original_file=data.get("original_file"),
            recipients=data.get("recipients"),
            self=data.get("self"),
            signed_file=data.get("signed_file"),
            verification=data.get("verification"),
            raw=dict(data),
        )


@dataclasses.dataclass(frozen=True, kw_only=True)
class Event:
    """Event (API v1)."""

    actor: EventActor
    id: str
    #: Valores conhecidos: "ok", "warn", "info" (a lista pode crescer).
    kind: str
    #: Rótulo em PT-BR do tipo; a lista cresce com a trilha — não é enumeração fechada.
    label: str
    object: str
    occurred_at: str
    recipient_id: str | None
    type: str
    #: Resposta original, com campos que esta versão do SDK ainda não conhece.
    raw: dict[str, Any] = dataclasses.field(default_factory=dict, repr=False, compare=False)

    @classmethod
    def from_dict(cls, data: Mapping[str, Any]) -> "Event":
        return cls(
            actor=_obj(EventActor, data.get("actor")),
            id=data.get("id"),
            kind=data.get("kind"),
            label=data.get("label"),
            object=data.get("object"),
            occurred_at=data.get("occurred_at"),
            recipient_id=data.get("recipient_id"),
            type=data.get("type"),
            raw=dict(data),
        )


@dataclasses.dataclass(frozen=True, kw_only=True)
class EventActor:
    """EventActor (API v1)."""

    name: str | None
    type: str
    #: Resposta original, com campos que esta versão do SDK ainda não conhece.
    raw: dict[str, Any] = dataclasses.field(default_factory=dict, repr=False, compare=False)

    @classmethod
    def from_dict(cls, data: Mapping[str, Any]) -> "EventActor":
        return cls(
            name=data.get("name"),
            type=data.get("type"),
            raw=dict(data),
        )


@dataclasses.dataclass(frozen=True, kw_only=True)
class Field:
    """Field (API v1)."""

    auto: bool
    document_id: str | None
    h: float
    id: str
    label: str | None
    object: str
    page: int
    recipient_id: str | None
    required: bool
    type: str
    w: float
    x: float
    y: float
    #: Resposta original, com campos que esta versão do SDK ainda não conhece.
    raw: dict[str, Any] = dataclasses.field(default_factory=dict, repr=False, compare=False)

    @classmethod
    def from_dict(cls, data: Mapping[str, Any]) -> "Field":
        return cls(
            auto=data.get("auto"),
            document_id=data.get("document_id"),
            h=data.get("h"),
            id=data.get("id"),
            label=data.get("label"),
            object=data.get("object"),
            page=data.get("page"),
            recipient_id=data.get("recipient_id"),
            required=data.get("required"),
            type=data.get("type"),
            w=data.get("w"),
            x=data.get("x"),
            y=data.get("y"),
            raw=dict(data),
        )


@dataclasses.dataclass(frozen=True, kw_only=True)
class Recipient:
    """Recipient (API v1)."""

    auth_method: str | None
    email: str | None
    id: str
    label: str | None
    name: str | None
    notifications_count: int
    notified_at: str | None
    object: str
    order: int
    phone_masked: str | None
    refusal_reason: str | None
    refused_at: str | None
    role: str
    #: Valores conhecidos: "Signatário", "Testemunha", "Aprovador", "Visualizador" (a lista pode crescer).
    role_label: str
    signed_at: str | None
    status: str
    #: Valores conhecidos: "Aprovado", "Pendente", "Assinado", "Recusado", "Expirado", "Cancelado", "Delegado" (a lista pode crescer).
    status_label: str
    #: Resposta original, com campos que esta versão do SDK ainda não conhece.
    raw: dict[str, Any] = dataclasses.field(default_factory=dict, repr=False, compare=False)

    @classmethod
    def from_dict(cls, data: Mapping[str, Any]) -> "Recipient":
        return cls(
            auth_method=data.get("auth_method"),
            email=data.get("email"),
            id=data.get("id"),
            label=data.get("label"),
            name=data.get("name"),
            notifications_count=data.get("notifications_count"),
            notified_at=data.get("notified_at"),
            object=data.get("object"),
            order=data.get("order"),
            phone_masked=data.get("phone_masked"),
            refusal_reason=data.get("refusal_reason"),
            refused_at=data.get("refused_at"),
            role=data.get("role"),
            role_label=data.get("role_label"),
            signed_at=data.get("signed_at"),
            status=data.get("status"),
            status_label=data.get("status_label"),
            raw=dict(data),
        )


@dataclasses.dataclass(frozen=True, kw_only=True)
class Template:
    """Template (API v1)."""

    category: str | None
    description: str | None
    id: str
    name: str
    object: str
    #: Só no detalhe.
    roles: list[TemplateRole] | None = None
    source_type: str
    status: str
    updated_at: str
    usable: bool
    #: Só no detalhe.
    variables: list[TemplateVariable] | None = None
    version: int | None
    #: Resposta original, com campos que esta versão do SDK ainda não conhece.
    raw: dict[str, Any] = dataclasses.field(default_factory=dict, repr=False, compare=False)

    @classmethod
    def from_dict(cls, data: Mapping[str, Any]) -> "Template":
        return cls(
            category=data.get("category"),
            description=data.get("description"),
            id=data.get("id"),
            name=data.get("name"),
            object=data.get("object"),
            roles=_list(TemplateRole, data.get("roles")),
            source_type=data.get("source_type"),
            status=data.get("status"),
            updated_at=data.get("updated_at"),
            usable=data.get("usable"),
            variables=_list(TemplateVariable, data.get("variables")),
            version=data.get("version"),
            raw=dict(data),
        )


@dataclasses.dataclass(frozen=True, kw_only=True)
class TemplateRole:
    """TemplateRole (API v1)."""

    id: str
    name: str
    participant_role: str
    participant_role_label: str
    #: Resposta original, com campos que esta versão do SDK ainda não conhece.
    raw: dict[str, Any] = dataclasses.field(default_factory=dict, repr=False, compare=False)

    @classmethod
    def from_dict(cls, data: Mapping[str, Any]) -> "TemplateRole":
        return cls(
            id=data.get("id"),
            name=data.get("name"),
            participant_role=data.get("participant_role"),
            participant_role_label=data.get("participant_role_label"),
            raw=dict(data),
        )


@dataclasses.dataclass(frozen=True, kw_only=True)
class TemplateVariable:
    """TemplateVariable (API v1)."""

    default_value: str | None
    help_text: str | None
    key: str
    label: str
    options: Any
    required: bool
    type: str
    #: Resposta original, com campos que esta versão do SDK ainda não conhece.
    raw: dict[str, Any] = dataclasses.field(default_factory=dict, repr=False, compare=False)

    @classmethod
    def from_dict(cls, data: Mapping[str, Any]) -> "TemplateVariable":
        return cls(
            default_value=data.get("default_value"),
            help_text=data.get("help_text"),
            key=data.get("key"),
            label=data.get("label"),
            options=data.get("options"),
            required=data.get("required"),
            type=data.get("type"),
            raw=dict(data),
        )


@dataclasses.dataclass(frozen=True, kw_only=True)
class WebhookSubscription:
    """Assinatura de REST Hook (um endpoint de webhook ligado ao token que a criou). `secret` só vem na criação (201); nas listagens é null e, quando a assinatura já existia (200), não vem."""

    id: str
    object: str
    target_url: str
    events: list[str]
    #: Valores conhecidos: "active", "paused" (a lista pode crescer).
    status: str
    status_label: str
    paused_reason: str | None
    secret_hint: str
    signature_header: str
    created_at: str
    secret: str | None = None
    #: Resposta original, com campos que esta versão do SDK ainda não conhece.
    raw: dict[str, Any] = dataclasses.field(default_factory=dict, repr=False, compare=False)

    @classmethod
    def from_dict(cls, data: Mapping[str, Any]) -> "WebhookSubscription":
        return cls(
            id=data.get("id"),
            object=data.get("object"),
            target_url=data.get("target_url"),
            events=data.get("events"),
            status=data.get("status"),
            status_label=data.get("status_label"),
            paused_reason=data.get("paused_reason"),
            secret_hint=data.get("secret_hint"),
            signature_header=data.get("signature_header"),
            created_at=data.get("created_at"),
            secret=data.get("secret"),
            raw=dict(data),
        )


class CancelEnvelopeRequest(TypedDict, total=False):
    """POST /api/v1/envelopes/{envelope}/cancel — motivo opcional (o mesmo campo da interface)."""

    reason: str | None


class CreateWebhookSubscriptionRequest(TypedDict, total=False):
    """Corpo CreateWebhookSubscriptionRequest. Obrigatórios: target_url."""

    event: Literal["*", "envelope.sent", "recipient.viewed", "recipient.signed", "recipient.approved", "recipient.refused", "envelope.refused", "envelope.completed", "envelope.expired", "envelope.canceled", "document.processing_failed"] | None
    events: list[Literal["*", "envelope.sent", "recipient.viewed", "recipient.signed", "recipient.approved", "recipient.refused", "envelope.refused", "envelope.completed", "envelope.expired", "envelope.canceled", "document.processing_failed"]] | None
    target_url: str


class GenerateEnvelopeFromTemplateRequest(TypedDict, total=False):
    """POST /api/v1/templates/{template}/envelopes — o mesmo corpo de "Usar modelo" na interface:"""

    participants: dict[str, GenerateEnvelopeFromTemplateRequestParticipant] | None
    title: str | None
    values: dict[str, str | float | bool | None] | None


class GenerateEnvelopeFromTemplateRequestParticipant(TypedDict, total=False):
    """Corpo GenerateEnvelopeFromTemplateRequestParticipant. Obrigatórios: email, name."""

    email: str
    name: str


class StoreEmbeddedSigningSessionRequest(TypedDict, total=False):
    """Corpo de `POST /api/v1/envelopes/{envelope}/recipients/{recipient}/embedded-sessions` Obrigatórios: origin."""

    #: Validade da URL de uso único, em segundos (60 a 900; padrão 300).
    expires_in: int | None
    #: Origem exata do site que hospeda o widget, ex.: `https://app.cliente.com.br`.
    origin: str


class StoreEnvelopeRequest(TypedDict, total=False):
    """POST /api/v1/envelopes — rascunho novo, com as mesmas regras de formato do passo 1 do Obrigatórios: title."""

    expires_in_days: int | None
    folder_id: str | None
    message: str | None
    send_copy_to_all: bool | None
    signing_order: Literal["sequential", "parallel"] | None
    title: str


class SyncEnvelopeFieldsRequest(TypedDict, total=False):
    """PUT /api/v1/envelopes/{envelope}/fields — substitui os campos posicionados. Obrigatórios: fields."""

    fields: list[SyncEnvelopeFieldsRequestField]
    initials_on_all_pages: bool | None


class SyncEnvelopeFieldsRequestField(TypedDict, total=False):
    """Corpo SyncEnvelopeFieldsRequestField. Obrigatórios: h, page, type, w, x, y."""

    auto: bool | None
    #: Fase 2 §2.3: ULID do documento onde o campo fica. Ausente = primeiro documento.
    document_id: str | None
    h: float
    id: str | None
    label: str | None
    options: SyncEnvelopeFieldsRequestFieldOptions | None
    page: int
    placeholder: str | None
    recipient_client_id: str | None
    recipient_id: str | None
    required: bool | None
    type: Literal["signature", "initials", "name", "date", "text", "checkbox", "cpf", "stamp"]
    w: float
    x: float
    y: float


class SyncEnvelopeFieldsRequestFieldOptions(TypedDict, total=False):
    """Corpo SyncEnvelopeFieldsRequestFieldOptions."""

    date_format: str | None
    default: bool | None
    font_size: float | None
    placeholder: str | None


class SyncEnvelopeRecipientsRequest(TypedDict, total=False):
    """PUT /api/v1/envelopes/{envelope}/recipients — substitui a lista inteira de participantes. Obrigatórios: recipients, signing_order."""

    recipients: list[SyncEnvelopeRecipientsRequestRecipient]
    signing_order: Literal["sequential", "parallel"]


class SyncEnvelopeRecipientsRequestRecipient(TypedDict, total=False):
    """Corpo SyncEnvelopeRecipientsRequestRecipient. Obrigatórios: email, name."""

    auth_method: Literal["email_otp", "sms_otp", "whatsapp_otp"] | None
    channel: Literal["email", "sms", "whatsapp"] | None
    email: str
    id: str | None
    name: str
    order: int | None
    #: Fase 2 §2.4: papel de domínio (o `role` acima é o rótulo livre). A flag
    participant_role: Literal["signer", "witness", "approver", "viewer"] | None
    #: Fase 2 §2.9 (C-CAN): telefone, canal do convite, método de autenticação e PIN.
    phone: str | None
    pin: str | None
    remove_pin: bool | None
    role: str | None


__all__ = [
    "CancelEnvelopeRequest",
    "CreateWebhookSubscriptionRequest",
    "Document",
    "DocumentFailure",
    "DocumentSha256",
    "EmbeddedSigningSession",
    "Envelope",
    "EnvelopeCreatedBy",
    "EnvelopeFolder",
    "EnvelopeLinks",
    "Event",
    "EventActor",
    "Field",
    "GenerateEnvelopeFromTemplateRequest",
    "GenerateEnvelopeFromTemplateRequestParticipant",
    "Recipient",
    "StoreEmbeddedSigningSessionRequest",
    "StoreEnvelopeRequest",
    "SyncEnvelopeFieldsRequest",
    "SyncEnvelopeFieldsRequestField",
    "SyncEnvelopeFieldsRequestFieldOptions",
    "SyncEnvelopeRecipientsRequest",
    "SyncEnvelopeRecipientsRequestRecipient",
    "Template",
    "TemplateRole",
    "TemplateVariable",
    "WebhookSubscription",
]
