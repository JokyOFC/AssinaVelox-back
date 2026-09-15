<?php

namespace App\Services\Signing;

use App\Enums\AcceptanceAction;
use App\Enums\AuditEventType;
use App\Enums\EnvelopeStatus;
use App\Enums\FieldType;
use App\Enums\RecipientRole;
use App\Enums\RecipientStatus;
use App\Enums\SignatureKind;
use App\Events\EnvelopeReadyForFinalization;
use App\Models\AcceptanceDocument;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Models\SignatureAcceptance;
use App\Models\SigningField;
use App\Models\SigningFieldValue;
use App\Models\SigningSession;
use App\Models\SigningSessionDocument;
use App\Services\Branding\Stamp\StampImages;
use App\Services\Envelopes\Delegation\DelegationVoider;
use App\Services\Envelopes\Sending\CancelEnvelope;
use App\Services\Envelopes\Steps\StepProgression;
use App\Services\Identity\CpfLookup;
use App\Services\Identity\CpfNumber;
use App\Services\Identity\IdentityCaptures;
use App\Services\Signing\Exceptions\SigningRejectedException;
use App\Support\Locale\SignerLocales;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Gravação do **aceite eletrônico** (arquitetura §4.5, §3.3).
 *
 * ## O que é revalidado sob lock
 *
 * Tudo que a tela já tinha validado, de novo, dentro de `SELECT ... FOR UPDATE` no envelope:
 * status, prazo, vez no sequencial, sessão autenticada, versão da sessão igual à
 * `sent_document_version_id`, token de autorização vivo, `snapshot_hash` igual ao da tela e
 * inexistência de aceite anterior. Entre a renderização e o clique podem ter passado
 * minutos, e nesse intervalo o remetente pode ter cancelado, o prazo pode ter vencido e
 * outro participante pode ter recusado. **A tela não é fonte de verdade; o banco sob lock é.**
 *
 * A duplicidade tem duas defesas: a checagem sob lock e o `UNIQUE(recipient_id)` de
 * `signature_acceptances`. A segunda é a que vale quando dois processos passam pela primeira
 * ao mesmo tempo em um banco onde o lock não serializa — a violação vira 409, nunca dois aceites.
 *
 * ## Vários documentos (Fase 2 §2.3)
 *
 * UM aceite por participante cobre o CONJUNTO de documentos do envelope. Ele só é gravado se
 * todos os documentos foram entregues à sessão (`signing_session_documents`) e se os campos
 * obrigatórios de todos os documentos em que a pessoa tem campos foram preenchidos. Para cada
 * documento fica uma linha em `acceptance_documents` (versão, SHA-256, campos e valores).
 * `signature_acceptances.document_version_id`/`document_sha256` continuam apontando para o
 * primeiro documento (compatibilidade com a Fase 1).
 *
 * ## Papéis (Fase 2 §2.4)
 *
 * `signer` → action `sign`; `witness` → `witness` (mesmo fluxo, declaração própria);
 * `approver` → `approve` (sem representação visual de assinatura). O `viewer` não registra
 * aceite: a tentativa é recusada.
 *
 * ## O que é do servidor, não do cliente
 *
 * - `accepted_at`: hora do servidor em UTC.
 * - Campos `date`: carimbados pelo servidor no fuso da organização (RECONCILIACAO Q9). O que
 *   o cliente mandar nesses campos é descartado sem erro — não é um valor "inválido", é um
 *   valor que simplesmente não é dele.
 * - `document_sha256`: dos bytes da versão apresentada, lido do banco.
 * - `consent_statement`: o texto resolvido no servidor, não o que voltou do formulário.
 * - IP e user-agent: da requisição, com o IP dependendo dos proxies confiáveis configurados.
 *
 * A transação **não** contém chamada externa nem processo: a imagem é normalizada e gravada
 * no disco antes de abrir a transação (arquitetura §3.3).
 */
final class RecordAcceptance
{
    public function __construct(
        private readonly SignerSessions $sessions,
        private readonly SignerPresentation $presentation,
        private readonly SignatureImages $images,
        private readonly SignerNotifier $notifier,
        // Fase 2 §2.10/§2.11 (C-ID): captura simples e consulta cadastral do CPF.
        private readonly IdentityCaptures $captures,
        private readonly CpfLookup $cpfLookup,
        // Fase 2 §2.8 (C-BRAND): carimbo visual congelado no aceite.
        private readonly StampImages $stamps,
    ) {}

