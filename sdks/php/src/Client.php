<?php

declare(strict_types=1);

namespace AssinaVelox\Sdk;

use AssinaVelox\Sdk\Http\CurlTransport;
use AssinaVelox\Sdk\Http\HttpClient;
use AssinaVelox\Sdk\Http\StreamTransport;
use AssinaVelox\Sdk\Http\Transport;

/**
 * Cliente da API v1 da AssinaVelox.
 *
 *     $client = new Client('https://sua-instalacao.example/api/v1', getenv('ASSINAVELOX_TOKEN'));
 *
 * O token nunca aparece em var_dump()/print_r() nem em mensagens de erro. Redirecionamentos
 * não são seguidos. Erros da API viram Exception\ApiException (RFC 9457).
 *
 * Gerado por tools/sdkgen a partir de sdks/openapi/v1.json — não edite; rode python tools/sdkgen/sdkgen.py generate.
 */
final class Client
{
    private readonly HttpClient $http;

    /**
     * @param  string  $baseUrl  Endereço da sua instalação, terminando em /api/v1.
     * @param  string  $token  Texto da chave criada em Integrações → Chaves (exibido uma única vez).
     * @param  float  $timeout  Tempo máximo de cada requisição, em segundos.
     * @param  array<string, string>  $headers  Cabeçalhos extras em todas as requisições.
     * @param  Transport|null  $transport  Padrão: curl, se a extensão existir; senão, streams.
     */
    public function __construct(
        string $baseUrl,
        #[\SensitiveParameter] string $token,
        float $timeout = 30.0,
        array $headers = [],
        ?Transport $transport = null,
    ) {
        $this->http = new HttpClient($baseUrl, $token, $timeout, $headers, $transport ?? (extension_loaded('curl') ? new CurlTransport : new StreamTransport));
    }

    public function baseUrl(): string
    {
        return $this->http->baseUrl();
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['baseUrl' => $this->http->baseUrl()];
    }

    /**
     * Listar documentos — GET /envelopes.
     *
     * Paginada por cursor. Use autoPagingIterator() para percorrer todas as páginas.
     *
     * @param  array{per_page?: int|null, cursor?: string|null, status?: list<'draft'|'preparing'|'ready'|'in_progress'|'finalizing'|'completed'|'refused'|'expired'|'canceled'>|'draft'|'preparing'|'ready'|'in_progress'|'finalizing'|'completed'|'refused'|'expired'|'canceled'|null, folder?: string|null, q?: string|null, created_after?: string|null, created_before?: string|null, updated_after?: string|null}  $query
     * @return Page<Model\Envelope>
     */
    public function listEnvelopes(array $query = [], ?RequestOptions $options = null): Page
    {
        $response = $this->http->request(
            method: 'GET',
            path: '/envelopes',
            query: $query,
            querySpec: ['per_page' => ['per_page', false], 'cursor' => ['cursor', false], 'status' => ['status[]', true], 'folder' => ['folder', false], 'q' => ['q', false], 'created_after' => ['created_after', false], 'created_before' => ['created_before', false], 'updated_after' => ['updated_after', false]],
            options: $options,
        );

        return Page::fromResponse(
            $response->json(),
            static fn (array $item): Model\Envelope => Model\Envelope::fromArray($item),
            fn (string $cursor): Page => $this->listEnvelopes(['cursor' => $cursor] + $query, $options),
        );
    }

    /**
     * Criar rascunho — POST /envelopes.
     *
     * Idempotency-Key obrigatória: o SDK gera um UUID v4 se você não passar uma chave.
     *
     * @param  array{expires_in_days?: int|null, folder_id?: string|null, message?: string|null, send_copy_to_all?: bool|null, signing_order?: 'sequential'|'parallel'|null, title: string}|object  $body
     */
    public function createEnvelope(array|object $body, ?RequestOptions $options = null): Model\Envelope
    {
        $response = $this->http->request(
            method: 'POST',
            path: '/envelopes',
            json: $body,
            hasBody: true,
            idempotency: 'required',
            options: $options,
        );

        return Model\Envelope::fromArray(self::object($response->data()));
    }

