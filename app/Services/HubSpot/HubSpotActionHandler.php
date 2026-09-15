<?php

namespace App\Services\HubSpot;

use App\Enums\AuditEventType;
use App\Enums\DocumentProcessingStatus;
use App\Enums\Permission;
use App\Models\Document;
use App\Models\Envelope;
use App\Models\HubSpotActionExecution;
use App\Models\HubSpotConnection;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Template;
use App\Models\TemplateVersion;
use App\Models\User;
use App\Services\Envelopes\EnvelopeAudit;
use App\Services\Envelopes\EnvelopeReadiness;
use App\Services\Envelopes\Sending\Exceptions\SendingException;
use App\Services\Envelopes\Sending\SendEnvelope;
use App\Services\HubSpot\Jobs\SendHubSpotEnvelope;
use App\Services\Plans\Exceptions\SendingBlockedException;
use App\Services\Templates\CreateEnvelopeFromTemplate;
use App\Support\CurrentOrganization;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Execução da ação de workflow "Enviar para assinatura" (docs/fase-3/conectores.md §5.2).
 *
 * Chega aqui só depois da assinatura v3 conferida e do portal resolvido para uma organização
 * com a flag ligada (HubSpotActionController). Então:
 *
 *  1. IDEMPOTÊNCIA: UNIQUE(portal_id, callback_id). A mesma execução repetida (retentativa do
 *     HubSpot, replay dentro da janela) recebe a MESMA resposta guardada, sem novo envelope;
 *  2. o envelope é criado a partir do modelo (`template_id`) pelo MESMO serviço de "Usar
 *     modelo", em nome de quem CONECTOU o HubSpot — que precisa continuar membro ativo com
 *     permissão de criar documentos. Modelo de outra organização = "não encontrado";
 *  3. pronto para enviar → envia (SendEnvelope, mesmas regras de plano e antifraude); arquivo
 *     ainda processando → job tenta de novo depois (SendHubSpotEnvelope); faltou algo (campos,
 *     plano, permissão de envio) → fica em rascunho e o estado diz "precisa de revisão";
 *  4. a resposta ao HubSpot traz só `outputFields` com o ULID do envelope e o estado.
 *
 * Campos de entrada da ação: `template_id` (ULID do modelo), `title` (opcional),
 * `participant_N_name`/`participant_N_email` (N = posição do papel no modelo, a partir de 1) e
 * `var_{chave}` para as variáveis do modelo. Tudo é dado não confiável e passa pela mesma
 * validação do "Usar modelo".
 */