    /**
     * @param  array{signature: array<string, mixed>, initials?: array<string, mixed>|null, fields?: array<string, mixed>, authorization: string}  $payload
     *
     * @throws SigningRejectedException
     */
    public function handle(SignerContext $context, SigningSession $session, Request $request, array $payload): SignatureAcceptance
    {
        $action = $context->action();

        // Visualizador: só recebe cópia. Não há aceite a registrar (Fase 2 §2.4).
        if ($action === null) {
            throw SigningRejectedException::conflict(
                'not_signable',
                'Você recebeu este documento apenas para acompanhar: não há aceite a registrar.',
            );
        }

        $version = $context->sentVersion();
        $sent = $context->sentDocuments();

        if ($version === null || $sent === [] || (int) $sent[0]['version']->getKey() !== (int) $version->getKey()) {
            throw SigningRejectedException::conflict('missing_sent_version', 'Este documento não está disponível para assinatura.');
        }

        // A declaração gravada em `consent_statement` afirma "Li integralmente o documento
        // […], cujo conteúdo apresentado nesta tela corresponde ao resumo SHA-256 …". Sem
        // que os bytes tenham saído do servidor para ESTA sessão, essa frase não tem como
        // ser sustentada pelo dossiê: a trilha ia de `invitation.opened` — que a própria
        // página de evidências rotula "não comprova leitura" — direto para
        // `acceptance.recorded`. `sign.document` marca a entrega na sessão e registra
        // `document.presented`; aqui a marca é exigida.
        if ($session->document_presented_at === null) {
            throw SigningRejectedException::conflict(
                'document_not_presented',
                'O documento ainda não foi carregado nesta sessão. Recarregue a página, confira o documento e assine em seguida.',
            );
        }

        // Vários documentos: TODOS precisam ter sido entregues a esta sessão.
        if (count($sent) > 1) {
            $this->assertAllPresented($session, $sent);
        }

        if ($session->document_version_id !== $version->getKey()) {
            throw SigningRejectedException::conflict(
                'stale_session_version',
                'O documento foi atualizado. Recarregue a página para ver a versão atual antes de assinar.',
            );
        }

        $fields = count($sent) > 1
            ? $this->presentation->myFieldsForDocuments($context, $sent)
            : $this->presentation->myFields($context, $version);

        $consentText = ConsentText::statement(
            $context->envelope,
            $context->recipient,
            $context->organization,
            $version->sha256,
            null,
            $sent,
        );
        $snapshot = $this->presentation->snapshotFor($context, $sent, $fields, $consentText);
        $snapshotHash = SignerPresentation::hash($snapshot);

        if (! $this->sessions->authorizationMatches($session, $payload['authorization'], $snapshotHash)) {
            throw SigningRejectedException::conflict(
                'stale_presentation',
                'A tela mudou desde que foi carregada (documento, campos ou prazo). Recarregue a página e confira antes de assinar.',
            );
        }

        $now = Carbon::now();

        // Valores dos campos: validados AQUI, fora da transação, porque a validação pode
        // recusar e não faz sentido segurar o lock do envelope enquanto se decide isso.
        $values = $this->resolveFieldValues($fields, $context, $payload['fields'] ?? [], $now);

        // Fase 2 §2.10 (C-ID): com a flag `identity_capture`, as fotos exigidas pelo remetente
        // precisam existir NESTA sessão. Antes da imagem da assinatura, para não deixar PNG
        // órfão no disco quando o aceite é recusado aqui.
        $this->captures->assertComplete($context, $session);

        // Aprovador não tem representação visual: nada do que vier em `signature` é gravado.
        $visual = $action->requiresVisualSignature()
            ? $this->resolveVisual($context, $payload, $fields)
            : self::noVisual();

        $correlationId = SignerTokens::correlationId();

        // Fase 2 §2.11 (C-ID): consulta CADASTRAL do CPF, só com a flag `cpf_lookup`. Chamada
        // externa, então fora da transação; nenhum resultado bloqueia o aceite.
        $cpfChecks = $this->cpfLookup->checkFields($fields, $values, $context, $correlationId);
        $captures = $this->captures->snapshotFor($context, $session);

        // Fase 2 §2.8 (C-BRAND): o carimbo visual é congelado AQUI, no aceite de quem tem o
        // campo (I/O fora da transação). Marca desligada ou sem marca: null, campo vazio no PDF.
        $stampPath = $fields->contains(fn (SigningField $field): bool => $field->type === FieldType::Stamp)
            ? $this->stamps->snapshot($context->envelope->setRelation('organization', $context->organization))
            : null;

        try {
            $acceptance = $this->persist(
                $context,
                $session,
                $version,
                $sent,
                $action,
                $fields,
                $values,
                $visual,
                $snapshot,
                $consentText,
                $request,
                $now,
                $correlationId,
                $cpfChecks,
                $captures,
                $stampPath,
            );
        } catch (\Throwable $exception) {
            // A imagem foi normalizada e gravada ANTES da transação (para não segurar o
            // lock do envelope durante I/O), mas o aceite pode ser recusado sob lock:
            // envelope cancelado, prazo vencido ou encerrado pela recusa de outro
            // participante entre a renderização da tela e o clique. Sem esta limpeza o
            // PNG ficava órfão no disco — dado pessoal sem nenhum registro que permitisse
            // auditá-lo ou apagá-lo a pedido, e um caminho barato para encher o disco.
            $this->images->discard($visual['image_path']);
            $this->images->discard($visual['initials_image_path']);

            throw $exception;
        }

        $this->sessions->consume($session, $context, $request);

        $this->advance($context->envelope->getKey(), $correlationId);

        // "Fulano assinou" para quem enviou. Fora da transação e depois de `advance()`,
        // como o convite do próximo: um e-mail que não sai não desfaz um aceite gravado —
        // o SignerNotifier registra em log e segue.
        $this->notifier->notifySenderSigned($context->envelope->refresh(), $context->recipient->refresh());

        return $acceptance;
    }