    /**
     * Detalhar documento — GET /envelopes/{envelope}.
     */
    public function getEnvelope(string $envelope, ?RequestOptions $options = null): Model\Envelope
    {
        $response = $this->http->request(
            method: 'GET',
            path: '/envelopes/{envelope}',
            pathParams: ['envelope' => $envelope],
            options: $options,
        );

        return Model\Envelope::fromArray(self::object($response->data()));
    }

    /**
     * Enviar arquivo — POST /envelopes/{envelope}/documents.
     *
     * Idempotency-Key obrigatória: o SDK gera um UUID v4 se você não passar uma chave.
     */
    public function uploadDocument(string $envelope, FileUpload $file, ?RequestOptions $options = null): Model\Document
    {
        $response = $this->http->request(
            method: 'POST',
            path: '/envelopes/{envelope}/documents',
            pathParams: ['envelope' => $envelope],
            file: $file,
            fileField: 'file',
            idempotency: 'required',
            options: $options,
        );

        return Model\Document::fromArray(self::object($response->data()));
    }

    /**
     * Situação dos participantes — GET /envelopes/{envelope}/recipients.
     *
     * @return list<Model\Recipient>
     */
    public function listRecipients(string $envelope, ?RequestOptions $options = null): array
    {
        $response = $this->http->request(
            method: 'GET',
            path: '/envelopes/{envelope}/recipients',
            pathParams: ['envelope' => $envelope],
            options: $options,
        );

        return Model\Hydrator::listOf(Model\Recipient::class, self::items($response->data()));
    }

    /**
     * Definir participantes — PUT /envelopes/{envelope}/recipients.
     *
     * Aceita Idempotency-Key (opcional).
     *
     * @param  array{recipients: list<array{auth_method?: 'email_otp'|'sms_otp'|'whatsapp_otp'|null, channel?: 'email'|'sms'|'whatsapp'|null, email: string, id?: string|null, name: string, order?: int|null, participant_role?: 'signer'|'witness'|'approver'|'viewer'|null, phone?: string|null, pin?: string|null, remove_pin?: bool|null, role?: string|null}>, signing_order: 'sequential'|'parallel'}|object  $body
     * @return list<Model\Recipient>
     */
    public function syncRecipients(string $envelope, array|object $body, ?RequestOptions $options = null): array
    {
        $response = $this->http->request(
            method: 'PUT',
            path: '/envelopes/{envelope}/recipients',
            pathParams: ['envelope' => $envelope],
            json: $body,
            hasBody: true,
            idempotency: 'optional',
            options: $options,
        );

        return Model\Hydrator::listOf(Model\Recipient::class, self::items($response->data()));
    }

    /**
     * Listar campos — GET /envelopes/{envelope}/fields.
     *
     * @return list<Model\Field>
     */
    public function listFields(string $envelope, ?RequestOptions $options = null): array
    {
        $response = $this->http->request(
            method: 'GET',
            path: '/envelopes/{envelope}/fields',
            pathParams: ['envelope' => $envelope],
            options: $options,
        );

        return Model\Hydrator::listOf(Model\Field::class, self::items($response->data()));
    }

    /**
     * Definir campos — PUT /envelopes/{envelope}/fields.
     *
     * Aceita Idempotency-Key (opcional).
     *
     * @param  array{fields: list<array{auto?: bool|null, document_id?: string|null, h: float, id?: string|null, label?: string|null, options?: array{date_format?: string|null, default?: bool|null, font_size?: float|null, placeholder?: string|null}|null, page: int, placeholder?: string|null, recipient_client_id?: string|null, recipient_id?: string|null, required?: bool|null, type: 'signature'|'initials'|'name'|'date'|'text'|'checkbox'|'cpf'|'stamp', w: float, x: float, y: float}>, initials_on_all_pages?: bool|null}|object  $body
     * @return list<Model\Field>
     */
    public function syncFields(string $envelope, array|object $body, ?RequestOptions $options = null): array
    {
        $response = $this->http->request(
            method: 'PUT',
            path: '/envelopes/{envelope}/fields',
            pathParams: ['envelope' => $envelope],
            json: $body,
            hasBody: true,
            idempotency: 'optional',
            options: $options,
        );

        return Model\Hydrator::listOf(Model\Field::class, self::items($response->data()));
    }