final class HubSpotActionHandler
{
    public function __construct(
        private readonly CreateEnvelopeFromTemplate $creator,
        private readonly SendEnvelope $sender,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{status: int, body: array<string, mixed>}
     */
    public function handle(HubSpotConnection $connection, Organization $organization, array $payload): array
    {
        $callbackId = $payload['callbackId'] ?? null;

        if (! is_string($callbackId) || preg_match('/^[A-Za-z0-9._:\-]{1,120}$/', $callbackId) !== 1) {
            return ['status' => 400, 'body' => ['message' => 'callbackId inválido.']];
        }

        $object = is_array($payload['object'] ?? null) ? $payload['object'] : [];
        $objectType = is_string($object['objectType'] ?? null) && preg_match('/^[A-Za-z0-9_-]{1,40}$/', $object['objectType']) === 1
            ? strtoupper($object['objectType'])
            : null;
        $rawObjectId = $object['objectId'] ?? null;
        $objectId = (is_int($rawObjectId) || (is_string($rawObjectId) && ctype_digit($rawObjectId))) && strlen((string) $rawObjectId) <= 20
            ? (string) $rawObjectId
            : null;
        $fields = is_array($payload['inputFields'] ?? null) ? $payload['inputFields'] : [];

        /** @var HubSpotActionExecution $execution */
        $execution = HubSpotActionExecution::withoutOrganizationScope()->createOrFirst(
            ['portal_id' => $connection->portal_id, 'callback_id' => $callbackId],
            [
                'organization_id' => $organization->getKey(),
                'hubspot_connection_id' => $connection->getKey(),
                'object_type' => $objectType,
                'object_id' => $objectId,
                'status' => HubSpotActionExecution::STATUS_PROCESSING,
            ],
        );

        if (! $execution->wasRecentlyCreated) {
            // Repetição: a mesma resposta, sem trabalho nenhum.
            return ['status' => 200, 'body' => $execution->response ?? self::body($execution, null)];
        }

        try {
            $this->execute($execution, $connection, $organization, $fields);
        } catch (HubSpotException $exception) {
            $execution->forceFill(['status' => HubSpotActionExecution::STATUS_FAILED, 'error_code' => $exception->errorCode])->save();
            $body = self::body($execution, null, $exception->userMessage());
            $execution->forceFill(['response' => $body])->save();

            return ['status' => 200, 'body' => $body];
        } catch (Throwable $exception) {
            $execution->forceFill(['status' => HubSpotActionExecution::STATUS_FAILED, 'error_code' => 'internal_error'])->save();

            Log::error('hubspot.action_failed', ['execution' => $execution->ulid, 'exception' => $exception::class]);
        }

        $execution->refresh();
        $envelope = $execution->envelope_id === null ? null : Envelope::withoutOrganizationScope()->find($execution->envelope_id);
        $body = self::body($execution, $envelope);
        $execution->forceFill(['response' => $body])->save();

        return ['status' => 200, 'body' => $body];
    }

    /**
     * Tenta enviar o envelope de uma execução. true = o arquivo ainda está sendo preparado e
     * vale tentar de novo depois (o job reagenda).
     */
    public function attemptSend(int $executionId, int $attempt = 1): bool
    {
        /** @var HubSpotActionExecution|null $execution */
        $execution = HubSpotActionExecution::withoutOrganizationScope()->find($executionId);

        if ($execution === null || $execution->envelope_id === null
            || ! in_array($execution->status, [HubSpotActionExecution::STATUS_PROCESSING, HubSpotActionExecution::STATUS_AWAITING_PREPARATION], true)) {
            return false;
        }

        /** @var Envelope|null $envelope */
        $envelope = Envelope::withoutOrganizationScope()->find($execution->envelope_id);
        $organization = Organization::query()->find($execution->organization_id);

        if ($envelope === null || $organization === null) {
            $this->mark($execution, HubSpotActionExecution::STATUS_NEEDS_REVIEW, 'envelope_missing');

            return false;
        }

        if (! $envelope->status->isDraftLike()) {
            // Alguém já enviou pelo painel (ou cancelou): nada a fazer aqui.
            $this->mark($execution, HubSpotActionExecution::STATUS_SENT, null);

            return false;
        }

        $connection = $execution->hubspot_connection_id === null ? null : HubSpotConnection::withoutOrganizationScope()->find($execution->hubspot_connection_id);
        $creator = self::creator($connection, $organization);

        if ($creator === null) {
            $this->mark($execution, HubSpotActionExecution::STATUS_NEEDS_REVIEW, 'connector_user_unavailable');

            return false;
        }

        [, $membership] = $creator;

        return (bool) CurrentOrganization::instance()->runAs($organization, function () use ($execution, $envelope, $membership, $attempt): bool {
            $processing = Document::withoutOrganizationScope()
                ->where('envelope_id', $envelope->getKey())
                ->whereIn('processing_status', [DocumentProcessingStatus::Uploaded->value, DocumentProcessingStatus::Converting->value])
                ->exists();

            if ($processing) {
                $retry = $attempt < max(1, (int) config('assinavelox.hubspot.send_attempts', 20));
                $this->mark($execution, $retry ? HubSpotActionExecution::STATUS_AWAITING_PREPARATION : HubSpotActionExecution::STATUS_NEEDS_REVIEW, $retry ? null : 'preparation_timeout');

                return $retry;
            }

            EnvelopeReadiness::refresh($envelope);
            $envelope->refresh();

            if (! $membership->hasPermission(Permission::SendEnvelopes)) {
                $this->mark($execution, HubSpotActionExecution::STATUS_NEEDS_REVIEW, 'send_not_allowed');

                return false;
            }

            if (! EnvelopeReadiness::isReady($envelope)) {
                $this->mark($execution, HubSpotActionExecution::STATUS_NEEDS_REVIEW, 'not_ready');

                return false;
            }

            try {
                $this->sender->handle($envelope);
            } catch (SendingException|SendingBlockedException $exception) {
                $this->mark($execution, HubSpotActionExecution::STATUS_NEEDS_REVIEW, mb_substr((string) $exception->errorCode, 0, 64));

                return false;
            }

            $this->mark($execution, HubSpotActionExecution::STATUS_SENT, null);

            return false;
        }, $membership);
    }

    /**
     * @param  array<string, mixed>  $fields
     *
     * @throws HubSpotException
     */
    private function execute(HubSpotActionExecution $execution, HubSpotConnection $connection, Organization $organization, array $fields): void
    {
        $creator = self::creator($connection, $organization);

        if ($creator === null) {
            throw new HubSpotException('connector_user_unavailable', 'Quem conectou o HubSpot não tem mais acesso para criar documentos nesta organização. Conecte de novo com outra pessoa.');
        }

        [$user, $membership] = $creator;
        $templateId = $fields['template_id'] ?? null;

        if (! is_string($templateId) || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $templateId) !== 1) {
            throw new HubSpotException('template_required', 'Informe no campo template_id o identificador de um modelo do AssinaVelox.');
        }

        /** @var Template|null $template */
        $template = Template::forOrganization($organization)->where('ulid', strtoupper($templateId))->first();

        if ($template === null || ! $template->isUsable()) {
            throw new HubSpotException('template_not_found', 'Modelo não encontrado ou indisponível nesta organização.');
        }

        $input = CurrentOrganization::instance()->runAs($organization, fn (): array => $this->input($template, $fields), $membership);

        try {
            /** @var Envelope $envelope */
            $envelope = CurrentOrganization::instance()->runAs(
                $organization,
                fn (): Envelope => $this->creator->handle($template, $user, $input),
                $membership,
            );
        } catch (ValidationException $exception) {
            $first = collect($exception->errors())->flatten()->first();

            throw new HubSpotException('invalid_input', is_string($first) && $first !== '' ? $first : 'Os dados enviados pelo HubSpot não foram aceitos pelo modelo.');
        }

        $execution->forceFill(['envelope_id' => $envelope->getKey(), 'template_id' => $template->getKey()])->save();

        EnvelopeAudit::record($envelope, AuditEventType::HubSpotActionReceived, [
            'execution' => $execution->ulid,
            'portal_id' => $execution->portal_id,
            'object_type' => $execution->object_type,
            'object_id' => $execution->object_id,
            'template' => $template->ulid,
        ]);

        if ($this->attemptSend((int) $execution->getKey())) {
            SendHubSpotEnvelope::dispatch((int) $execution->getKey())
                ->delay(Carbon::now()->addSeconds(max(5, (int) config('assinavelox.hubspot.send_retry_seconds', 30))));
        }
    }