    /**
     * @param  list<array{document: Document, version: DocumentVersion}>  $sent
     *
     * @throws SigningRejectedException
     */
    private function assertAllPresented(SigningSession $session, array $sent): void
    {
        $presented = SigningSessionDocument::withoutOrganizationScope()
            ->where('signing_session_id', $session->getKey())
            ->get(['document_id', 'document_version_id'])
            ->mapWithKeys(fn (SigningSessionDocument $row): array => [(int) $row->document_id => (int) $row->document_version_id])
            ->all();

        foreach ($sent as $row) {
            $documentId = (int) $row['document']->getKey();

            if (($presented[$documentId] ?? null) !== (int) $row['version']->getKey()) {
                throw SigningRejectedException::conflict(
                    'document_not_presented',
                    sprintf(
                        'Abra e confira todos os arquivos antes de assinar: o arquivo "%s" ainda não foi carregado nesta sessão.',
                        $row['document']->name,
                    ),
                    ['document' => $row['document']->ulid],
                );
            }
        }
    }

    // -- Persistência ------------------------------------------------------------------

    /**
     * @param  list<array{document: Document, version: DocumentVersion}>  $sent
     * @param  Collection<int, SigningField>  $fields
     * @param  array<string, array{text: string|null, bool: bool|null}>  $values
     * @param  array{kind: SignatureKind|null, image_path: string|null, initials_image_path: string|null, typed_name: string|null, typed_font: string|null}  $visual
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, array<string, mixed>>  $cpfChecks  resultado da consulta cadastral por ULID do campo (C-ID)
     * @param  list<array{capture_ulid: string, kind: string, sha256: string, width: int, height: int, captured_at: string}>  $captures  fotos referenciadas pelo aceite (C-ID)
     */
    private function persist(
        SignerContext $context,
        SigningSession $session,
        DocumentVersion $version,
        array $sent,
        AcceptanceAction $action,
        Collection $fields,
        array $values,
        array $visual,
        array $snapshot,
        string $consentText,
        Request $request,
        Carbon $now,
        string $correlationId,
        array $cpfChecks = [],
        array $captures = [],
        ?string $stampPath = null,
    ): SignatureAcceptance {
        try {
            return DB::transaction(function () use (
                $context, $session, $version, $sent, $action, $fields, $values, $visual, $snapshot, $consentText, $request, $now, $correlationId, $cpfChecks, $captures, $stampPath
            ): SignatureAcceptance {
                /** @var Envelope|null $envelope */
                $envelope = Envelope::withoutOrganizationScope()
                    ->whereKey($context->envelope->getKey())
                    ->lockForUpdate()
                    ->first();

                /** @var Recipient|null $recipient */
                $recipient = Recipient::withoutOrganizationScope()
                    ->whereKey($context->recipient->getKey())
                    ->first();

                if ($envelope === null || $recipient === null) {
                    throw SigningRejectedException::conflict('not_signable', 'Este documento não está mais disponível para assinatura.');
                }

                $this->assertStillSignable($envelope, $recipient, $version);

                $challengeId = $session->authChallenges()
                    ->withoutGlobalScopes()
                    ->whereNotNull('consumed_at')
                    ->latest('id')
                    ->value('id');

                $acceptance = SignatureAcceptance::query()->create([
                    'recipient_id' => $recipient->getKey(),
                    'envelope_id' => $envelope->getKey(),
                    'document_version_id' => $version->getKey(),
                    'signing_session_id' => $session->getKey(),
                    'auth_challenge_id' => $challengeId,
                    'organization_id' => $envelope->organization_id,
                    'action' => $action,
                    'accepted_at' => $now,
                    'ip_address' => SignerRequestFacts::ip($request),
                    'user_agent' => SignerRequestFacts::userAgent($request),
                    'auth_method' => $recipient->auth_method,
                    'terms_version' => ConsentText::versionFor($envelope, $recipient, count($sent)),
                    // Fase 3 §3.3 (F-I18N): idioma em que a página foi exibida (nulo com a flag
                    // `multilingual` desligada). A declaração gravada é sempre a de referência.
                    'display_locale' => SignerLocales::current($request)?->value,
                    'consent_statement' => $consentText,
                    'document_sha256' => $version->sha256,
                    // `identity_captures` só existe quando houve foto exigida (C-ID): sem ela o
                    // snapshot é exatamente o de antes.
                    'fields_snapshot' => $snapshot
                        + ['values' => $this->snapshotValues($fields, $values, $visual, $cpfChecks)]
                        + ($captures === [] ? [] : ['identity_captures' => $captures]),
                    'signature_kind' => $visual['kind'],
                    'signature_image_path' => $visual['image_path'],
                    'typed_name' => $visual['typed_name'],
                    'typed_font' => $visual['typed_font'],
                ]);

                // Fotos da captura simples passam a pertencer a este aceite (C-ID).
                $this->captures->attachToAcceptance($acceptance, $captures);

                foreach ($fields as $field) {
                    $value = $values[$field->ulid] ?? ['text' => null, 'bool' => null];

                    SigningFieldValue::query()->create([
                        'signing_field_id' => $field->getKey(),
                        'recipient_id' => $recipient->getKey(),
                        'signature_acceptance_id' => $acceptance->getKey(),
                        'envelope_id' => $envelope->getKey(),
                        'organization_id' => $envelope->organization_id,
                        'value_text' => $value['text'],
                        'value_bool' => $value['bool'],
                        'image_path' => match ($field->type) {
                            FieldType::Signature => $visual['image_path'],
                            FieldType::Initials => $visual['initials_image_path'] ?? $visual['image_path'],
                            FieldType::Stamp => $stampPath,
                            default => null,
                        },
                    ]);
                }

                // O que o aceite cobriu, documento a documento (Fase 2 §2.3).
                foreach ($sent as $row) {
                    $documentFields = $fields
                        ->filter(fn (SigningField $field): bool => (int) $field->document_version_id === (int) $row['version']->getKey())
                        ->values();

                    AcceptanceDocument::query()->create([
                        'signature_acceptance_id' => $acceptance->getKey(),
                        'document_id' => $row['document']->getKey(),
                        'document_version_id' => $row['version']->getKey(),
                        'organization_id' => $envelope->organization_id,
                        'position' => (int) $row['document']->position,
                        'document_sha256' => $row['version']->sha256,
                        'fields_snapshot' => [
                            'fields' => $documentFields->map(fn (SigningField $field): array => SignerPresentation::fieldSnapshot($field))->all(),
                            'values' => $this->snapshotValues($documentFields, $values, $visual, $cpfChecks),
                        ],
                    ]);
                }

                // pending → notified → viewed → signed: um POST direto (sem GET) pula
                // `viewed`, e a máquina de estados não permite. O aceite é a prova de que a
                // pessoa viu; o passo intermediário é registrado para manter a trilha coerente.
                if ($recipient->status === RecipientStatus::Notified) {
                    $recipient->transitionTo(RecipientStatus::Viewed);
                }

                $recipient->transitionTo(RecipientStatus::Signed);
                $recipient->signed_at = $now;
                $recipient->save();

                $payload = [
                    'acceptance_ulid' => $acceptance->ulid,
                    'document_version_ulid' => $version->ulid,
                    'document_sha256' => $version->sha256,
                    'terms_version' => $acceptance->terms_version,
                    'auth_method' => $recipient->auth_method->value,
                    'signature_kind' => $visual['kind']?->value,
                    'fields' => $fields->count(),
                ];

                // Chaves da Fase 2 só quando há o que dizer: o payload de um signatário com
                // um documento continua o da Fase 1.
                if ($action !== AcceptanceAction::Sign || count($sent) > 1) {
                    $payload['action'] = $action->value;
                    $payload['documents'] = array_map(static fn (array $row): array => [
                        'document_ulid' => $row['document']->ulid,
                        'sha256' => $row['version']->sha256,
                    ], $sent);
                }

                SignerAudit::record(
                    $envelope,
                    $recipient,
                    $action === AcceptanceAction::Approve ? AuditEventType::ApprovalRecorded : AuditEventType::AcceptanceRecorded,
                    $payload,
                    $correlationId,
                );

                return $acceptance;
            });
        } catch (QueryException $exception) {
            // Corrida real: dois processos passaram pela checagem e o UNIQUE(recipient_id)
            // decidiu. Um aceite, um 409.
            if ($this->isUniqueViolation($exception)) {
                throw SigningRejectedException::conflict('already_signed', 'Seu aceite já foi registrado para este documento.');
            }

            throw $exception;
        }
    }

