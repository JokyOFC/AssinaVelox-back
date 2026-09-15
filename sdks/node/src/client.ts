// Gerado por tools/sdkgen a partir de sdks/openapi/v1.json — não edite; rode python tools/sdkgen/sdkgen.py generate.

import { DownloadedFile, type FileUpload } from './files.js';
import {
    dataOf,
    HttpClient,
    jsonOf,
    metaOf,
    type ClientOptions,
    type RequestOptions,
} from './http.js';
import { Page, type ApiResult } from './page.js';
import type * as T from './types.js';

/**
 * Cliente da API v1 da AssinaVelox.
 *
 * ```ts
 * const client = new AssinaVelox({ baseUrl: 'https://sua-instalacao.example/api/v1', token: process.env.ASSINAVELOX_TOKEN! });
 * ```
 *
 * O token fica num campo privado (não aparece em `console.log`). Redirecionamentos
 * não são seguidos. Erros da API viram `ApiError` (RFC 9457).
 */
export class AssinaVelox {
    readonly #http: HttpClient;

    constructor(options: ClientOptions) {
        this.#http = new HttpClient(options);
    }

    get baseUrl(): string {
        return this.#http.baseUrl;
    }

    /**
     * Listar documentos — `GET /envelopes`.
     *
     * Paginada por cursor. `for await` percorre todas as páginas.
     */
    async listEnvelopes(
        query: T.ListEnvelopesQuery = {},
        options: RequestOptions = {},
    ): Promise<Page<T.Envelope>> {
        const response = await this.#http.request({
            method: 'GET',
            path: '/envelopes',
            query,
            querySpec: {
                per_page: ['per_page', false] as const,
                cursor: ['cursor', false] as const,
                status: ['status[]', true] as const,
                folder: ['folder', false] as const,
                q: ['q', false] as const,
                created_after: ['created_after', false] as const,
                created_before: ['created_before', false] as const,
                updated_after: ['updated_after', false] as const,
            },
            options,
        });
        return Page.fromJson<T.Envelope>(jsonOf(response), (cursor) =>
            this.listEnvelopes({ ...query, cursor }, options),
        );
    }

    /**
     * Criar rascunho — `POST /envelopes`.
     *
     * Idempotency-Key obrigatória: o SDK gera um UUID v4 se você não passar uma chave.
     */
    async createEnvelope(
        body: T.StoreEnvelopeRequest,
        options: RequestOptions = {},
    ): Promise<T.Envelope> {
        const response = await this.#http.request({
            method: 'POST',
            path: '/envelopes',
            json: body,
            hasBody: true,
            idempotency: 'required',
            options,
        });
        return dataOf(response) as T.Envelope;
    }

    /** Detalhar documento — `GET /envelopes/{envelope}`. */
    async getEnvelope(
        envelope: string,
        options: RequestOptions = {},
    ): Promise<T.Envelope> {
        const response = await this.#http.request({
            method: 'GET',
            path: '/envelopes/{envelope}',
            pathParams: { envelope: envelope },
            options,
        });
        return dataOf(response) as T.Envelope;
    }

    /**
     * Enviar arquivo — `POST /envelopes/{envelope}/documents`.
     *
     * Idempotency-Key obrigatória: o SDK gera um UUID v4 se você não passar uma chave.
     */
    async uploadDocument(
        envelope: string,
        file: FileUpload,
        options: RequestOptions = {},
    ): Promise<T.Document> {
        const response = await this.#http.request({
            method: 'POST',
            path: '/envelopes/{envelope}/documents',
            pathParams: { envelope: envelope },
            file,
            fileField: 'file',
            idempotency: 'required',
            options,
        });
        return dataOf(response) as T.Document;
    }

    /** Situação dos participantes — `GET /envelopes/{envelope}/recipients`. */
    async listRecipients(
        envelope: string,
        options: RequestOptions = {},
    ): Promise<T.Recipient[]> {
        const response = await this.#http.request({
            method: 'GET',
            path: '/envelopes/{envelope}/recipients',
            pathParams: { envelope: envelope },
            options,
        });
        return (dataOf(response) ?? []) as T.Recipient[];
    }

    /**
     * Definir participantes — `PUT /envelopes/{envelope}/recipients`.
     *
     * Aceita Idempotency-Key (opcional).
     */
    async syncRecipients(
        envelope: string,
        body: T.SyncEnvelopeRecipientsRequest,
        options: RequestOptions = {},
    ): Promise<T.Recipient[]> {
        const response = await this.#http.request({
            method: 'PUT',
            path: '/envelopes/{envelope}/recipients',
            pathParams: { envelope: envelope },
            json: body,
            hasBody: true,
            idempotency: 'optional',
            options,
        });
        return (dataOf(response) ?? []) as T.Recipient[];
    }

    /** Listar campos — `GET /envelopes/{envelope}/fields`. */
    async listFields(
        envelope: string,
        options: RequestOptions = {},
    ): Promise<T.Field[]> {
        const response = await this.#http.request({
            method: 'GET',
            path: '/envelopes/{envelope}/fields',
            pathParams: { envelope: envelope },
            options,
        });
        return (dataOf(response) ?? []) as T.Field[];
    }

    /**
     * Definir campos — `PUT /envelopes/{envelope}/fields`.
     *
     * Aceita Idempotency-Key (opcional).
     */
    async syncFields(
        envelope: string,
        body: T.SyncEnvelopeFieldsRequest,
        options: RequestOptions = {},
    ): Promise<T.Field[]> {
        const response = await this.#http.request({
            method: 'PUT',
            path: '/envelopes/{envelope}/fields',
            pathParams: { envelope: envelope },
            json: body,
            hasBody: true,
            idempotency: 'optional',
            options,
        });
        return (dataOf(response) ?? []) as T.Field[];
    }

    /**
     * Enviar para assinatura — `POST /envelopes/{envelope}/send`.
     *
     * Idempotency-Key obrigatória: o SDK gera um UUID v4 se você não passar uma chave.
     */
    async sendEnvelope(
        envelope: string,
        options: RequestOptions = {},
    ): Promise<ApiResult<T.Envelope>> {
        const response = await this.#http.request({
            method: 'POST',
            path: '/envelopes/{envelope}/send',
            pathParams: { envelope: envelope },
            idempotency: 'required',
            options,
        });
        return { data: dataOf(response) as T.Envelope, meta: metaOf(response) };
    }

    /**
     * Cancelar documento — `POST /envelopes/{envelope}/cancel`.
     *
     * Aceita Idempotency-Key (opcional).
     */
    async cancelEnvelope(
        envelope: string,
        body?: T.CancelEnvelopeRequest,
        options: RequestOptions = {},
    ): Promise<ApiResult<T.Envelope>> {
        const response = await this.#http.request({
            method: 'POST',
            path: '/envelopes/{envelope}/cancel',
            pathParams: { envelope: envelope },
            json: body,
            hasBody: true,
            idempotency: 'optional',
            options,
        });
        return { data: dataOf(response) as T.Envelope, meta: metaOf(response) };
    }

    /**
     * Baixar arquivo — `GET /envelopes/{envelope}/files/{type}`.
     *
     * Devolve os bytes do arquivo.
     */
    async downloadFile(
        envelope: string,
        type: 'original' | 'signed' | 'evidence',
        query: T.DownloadFileQuery = {},
        options: RequestOptions = {},
    ): Promise<DownloadedFile> {
        const response = await this.#http.request({
            method: 'GET',
            path: '/envelopes/{envelope}/files/{type}',
            pathParams: { envelope: envelope, type: type },
            query,
            querySpec: { document: ['document', false] as const },
            options,
            accept: '*/*',
        });
        return DownloadedFile.fromResponse(response.headers, response.body);
    }

    /**
     * Eventos da trilha — `GET /envelopes/{envelope}/events`.
     *
     * Paginada por cursor. `for await` percorre todas as páginas.
     */
    async listEvents(
        envelope: string,
        query: T.ListEventsQuery = {},
        options: RequestOptions = {},
    ): Promise<Page<T.Event>> {
        const response = await this.#http.request({
            method: 'GET',
            path: '/envelopes/{envelope}/events',
            pathParams: { envelope: envelope },
            query,
            querySpec: {
                per_page: ['per_page', false] as const,
                cursor: ['cursor', false] as const,
            },
            options,
        });
        return Page.fromJson<T.Event>(jsonOf(response), (cursor) =>
            this.listEvents(envelope, { ...query, cursor }, options),
        );
    }

    /** Registro de verificação — `GET /envelopes/{envelope}/verification`. */
    async getVerification(
        envelope: string,
        options: RequestOptions = {},
    ): Promise<Record<string, unknown>> {
        const response = await this.#http.request({
            method: 'GET',
            path: '/envelopes/{envelope}/verification',
            pathParams: { envelope: envelope },
            options,
        });
        return dataOf(response) as Record<string, unknown>;
    }

    /**
     * Listar modelos — `GET /templates`.
     *
     * Paginada por cursor. `for await` percorre todas as páginas.
     */
    async listTemplates(
        query: T.ListTemplatesQuery = {},
        options: RequestOptions = {},
    ): Promise<Page<T.Template>> {
        const response = await this.#http.request({
            method: 'GET',
            path: '/templates',
            query,
            querySpec: {
                per_page: ['per_page', false] as const,
                cursor: ['cursor', false] as const,
            },
            options,
        });
        return Page.fromJson<T.Template>(jsonOf(response), (cursor) =>
            this.listTemplates({ ...query, cursor }, options),
        );
    }

    /** Detalhar modelo — `GET /templates/{template}`. */
    async getTemplate(
        template: string,
        options: RequestOptions = {},
    ): Promise<T.Template> {
        const response = await this.#http.request({
            method: 'GET',
            path: '/templates/{template}',
            pathParams: { template: template },
            options,
        });
        return dataOf(response) as T.Template;
    }

    /**
     * Gerar documento a partir do modelo — `POST /templates/{template}/envelopes`.
     *
     * Idempotency-Key obrigatória: o SDK gera um UUID v4 se você não passar uma chave.
     */
    async createEnvelopeFromTemplate(
        template: string,
        body?: T.GenerateEnvelopeFromTemplateRequest,
        options: RequestOptions = {},
    ): Promise<T.Envelope> {
        const response = await this.#http.request({
            method: 'POST',
            path: '/templates/{template}/envelopes',
            pathParams: { template: template },
            json: body,
            hasBody: true,
            idempotency: 'required',
            options,
        });
        return dataOf(response) as T.Envelope;
    }

    /** Eventos que podem ser assinados (`*` = todos, inclusive os que forem criados depois) — `GET /webhook-events`. */
    async listWebhookEvents(options: RequestOptions = {}): Promise<unknown[]> {
        const response = await this.#http.request({
            method: 'GET',
            path: '/webhook-events',
            options,
        });
        return (dataOf(response) ?? []) as unknown[];
    }

    /** Payload de exemplo de um evento, no formato `{data: [payload]}` — lista com um item, que é o que os editores de gatilho esperam para mapear campos. Nenhum dado real — `GET /webhook-events/{event}/sample`. */
    async getWebhookEventSample(
        event: string,
        options: RequestOptions = {},
    ): Promise<ApiResult<unknown[]>> {
        const response = await this.#http.request({
            method: 'GET',
            path: '/webhook-events/{event}/sample',
            pathParams: { event: event },
            options,
        });
        return {
            data: (dataOf(response) ?? []) as unknown[],
            meta: metaOf(response),
        };
    }

    /** Assinaturas ativas deste token — `GET /webhook-subscriptions`. */
    async listWebhookSubscriptions(
        options: RequestOptions = {},
    ): Promise<ApiResult<T.WebhookSubscription[]>> {
        const response = await this.#http.request({
            method: 'GET',
            path: '/webhook-subscriptions',
            options,
        });
        return {
            data: (dataOf(response) ?? []) as T.WebhookSubscription[],
            meta: metaOf(response),
        };
    }

    /**
     * Assina um evento (ou vários) numa URL HTTPS pública — `POST /webhook-subscriptions`.
     *
     * Idempotency-Key obrigatória: o SDK gera um UUID v4 se você não passar uma chave.
     */
    async createWebhookSubscription(
        body: T.CreateWebhookSubscriptionRequest,
        options: RequestOptions = {},
    ): Promise<ApiResult<T.WebhookSubscription>> {
        const response = await this.#http.request({
            method: 'POST',
            path: '/webhook-subscriptions',
            json: body,
            hasBody: true,
            idempotency: 'required',
            options,
        });
        return {
            data: dataOf(response) as T.WebhookSubscription,
            meta: metaOf(response),
        };
    }

    /** Remove a assinatura (o "unsubscribe" do REST Hooks). 204; 404 se não for deste token — `DELETE /webhook-subscriptions/{subscription}`. */
    async deleteWebhookSubscription(
        subscription: string,
        options: RequestOptions = {},
    ): Promise<void> {
        await this.#http.request({
            method: 'DELETE',
            path: '/webhook-subscriptions/{subscription}',
            pathParams: { subscription: subscription },
            options,
        });
    }

    /**
     * Criar sessão de assinatura embutida — `POST /envelopes/{envelope}/recipients/{recipient}/embedded-sessions`.
     *
     * Idempotency-Key obrigatória: o SDK gera um UUID v4 se você não passar uma chave.
     */
    async createEmbeddedSession(
        envelope: string,
        recipient: string,
        body: T.StoreEmbeddedSigningSessionRequest,
        options: RequestOptions = {},
    ): Promise<T.EmbeddedSigningSession> {
        const response = await this.#http.request({
            method: 'POST',
            path: '/envelopes/{envelope}/recipients/{recipient}/embedded-sessions',
            pathParams: { envelope: envelope, recipient: recipient },
            json: body,
            hasBody: true,
            idempotency: 'required',
            options,
        });
        return dataOf(response) as T.EmbeddedSigningSession;
    }

    /** Situação da sessão embutida — `GET /envelopes/{envelope}/recipients/{recipient}/embedded-sessions/{embeddedSession}`. */
    async getEmbeddedSession(
        envelope: string,
        recipient: string,
        embeddedSession: string,
        options: RequestOptions = {},
    ): Promise<T.EmbeddedSigningSession> {
        const response = await this.#http.request({
            method: 'GET',
            path: '/envelopes/{envelope}/recipients/{recipient}/embedded-sessions/{embeddedSession}',
            pathParams: {
                envelope: envelope,
                recipient: recipient,
                embeddedSession: embeddedSession,
            },
            options,
        });
        return dataOf(response) as T.EmbeddedSigningSession;
    }

    /** Revogar sessão embutida — `DELETE /envelopes/{envelope}/recipients/{recipient}/embedded-sessions/{embeddedSession}`. */
    async revokeEmbeddedSession(
        envelope: string,
        recipient: string,
        embeddedSession: string,
        options: RequestOptions = {},
    ): Promise<T.EmbeddedSigningSession> {
        const response = await this.#http.request({
            method: 'DELETE',
            path: '/envelopes/{envelope}/recipients/{recipient}/embedded-sessions/{embeddedSession}',
            pathParams: {
                envelope: envelope,
                recipient: recipient,
                embeddedSession: embeddedSession,
            },
            options,
        });
        return dataOf(response) as T.EmbeddedSigningSession;
    }
}