    /**
     * Entrada de CreateEnvelopeFromTemplate a partir dos campos da ação.
     *
     * @param  array<string, mixed>  $fields
     * @return array{title: string|null, values: array<string, string>, participants: array<string, array{name: string, email: string}>}
     *
     * @throws HubSpotException
     */
    private function input(Template $template, array $fields): array
    {
        /** @var TemplateVersion|null $version */
        $version = $template->currentVersion()->with(['roles', 'variables'])->first();

        if ($version === null) {
            throw new HubSpotException('template_not_found', 'Modelo não encontrado ou indisponível nesta organização.');
        }

        $max = max(1, (int) config('assinavelox.hubspot.max_participants', 5));

        if ($version->roles->count() > $max) {
            throw new HubSpotException('too_many_roles', sprintf('A ação do HubSpot aceita modelos com até %d participantes.', $max));
        }

        $participants = [];

        foreach ($version->roles->values() as $index => $role) {
            $n = $index + 1;
            $participants[$role->ulid] = [
                'name' => self::text($fields['participant_'.$n.'_name'] ?? null, 120),
                'email' => self::text($fields['participant_'.$n.'_email'] ?? null, 255),
            ];
        }

        $values = [];

        foreach ($version->variables as $variable) {
            $key = 'var_'.$variable->key;

            if (array_key_exists($key, $fields) && is_scalar($fields[$key])) {
                $values[(string) $variable->key] = self::text($fields[$key], 5000);
            }
        }

        $title = self::text($fields['title'] ?? null, 160);

        return ['title' => $title === '' ? null : $title, 'values' => $values, 'participants' => $participants];
    }

    /**
     * Quem conectou o HubSpot, se ainda for membro ativo com permissão de criar documentos.
     *
     * @return array{0: User, 1: Membership}|null
     */
    private static function creator(?HubSpotConnection $connection, Organization $organization): ?array
    {
        $userId = $connection?->connected_by_user_id;

        if ($userId === null) {
            return null;
        }

        /** @var Membership|null $membership */
        $membership = Membership::query()->withoutGlobalScopes()
            ->where('organization_id', $organization->getKey())
            ->where('user_id', $userId)
            ->first();
        $user = User::query()->find($userId);

        if ($membership === null || $user === null || ! $membership->isActive() || ! $membership->hasPermission(Permission::CreateEnvelopes)) {
            return null;
        }

        return [$user, $membership];
    }

    private function mark(HubSpotActionExecution $execution, string $status, ?string $code): void
    {
        $execution->forceFill(['status' => $status, 'error_code' => $code])->save();
    }

    private static function text(mixed $value, int $max): string
    {
        return is_scalar($value) ? mb_substr(trim((string) $value), 0, $max) : '';
    }

    /**
     * @return array<string, mixed>
     */
    private static function body(HubSpotActionExecution $execution, ?Envelope $envelope, ?string $message = null): array
    {
        $message ??= match ($execution->status) {
            HubSpotActionExecution::STATUS_SENT => 'Documento enviado para assinatura.',
            HubSpotActionExecution::STATUS_AWAITING_PREPARATION => 'Documento criado. O envio sai assim que o arquivo terminar de ser preparado.',
            HubSpotActionExecution::STATUS_NEEDS_REVIEW => 'Documento criado em rascunho. Conclua o preparo no AssinaVelox para enviar.',
            HubSpotActionExecution::STATUS_PROCESSING => 'Execução em processamento.',
            default => 'Não foi possível criar o documento.',
        };

        return [
            'outputFields' => [
                'assinavelox_envelope_id' => $envelope->ulid ?? '',
                'assinavelox_status' => $execution->status,
                'assinavelox_message' => $message,
            ],
        ];
    }
}