    /**
     * @throws SigningRejectedException
     */
    private function assertStillSignable(Envelope $envelope, Recipient $recipient, DocumentVersion $version): void
    {
        if (! $recipient->participates()) {
            throw SigningRejectedException::conflict('not_signable', 'Você recebeu este documento apenas para acompanhar: não há aceite a registrar.');
        }

        if ($envelope->status !== EnvelopeStatus::InProgress) {
            throw SigningRejectedException::conflict('not_signable', 'Este documento não está mais disponível para assinatura.');
        }

        if ($envelope->expires_at !== null && $envelope->expires_at->isPast()) {
            throw SigningRejectedException::conflict('expired', 'O prazo para assinar este documento terminou.');
        }

        if ($envelope->sent_document_version_id !== $version->getKey()) {
            throw SigningRejectedException::conflict(
                'stale_session_version',
                'O documento foi atualizado. Recarregue a página para ver a versão atual antes de assinar.',
            );
        }

        if ($recipient->status === RecipientStatus::Signed) {
            throw SigningRejectedException::conflict('already_signed', 'Seu aceite já foi registrado para este documento.');
        }

        if (! $recipient->status->isPendingSignature()) {
            throw SigningRejectedException::conflict('not_signable', 'Este documento não está mais disponível para assinatura.');
        }

        // Fase 3 §3.3 (F-FLOW): com etapas a vez vale também no paralelo (sem etapas = sequencial).
        if ($envelope->hasTurns() && $recipient->order_index > $envelope->current_order) {
            throw SigningRejectedException::conflict('not_your_turn', 'Ainda não é a sua vez de assinar este documento.');
        }

        if (SignatureAcceptance::withoutOrganizationScope()->where('recipient_id', $recipient->getKey())->exists()) {
            throw SigningRejectedException::conflict('already_signed', 'Seu aceite já foi registrado para este documento.');
        }
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        $code = (string) ($exception->errorInfo[1] ?? '');
        $message = strtolower($exception->getMessage());

        return $exception->getCode() === '23000'
            || in_array($code, ['1062', '19'], true)
            || str_contains($message, 'unique constraint')
            || str_contains($message, 'duplicate entry');
    }