    /**
     * Enviar para assinatura — POST /envelopes/{envelope}/send.
     *
     * Idempotency-Key obrigatória: o SDK gera um UUID v4 se você não passar uma chave.
     *
     * @return ApiResult<Model\Envelope>
     */
    public function sendEnvelope(string $envelope, ?RequestOptions $options = null): ApiResult
    {
        $response = $this->http->request(
            method: 'POST',
            path: '/envelopes/{envelope}/send',
            pathParams: ['envelope' => $envelope],
            idempotency: 'required',
            options: $options,
        );

        return new ApiResult(Model\Envelope::fromArray(self::object($response->data())), $response->meta());
    }

    /**
     * Cancelar documento — POST /envelopes/{envelope}/cancel.
     *
     * Aceita Idempotency-Key (opcional).
     *
     * @param  array{reason?: string|null}|object|null  $body
     * @return ApiResult<Model\Envelope>
     */
    public function cancelEnvelope(string $envelope, array|object|null $body = null, ?RequestOptions $options = null): ApiResult
    {
        $response = $this->http->request(
            method: 'POST',
            path: '/envelopes/{envelope}/cancel',
            pathParams: ['envelope' => $envelope],
            json: $body,
            hasBody: true,
            idempotency: 'optional',
            options: $options,
        );

        return new ApiResult(Model\Envelope::fromArray(self::object($response->data())), $response->meta());
    }

    /**
     * Baixar arquivo — GET /envelopes/{envelope}/files/{type}.
     *
     * Devolve os bytes do arquivo.
     *
     * @param  'original'|'signed'|'evidence'  $type
     * @param  array{document?: string|null}  $query
     */
    public function downloadFile(string $envelope, string $type, array $query = [], ?RequestOptions $options = null): DownloadedFile
    {
        $response = $this->http->request(
            method: 'GET',
            path: '/envelopes/{envelope}/files/{type}',
            pathParams: ['envelope' => $envelope, 'type' => $type],
            query: $query,
            querySpec: ['document' => ['document', false]],
            options: $options,
            accept: '*/*',
        );

        return DownloadedFile::fromResponse($response);
    }

    /**
     * Eventos da trilha — GET /envelopes/{envelope}/events.
     *
     * Paginada por cursor. Use autoPagingIterator() para percorrer todas as páginas.
     *
     * @param  array{per_page?: int|null, cursor?: string|null}  $query
     * @return Page<Model\Event>
     */
    public function listEvents(string $envelope, array $query = [], ?RequestOptions $options = null): Page
    {
        $response = $this->http->request(
            method: 'GET',
            path: '/envelopes/{envelope}/events',
            pathParams: ['envelope' => $envelope],
            query: $query,
            querySpec: ['per_page' => ['per_page', false], 'cursor' => ['cursor', false]],
            options: $options,
        );

        return Page::fromResponse(
            $response->json(),
            static fn (array $item): Model\Event => Model\Event::fromArray($item),
            fn (string $cursor): Page => $this->listEvents($envelope, ['cursor' => $cursor] + $query, $options),
        );
    }

    /**
     * Registro de verificação — GET /envelopes/{envelope}/verification.
     *
     * @return array<string, mixed>
     */
    public function getVerification(string $envelope, ?RequestOptions $options = null): array
    {
        $response = $this->http->request(
            method: 'GET',
            path: '/envelopes/{envelope}/verification',
            pathParams: ['envelope' => $envelope],
            options: $options,
        );

        return self::object($response->data());
    }