    // -- Campos ------------------------------------------------------------------------

    /**
     * Valores gravados por campo, já validados.
     *
     * @param  Collection<int, SigningField>  $fields
     * @param  array<string, mixed>  $input
     * @return array<string, array{text: string|null, bool: bool|null}>
     *
     * @throws SigningRejectedException
     */
    public function resolveFieldValues(Collection $fields, SignerContext $context, array $input, Carbon $now): array
    {
        $maxText = max(1, (int) config('assinavelox.signing_session.max_text_field_length', 500));
        $values = [];

        foreach ($fields as $field) {
            $raw = $input[$field->ulid] ?? null;

            $values[$field->ulid] = match ($field->type) {
                // Carimbado pelo servidor: o que o cliente mandou é ignorado (Q9).
                FieldType::Date => ['text' => $this->presentation->serverDate($field, $context, $now), 'bool' => null],

                FieldType::Checkbox => $this->checkboxValue($field, $raw),

                FieldType::Name => $this->textValue(
                    $field,
                    is_string($raw) && trim($raw) !== '' ? trim($raw) : $context->recipient->name,
                    160,
                ),

                FieldType::Text => $this->textValue($field, is_string($raw) ? trim($raw) : '', $maxText),

                // Fase 2 §2.11 (C-ID): dígitos verificadores conferidos AQUI, no servidor.
                FieldType::Cpf => $this->cpfValue($field, $raw),

                // Assinatura e rubrica não vêm do mapa de campos: vêm da captura. O carimbo
                // (Fase 2 §2.8) é desenhado pelo servidor na consolidação: o cliente não o envia.
                FieldType::Signature, FieldType::Initials, FieldType::Stamp => ['text' => null, 'bool' => null],
            };
        }

        return $values;
    }

    /**
     * @return array{text: string|null, bool: bool|null}
     *
     * @throws SigningRejectedException
     */
    private function checkboxValue(SigningField $field, mixed $raw): array
    {
        $checked = filter_var($raw, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false;

        if ($field->required && ! $checked) {
            throw new SigningRejectedException(
                'required_field_missing',
                sprintf('Marque a caixa "%s" para continuar.', $field->label ?: 'obrigatória'),
                context: ['field' => $field->ulid],
            );
        }

        return ['text' => null, 'bool' => $checked];
    }

    /**
     * @return array{text: string|null, bool: bool|null}
     *
     * @throws SigningRejectedException
     */
    private function textValue(SigningField $field, string $value, int $max): array
    {
        if ($value === '' && $field->required) {
            throw new SigningRejectedException(
                'required_field_missing',
                sprintf('Preencha o campo "%s" para continuar.', $field->label ?: $field->type->label()),
                context: ['field' => $field->ulid],
            );
        }

        if (Str::length($value) > $max) {
            throw new SigningRejectedException(
                'field_too_long',
                sprintf('O campo "%s" aceita no máximo %d caracteres.', $field->label ?: $field->type->label(), $max),
                context: ['field' => $field->ulid],
            );
        }

        return ['text' => $value === '' ? null : $value, 'bool' => null];
    }

    /**
     * Campo `cpf` (Fase 2 §2.11, C-ID): 11 dígitos, sem sequência repetida e com os dois
     * verificadores corretos. Gravado formatado (`000.000.000-00`), porque é conteúdo do
     * documento preenchido pela própria pessoa; fora do documento só sai mascarado
     * (`***.456.789-**`). Dígitos válidos NÃO provam que a pessoa é a titular.
     *
     * @return array{text: string|null, bool: bool|null}
     *
     * @throws SigningRejectedException
     */
    private function cpfValue(SigningField $field, mixed $raw): array
    {
        $input = is_string($raw) ? trim($raw) : '';
        $label = $field->label ?: $field->type->label();

        if ($input === '') {
            if ($field->required) {
                throw new SigningRejectedException(
                    'required_field_missing',
                    sprintf('Preencha o campo "%s" para continuar.', $label),
                    context: ['field' => $field->ulid],
                );
            }

            return ['text' => null, 'bool' => null];
        }

        if (Str::length($input) > 20 || preg_match('/^[\d.\-\s]+$/', $input) !== 1 || ! CpfNumber::isValid($input)) {
            throw new SigningRejectedException(
                'invalid_cpf',
                sprintf('O CPF informado em "%s" não é válido. Confira os números digitados.', $label),
                context: ['field' => $field->ulid],
            );
        }

        return ['text' => CpfNumber::format($input), 'bool' => null];
    }

    // -- Representação visual -----------------------------------------------------------

    /**
     * Sem representação visual (aprovador, Fase 2 §2.4).
     *
     * @return array{kind: null, image_path: null, initials_image_path: null, typed_name: null, typed_font: null}
     */
    private static function noVisual(): array
    {
        return [
            'kind' => null,
            'image_path' => null,
            'initials_image_path' => null,
            'typed_name' => null,
            'typed_font' => null,
        ];
    }

    /**
     * Normaliza a representação visual conforme o método escolhido.
     *
     * @param  array<string, mixed>  $payload
     * @param  Collection<int, SigningField>  $fields
     * @return array{kind: SignatureKind, image_path: string|null, initials_image_path: string|null, typed_name: string|null, typed_font: string|null}
     *
     * @throws SigningRejectedException
     */
    public function resolveVisual(SignerContext $context, array $payload, Collection $fields): array
    {
        $signature = is_array($payload['signature'] ?? null) ? $payload['signature'] : [];
        $method = is_string($signature['method'] ?? null) ? $signature['method'] : '';

        $needsSignature = $fields->contains(fn (SigningField $f): bool => $f->type === FieldType::Signature);
        $needsInitials = $fields->contains(fn (SigningField $f): bool => $f->type === FieldType::Initials);

        $kind = match ($method) {
            'draw' => SignatureKind::Drawn,
            'type' => SignatureKind::Typed,
            'upload' => SignatureKind::Uploaded,
            default => throw new SigningRejectedException('invalid_signature_method', 'Escolha como quer assinar: desenhar, digitar ou enviar uma imagem.'),
        };

        $imagePath = null;
        $typedName = null;
        $typedFont = null;

        if ($kind === SignatureKind::Typed) {
            $typedName = trim((string) ($signature['text'] ?? ''));

            if (Str::length($typedName) < 2 || Str::length($typedName) > 80) {
                throw new SigningRejectedException('invalid_typed_signature', 'Digite seu nome com 2 a 80 caracteres para assinar.');
            }

            $font = (string) ($signature['font'] ?? 'caveat');
            $typedFont = in_array($font, self::FONTS, true) ? $font : 'caveat';
        } elseif ($needsSignature || $needsInitials || ($signature['image_base64'] ?? null) !== null) {
            $imagePath = $this->images->store((string) ($signature['image_base64'] ?? ''), $context, 'signature');
        }

        $initialsPath = null;
        $initials = is_array($payload['initials'] ?? null) ? $payload['initials'] : null;

        if ($needsInitials && $initials !== null && is_string($initials['image_base64'] ?? null) && $initials['image_base64'] !== '') {
            $initialsPath = $this->images->store((string) $initials['image_base64'], $context, 'initials');
        }

        return [
            'kind' => $kind,
            'image_path' => $imagePath,
            'initials_image_path' => $initialsPath,
            'typed_name' => $typedName,
            'typed_font' => $typedFont,
        ];
    }

    /**
     * Fontes manuscritas aceitas na assinatura digitada (DESIGN_SYSTEM §1.2).
     *
     * `typed_font` é **evidência**: diz em que família a representação visual foi
     * desenhada. Só pode listar famílias que o build realmente carrega — hoje a
     * Caveat é a única (`vite.config.ts`, `--font-hand`). Aceitar `dancing_script`
     * ou `homemade_apple` gravaria uma evidência falsa, porque o navegador teria
     * caído numa fonte genérica do sistema. Ao acrescentar uma família aqui,
     * acrescente-a também ao `vite.config.ts` e a `SIGNATURE_STYLES`.
     */
    public const FONTS = ['caveat'];

    /** Motivo gravado quando, com etapas, nenhuma etapa com signatário se aplicou. */
    public const NO_SIGNATURE_REASON = 'Nenhuma etapa com signatário se aplicou: o documento foi encerrado sem assinatura.';

    /**
     * Valores como entram no `fields_snapshot`. Caminhos de arquivo entram; bytes, não.
     *
     * @param  Collection<int, SigningField>  $fields
     * @param  array<string, array{text: string|null, bool: bool|null}>  $values
     * @param  array{kind: SignatureKind|null, image_path: string|null, initials_image_path: string|null, typed_name: string|null, typed_font: string|null}  $visual
     * @param  array<string, array<string, mixed>>  $cpfChecks
     * @return list<array<string, mixed>>
     */
    private function snapshotValues(Collection $fields, array $values, array $visual, array $cpfChecks = []): array
    {
        /** @var list<array<string, mixed>> */
        return $fields
            ->map(function (SigningField $field) use ($values, $visual, $cpfChecks): array {
                $value = $values[$field->ulid] ?? ['text' => null, 'bool' => null];

                return array_filter([
                    // Campo `cpf` (C-ID): a forma mascarada para quem exibir o snapshot fora do
                    // documento, e o resultado da consulta cadastral quando ela rodou.
                    'cpf_masked' => $field->type === FieldType::Cpf && $value['text'] !== null ? CpfNumber::mask($value['text']) : null,
                    'cpf_check' => $cpfChecks[$field->ulid] ?? null,
                    'field_ulid' => $field->ulid,
                    'type' => $field->type->value,
                    'text' => $value['text'],
                    'bool' => $value['bool'],
                    'image_path' => match ($field->type) {
                        FieldType::Signature => $visual['image_path'],
                        FieldType::Initials => $visual['initials_image_path'] ?? $visual['image_path'],
                        default => null,
                    },
                    'typed_name' => $field->type->isImageBased() ? $visual['typed_name'] : null,
                    'typed_font' => $field->type->isImageBased() ? $visual['typed_font'] : null,
                ], fn ($v) => $v !== null);
            })
            ->values()
            ->all();
    }

    // -- Depois do aceite ---------------------------------------------------------------

    /**
     * Avança a ordem (sequencial) ou dispara a finalização quando ninguém mais falta.
     *
     * Só quem PARTICIPA (signatário, testemunha, aprovador) é pendência; o visualizador
     * nunca impede a conclusão (Fase 2 §2.4).
     *
     * Roda **depois** do commit do aceite e em transação própria: notificar o próximo é
     * consequência do aceite, não parte dele. Se falhar aqui, o aceite continua gravado e a
     * trilha mostra o que aconteceu.
     */
    private function advance(int $envelopeId, string $correlationId): void
    {
        /** @var array{envelope: Envelope, invite: list<Recipient>, finalize: bool, closed?: list<Recipient>, no_signature?: bool} $outcome */
        $outcome = DB::transaction(function () use ($envelopeId, $correlationId): array {
            /** @var Envelope $envelope */
            $envelope = Envelope::withoutOrganizationScope()->whereKey($envelopeId)->lockForUpdate()->firstOrFail();

            /** @var Collection<int, Recipient> $recipients */
            $recipients = Recipient::withoutOrganizationScope()
                ->where('envelope_id', $envelopeId)
                ->orderBy('order_index')
                ->orderBy('id')
                ->get();

            // Fase 3 §3.3 (F-FLOW): com etapas, a próxima etapa é avaliada AQUI, sob este mesmo
            // lock — condição falsa pula a etapa (participantes cancelados, nunca notificados) e o
            // envelope conclui quando não resta etapa aplicável. Sem etapas devolve a mesma coleção.
            $recipients = app(StepProgression::class)->prepare($envelope, $recipients, $correlationId);

            // Recusa de aprovador que aguardava o fim da própria etapa: nenhuma etapa posterior se
            // aplicou → a recusa encerra o envelope, como se tivesse sido a última decisão da etapa.
            $deferredRefusal = app(StepProgression::class)->deferredRefusal($envelope, $recipients);

            // Pedido de delegação de quem acabou de aceitar (ou cuja etapa não se aplicou) fica
            // sem efeito já aqui. Sem pedido pendente: uma consulta e nada mais.
            DelegationVoider::voidStale($envelope, $correlationId);

            $pending = $recipients->filter(fn (Recipient $r): bool => $r->isPendingParticipant());

            if ($pending->isEmpty()) {
                if ($envelope->status === EnvelopeStatus::InProgress && $deferredRefusal !== null) {
                    $canceled = app(RecordRefusal::class)->closeEnvelope(
                        $envelope,
                        $deferredRefusal,
                        (string) $deferredRefusal->refusal_reason,
                        Carbon::now(),
                        $correlationId,
                        ['deferred_until_step_end' => true],
                    );

                    DelegationVoider::voidStale($envelope, $correlationId);

                    return ['envelope' => $envelope, 'invite' => [], 'finalize' => false, 'closed' => $canceled];
                }

                // Regra de produto (revisão adversarial da onda F, docs/fase-3/etapas-e-delegacao.md
                // §2.4): com etapas, todas as etapas com signatário podem ter sido puladas. Um
                // documento nunca é "concluído" sem nenhuma assinatura — o envio já exigia um
                // signatário (EnvelopeReadiness). O envelope é encerrado sem assinatura (cancelado
                // pelo sistema, com o motivo), fora desta transação.
                if ($envelope->status === EnvelopeStatus::InProgress
                    && $envelope->usesSigningSteps()
                    && ! $recipients->contains(fn (Recipient $r): bool => $r->role === RecipientRole::Signer && $r->status === RecipientStatus::Signed)) {
                    return ['envelope' => $envelope, 'invite' => [], 'finalize' => false, 'no_signature' => true];
                }

                if ($envelope->status === EnvelopeStatus::InProgress) {
                    $envelope->transitionTo(EnvelopeStatus::Finalizing);
                    $envelope->finalization_key ??= (string) Str::ulid();
                    $envelope->save();

                    DelegationVoider::voidStale($envelope, $correlationId);

                    SignerAudit::system($envelope, AuditEventType::EnvelopeFinalizing, [
                        'acceptances' => $recipients->where('status', RecipientStatus::Signed)->count(),
                    ], null, $correlationId);

                    return ['envelope' => $envelope, 'invite' => [], 'finalize' => true];
                }

                return ['envelope' => $envelope, 'invite' => [], 'finalize' => false];
            }

            // Fase 3 §3.3 (F-FLOW): com etapas o paralelo também anda por vez (sem etapas = sequencial).
            if (! $envelope->hasTurns()) {
                return ['envelope' => $envelope, 'invite' => [], 'finalize' => false];
            }

            // Sequencial: a vez avança para a menor ordem que ainda tem alguém pendente.
            $nextOrder = (int) $pending->min('order_index');

            if ($nextOrder <= $envelope->current_order) {
                return ['envelope' => $envelope, 'invite' => [], 'finalize' => false];
            }

            $envelope->forceFill(['current_order' => $nextOrder])->save();

            $invite = $pending
                ->filter(fn (Recipient $r): bool => $r->order_index === $nextOrder && $r->status === RecipientStatus::Pending)
                ->values()
                ->all();

            return ['envelope' => $envelope, 'invite' => $invite, 'finalize' => false];
        });

        if ($outcome['invite'] !== []) {
            // Emitir o link novo e escrever o convite é do módulo de envio; aqui só se avisa
            // que a vez mudou (contrato SignerNotifications).
            $this->notifier->inviteRecipients($outcome['envelope'], $outcome['invite']);
        }

        if (isset($outcome['closed'])) {
            $this->notifier->notifyEnvelopeClosed($outcome['envelope'], $outcome['closed'], 'recipient_refused');
        }

        if (isset($outcome['no_signature'])) {
            app(CancelEnvelope::class)->handle($outcome['envelope'], self::NO_SIGNATURE_REASON);
        }

        if ($outcome['finalize']) {
            EnvelopeReadyForFinalization::dispatch(
                (int) $outcome['envelope']->getKey(),
                (int) $outcome['envelope']->organization_id,
                $outcome['envelope']->finalization_key,
                $correlationId,
            );
        }
    }
}