    /**
     * Listar modelos — GET /templates.
     *
     * Paginada por cursor. Use autoPagingIterator() para percorrer todas as páginas.
     *
     * @param  array{per_page?: int|null, cursor?: string|null}  $query
     * @return Page<Model\Template>
     */
    public function listTemplates(array $query = [], ?RequestOptions $options = null): Page
    {
        $response = $this->http->request(
            method: 'GET',
            path: '/templates',
            query: $query,
            querySpec: ['per_page' => ['per_page', false], 'cursor' => ['cursor', false]],
            options: $options,
        );

        return Page::fromResponse(
            $response->json(),
            static fn (array $item): Model\Template => Model\Template::fromArray($item),
            fn (string $cursor): Page => $this->listTemplates(['cursor' => $cursor] + $query, $options),
        );
    }

    /**
     * Detalhar modelo — GET /templates/{template}.
     */
    public function getTemplate(string $template, ?RequestOptions $options = null): Model\Template
    {
        $response = $this->http->request(
            method: 'GET',
            path: '/templates/{template}',
            pathParams: ['template' => $template],
            options: $options,
        );

        return Model\Template::fromArray(self::object($response->data()));
    }

    /**
     * Gerar documento a partir do modelo — POST /templates/{template}/envelopes.
     *
     * Idempotency-Key obrigatória: o SDK gera um UUID v4 se você não passar uma chave.
     *
     * @param  array{participants?: array<string, array{email: string, name: string}>|null, title?: string|null, values?: array<string, string|float|bool|null>|null}|object|null  $body
     */
    public function createEnvelopeFromTemplate(string $template, array|object|null $body = null, ?RequestOptions $options = null): Model\Envelope
    {
        $response = $this->http->request(
            method: 'POST',
            path: '/templates/{template}/envelopes',
            pathParams: ['template' => $template],
            json: $body,
            hasBody: true,
            idempotency: 'required',
            options: $options,
        );

        return Model\Envelope::fromArray(self::object($response->data()));
    }

    /**
     * Eventos que podem ser assinados (`*` = todos, inclusive os que forem criados depois) — GET /webhook-events.
     *
     * @return list<mixed>
     */
    public function listWebhookEvents(?RequestOptions $options = null): array
    {
        $response = $this->http->request(
            method: 'GET',
            path: '/webhook-events',
            options: $options,
        );

        return self::items($response->data());
    }

    /**
     * Payload de exemplo de um evento, no formato `{data: [payload]}` — lista com um item, que é o que os editores de gatilho esperam para mapear campos. Nenhum dado real — GET /webhook-events/{event}/sample.
     *
     * @return ApiResult<list<mixed>>
     */
    public function getWebhookEventSample(string $event, ?RequestOptions $options = null): ApiResult
    {
        $response = $this->http->request(
            method: 'GET',
            path: '/webhook-events/{event}/sample',
            pathParams: ['event' => $event],
            options: $options,
        );

        return new ApiResult(self::items($response->data()), $response->meta());
    }

    /**
     * Assinaturas ativas deste token — GET /webhook-subscriptions.
     *
     * @return ApiResult<list<Model\WebhookSubscription>>
     */
    public function listWebhookSubscriptions(?RequestOptions $options = null): ApiResult
    {
        $response = $this->http->request(
            method: 'GET',
            path: '/webhook-subscriptions',
            options: $options,
        );

        return new ApiResult(Model\Hydrator::listOf(Model\WebhookSubscription::class, self::items($response->data())), $response->meta());
    }

    /**
     * Assina um evento (ou vários) numa URL HTTPS pública — POST /webhook-subscriptions.
     *
     * Idempotency-Key obrigatória: o SDK gera um UUID v4 se você não passar uma chave.
     *
     * @param  array{event?: '*'|'envelope.sent'|'recipient.viewed'|'recipient.signed'|'recipient.approved'|'recipient.refused'|'envelope.refused'|'envelope.completed'|'envelope.expired'|'envelope.canceled'|'document.processing_failed'|null, events?: list<'*'|'envelope.sent'|'recipient.viewed'|'recipient.signed'|'recipient.approved'|'recipient.refused'|'envelope.refused'|'envelope.completed'|'envelope.expired'|'envelope.canceled'|'document.processing_failed'>|null, target_url: string}|object  $body
     * @return ApiResult<Model\WebhookSubscription>
     */
    public function createWebhookSubscription(array|object $body, ?RequestOptions $options = null): ApiResult
    {
        $response = $this->http->request(
            method: 'POST',
            path: '/webhook-subscriptions',
            json: $body,
            hasBody: true,
            idempotency: 'required',
            options: $options,
        );

        return new ApiResult(Model\WebhookSubscription::fromArray(self::object($response->data())), $response->meta());
    }

    /**
     * Remove a assinatura (o "unsubscribe" do REST Hooks). 204; 404 se não for deste token — DELETE /webhook-subscriptions/{subscription}.
     */
    public function deleteWebhookSubscription(string $subscription, ?RequestOptions $options = null): void
    {
        $this->http->request(
            method: 'DELETE',
            path: '/webhook-subscriptions/{subscription}',
            pathParams: ['subscription' => $subscription],
            options: $options,
        );
    }

    /**
     * Criar sessão de assinatura embutida — POST /envelopes/{envelope}/recipients/{recipient}/embedded-sessions.
     *
     * Idempotency-Key obrigatória: o SDK gera um UUID v4 se você não passar uma chave.
     *
     * @param  array{expires_in?: int|null, origin: string}|object  $body
     */
    public function createEmbeddedSession(string $envelope, string $recipient, array|object $body, ?RequestOptions $options = null): Model\EmbeddedSigningSession
    {
        $response = $this->http->request(
            method: 'POST',
            path: '/envelopes/{envelope}/recipients/{recipient}/embedded-sessions',
            pathParams: ['envelope' => $envelope, 'recipient' => $recipient],
            json: $body,
            hasBody: true,
            idempotency: 'required',
            options: $options,
        );

        return Model\EmbeddedSigningSession::fromArray(self::object($response->data()));
    }

    /**
     * Situação da sessão embutida — GET /envelopes/{envelope}/recipients/{recipient}/embedded-sessions/{embeddedSession}.
     */
    public function getEmbeddedSession(string $envelope, string $recipient, string $embeddedSession, ?RequestOptions $options = null): Model\EmbeddedSigningSession
    {
        $response = $this->http->request(
            method: 'GET',
            path: '/envelopes/{envelope}/recipients/{recipient}/embedded-sessions/{embeddedSession}',
            pathParams: ['envelope' => $envelope, 'recipient' => $recipient, 'embeddedSession' => $embeddedSession],
            options: $options,
        );

        return Model\EmbeddedSigningSession::fromArray(self::object($response->data()));
    }

    /**
     * Revogar sessão embutida — DELETE /envelopes/{envelope}/recipients/{recipient}/embedded-sessions/{embeddedSession}.
     */
    public function revokeEmbeddedSession(string $envelope, string $recipient, string $embeddedSession, ?RequestOptions $options = null): Model\EmbeddedSigningSession
    {
        $response = $this->http->request(
            method: 'DELETE',
            path: '/envelopes/{envelope}/recipients/{recipient}/embedded-sessions/{embeddedSession}',
            pathParams: ['envelope' => $envelope, 'recipient' => $recipient, 'embeddedSession' => $embeddedSession],
            options: $options,
        );

        return Model\EmbeddedSigningSession::fromArray(self::object($response->data()));
    }

    /**
     * @return array<string, mixed>
     */
    private static function object(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * @return list<mixed>
     */
    private static function items(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }
}
