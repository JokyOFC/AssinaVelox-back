<?php

namespace App\Services\Envelopes\Finalization;

use App\Enums\AuditEventType;
use App\Enums\RecipientRole;
use App\Enums\SignatureStatus;
use App\Models\AcceptanceDocument;
use App\Models\AuditEvent;
use App\Models\CertificateReference;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Models\SignatureAcceptance;
use App\Services\Branding\BrandingPresenter;
use App\Services\Branding\Stamp\StampEvidence;
use App\Services\Envelopes\Finalization\Support\QrCode;
use App\Services\Envelopes\Steps\FlowEvidence;
use App\Services\Identity\VerificationEvidence;
use App\Services\Identity\VideoEvidence;
use App\Services\InPerson\InPersonEvidence;
use App\Services\Signing\Channels\SenderPins;
use App\Services\Signing\Channels\SimulatedChannelEvidence;
use App\Services\Signing\ConsentText;
use App\Support\IpDisplay;
use App\Support\Locale\SignerLocale;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Carbon;

/**
 * Dados da página de evidências (arquitetura §5 item 7, docs/juridico §5).
 *
 * Três decisões que estão aqui e não no Blade:
 *
 * 1. **O hash final não existe nesta etapa.** A página é montada ANTES da assinatura, e o
 *    hash final é dos bytes do arquivo pronto — que ainda não existe. Por isso o array de
 *    hashes tem exatamente três entradas (original, enviado, consolidado) e o texto explica
 *    onde o quarto é publicado. Um "hash final" impresso aqui seria falso por construção.
 * 2. **A política de privacidade da organização governa o IP** (`evidence_show_ip` =
 *    masked | full | none), pela mesma classe que a interface usa (`IpDisplay`), para que a
 *    tela e o PDF nunca discordem.
 * 3. **A variante do bloco de assinatura descreve o resultado real** da finalização, não a
 *    intenção: `company_a1` só quando a assinatura foi de fato aplicada e validada.
 */
class EvidenceData
{
    public function __construct(private readonly Repository $config) {}

    /**
     * @param  array<string, string|null>  $hashes  original, sent, consolidated
     * @param  array<string, mixed>|null  $validationResult
     * @param  list<array<string, mixed>>  $documents  Fase 2 §2.3: todos os documentos do envelope
     *                                                 (vazio com um documento só)
     * @param  int|null  $position  posição do documento DESTA página (vários documentos)
     * @return array<string, mixed>
     */
    public function build(
        Envelope $envelope,
        DocumentVersion $sentVersion,
        array $hashes,
        SignatureStatus $signatureStatus,
        ?string $signatureProfile = null,
        ?CertificateReference $certificate = null,
        ?array $validationResult = null,
        ?CarbonInterface $generatedAt = null,
        array $documents = [],
        ?int $position = null,
        bool $participantMode = false,
    ): array {
        $organization = $envelope->organization;
        $timezone = $organization->timezone !== '' ? $organization->timezone : (string) $this->config->get('app.timezone', 'UTC');
        $generatedAt ??= Carbon::now();

        $verificationUrl = ConsentText::verificationUrl();
        $code = $envelope->formatted_verification_code ?? '—';
        $publicUrl = $envelope->verification_code !== null
            ? $verificationUrl.'/'.$envelope->verification_code
            : $verificationUrl;

        return [
            'envelope' => [
                'title' => $envelope->title,
                'display_code' => $envelope->display_code,
                'verification_code' => $code,
                'created_at' => $this->local($envelope->created_at, $timezone),
                'sent_at' => $this->local($envelope->sent_at, $timezone),
                'completed_at' => $this->local($generatedAt, $timezone),
                'signing_order' => $envelope->signing_order->value,
                'terms_version' => ConsentText::versionFor($envelope),
                'page_count' => (int) ($sentVersion->page_count ?? 0),
            ],
            'organization' => [
                'name' => $organization->name,
                'legal_name' => $organization->legal_name,
                'timezone' => $timezone,
            ],
            'operator' => [
                'name' => ConsentText::operatorLegalName(),
            ],
            'participants' => $this->participants($envelope, $timezone),
            'timeline' => $this->timeline($envelope, $timezone),
            'hashes' => [
                'original' => $hashes['original'] ?? null,
                'sent' => $hashes['sent'] ?? null,
                'consolidated' => $hashes['consolidated'] ?? null,
            ],
            'signature' => [
                'status' => $signatureStatus->value,
                'signed' => $signatureStatus === SignatureStatus::CompanyA1,
                'profile' => $signatureProfile,
                'certificate' => $certificate === null ? null : [
                    'subject' => $certificate->subject,
                    'issuer' => $certificate->issuer,
                    'serial' => $certificate->serial_number,
                    'environment' => $certificate->environment->value,
                    'not_before' => $this->local($certificate->not_before, $timezone),
                    'not_after' => $this->local($certificate->not_after, $timezone),
                ],
                'validation_summary' => $this->validationSummary($validationResult),
            ] + ($participantMode ? [
                // Fase 2 §2.12 (K-A1): participantes assinam com o próprio certificado DEPOIS
                // desta página (revisões incrementais). Chave presente só nesse modo.
                'participant_mode' => true,
            ] : []),
            'verification' => [
                'url' => $verificationUrl,
                'public_url' => $publicUrl,
                'code' => $code,
                'qr' => QrCode::dataUri(
                    $publicUrl,
                    (int) $this->config->get('assinavelox.evidence.qr.module_px', 4),
                    (int) $this->config->get('assinavelox.evidence.qr.quiet_zone', QrCode::MIN_QUIET_ZONE),
                ),
                'footer_line' => ConsolidationPlanner::footerText($envelope),
            ],
            'generated_at' => [
                'utc' => $generatedAt->copy()->utc()->format('d/m/Y H:i:s'),
                'local' => $this->local($generatedAt, $timezone),
            ],
            // Fase 2 (aditivos; vazios/nulos num envelope de um documento só com signatários).
            'documents' => $this->documentRows($documents, $timezone),
            'document' => $documents === [] ? null : $this->currentDocument($documents, $position),
            'viewers' => $this->viewers($envelope),
            'has_roles' => $envelope->recipients()->where('role', '!=', RecipientRole::Signer->value)->exists(),
            // Fase 2 §2.8 (C-BRAND): logo da organização remetente no cabeçalho (flag
            // `branding` + logo salvo) e a linha do carimbo visual. Null = página da Fase 1.
            'branding' => app(BrandingPresenter::class)->forEvidence($organization),
            'stamp' => StampEvidence::forEnvelope($envelope, (int) $sentVersion->getKey()),
            // Fase 3 §3.3 (F-FLOW): a chave só existe com etapas ou delegações no envelope.
            ...(($flow = FlowEvidence::forEnvelope($envelope, $timezone)) !== null ? ['flow' => $flow] : []),
        ];
    }

    /**
     * Seção "Documentos deste envelope": cada documento com seus resumos e quem registrou
     * aceite/aprovação sobre ele (`acceptance_documents`).
     *
     * @param  list<array<string, mixed>>  $documents
     * @return list<array<string, mixed>>
     */
    private function documentRows(array $documents, string $timezone): array
    {
        $rows = [];

        foreach ($documents as $row) {
            /** @var Document $document */
            $document = $row['document'];

            $accepted = AcceptanceDocument::withoutOrganizationScope()
                ->with('acceptance.recipient')
                ->where('document_id', $document->getKey())
                ->orderBy('id')
                ->get()
                ->map(function (AcceptanceDocument $item) use ($timezone): array {
                    $acceptance = $item->acceptance;
                    $recipient = $acceptance?->recipient;

                    return [
                        'name' => $recipient->name ?? '—',
                        'role_label' => $recipient?->role->label(),
                        'action_label' => $acceptance?->action->label(),
                        'accepted_at' => $this->local($acceptance?->accepted_at, $timezone),
                        'document_sha256' => $item->document_sha256,
                    ];
                })
                ->values()
                ->all();

            $rows[] = [
                'position' => (int) ($row['position'] ?? $document->position),
                'name' => (string) ($row['name'] ?? $document->name),
                'page_count' => (int) ($row['page_count'] ?? 0),
                'hashes' => $row['hashes'] ?? [],
                'accepted_by' => $accepted,
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $documents
     * @return array{position: int, count: int, name: string}
     */
    private function currentDocument(array $documents, ?int $position): array
    {
        $current = collect($documents)->first(fn (array $row): bool => (int) ($row['position'] ?? 0) === $position) ?? $documents[0];

        return [
            'position' => (int) ($current['position'] ?? 1),
            'count' => count($documents),
            'name' => (string) ($current['name'] ?? ''),
        ];
    }

    /**
     * Quem recebeu cópia sem participar da coleta (visualizadores, Fase 2 §2.4).
     *
     * @return list<array{name: string, email: string}>
     */
    private function viewers(Envelope $envelope): array
    {
        return array_values($envelope->recipients()
            ->where('role', RecipientRole::Viewer->value)
            ->get()
            ->map(fn (Recipient $recipient): array => ['name' => $recipient->name, 'email' => $recipient->email])
            ->all());
    }

    /**
     * Participantes conforme a política de privacidade da organização.
     *
     * @return list<array<string, mixed>>
     */
    private function participants(Envelope $envelope, string $timezone): array
    {
        $organization = $envelope->organization;
        $pins = app(SenderPins::class);
        $inPerson = collect(InPersonEvidence::forEnvelope($envelope))->keyBy('recipient_id')->all();
        // Fase 3 §3.3 (F-VIDEO): o vídeo curto é só CITADO (tipo, SHA-256, origem declarada); nunca embutido.
        $videos = app(VideoEvidence::class)->pdfLines($envelope);
        // Fase 4 §4.1: o que o PROVEDOR informou da verificação facial com documento vinculada ao aceite.
        $verifications = app(VerificationEvidence::class)->pdfLines($envelope, $timezone);
        // Fase 3 §3.3 (F-FLOW): delegação e etapa pulada por participante (sem elas, vazio).
        $flowNotes = FlowEvidence::participantNotes($envelope, $timezone);

        $rows = [];

        foreach ($envelope->recipients()->with('acceptance')->get() as $recipient) {
            // Visualizador não participa da coleta: vai para a lista `viewers`.
            if (! $recipient->participates()) {
                continue;
            }

            /** @var SignatureAcceptance|null $acceptance */
            $acceptance = $recipient->acceptance;

            $rows[] = [
                // Fase 2 §2.4: papel de domínio e o que o aceite registrou.
                'role' => $recipient->role->value,
                'role_label' => $recipient->role->label(),
                'action_label' => $acceptance?->action->label(),
                'name' => $recipient->name,
                'email' => $recipient->email,
                'status' => $recipient->status->value,
                'status_label' => $recipient->status->label(),
                'order_index' => (int) $recipient->order_index,
                // Fase 2 §2.9 (C-CAN): o canal do código que o participante confirmou, + PIN do
                // remetente quando houve. Fase 1: "Código por e-mail", como antes.
                'auth_method' => ($acceptance->auth_method ?? $recipient->auth_method)->label()
                    // Revisão da onda B: código "enviado" pelo simulador não chegou a celular nenhum.
                    .(SimulatedChannelEvidence::wasSimulated($recipient) ? SimulatedChannelEvidence::LABEL_SUFFIX : '')
                    .($pins->requiredFor($recipient) ? ' + PIN do remetente' : ''),
                // Fase 2 §2.6 (C-PRES): aceite registrado no dispositivo presencial.
                'in_person_label' => $inPerson[$recipient->ulid]['label'] ?? null,
                // F-VIDEO: a chave só existe para quem enviou vídeo (sem vídeo, dados idênticos).
                ...(isset($videos[$recipient->ulid]) ? ['identity_video_label' => $videos[$recipient->ulid]] : []),
                // Fase 4 §4.1: as chaves só existem para quem teve a verificação facial aprovada e
                // vinculada ao aceite (sem ela, dados idênticos). O aviso diz quem comparou.
                ...(isset($verifications[$recipient->ulid]) ? [
                    'identity_verification_label' => $verifications[$recipient->ulid],
                    'identity_verification_notice' => VerificationEvidence::NOTICE,
                ] : []),
                // F-I18N: a chave só existe quando o aceite registrou o idioma exibido (flag ligada).
                ...($acceptance?->display_locale !== null ? ['display_locale_label' => SignerLocale::evidenceLabel($acceptance->display_locale)] : []),
                // F-FLOW: a chave só existe para quem delegou, recebeu por delegação ou ficou numa etapa pulada.
                ...($flowNotes[(int) $recipient->getKey()] ?? []),
                'signed_at' => $this->local($acceptance->accepted_at ?? $recipient->signed_at, $timezone),
                'signed_at_utc' => $acceptance?->accepted_at->copy()->utc()->format('d/m/Y H:i:s'),
                'refused_at' => $this->local($recipient->refused_at, $timezone),
                'refusal_reason' => $recipient->refusal_reason,
                'ip' => IpDisplay::for($acceptance?->ip_address, $organization),
                'user_agent' => $acceptance?->user_agent,
                'signature_kind' => $acceptance?->signature_kind?->label(),
                'typed_name' => $acceptance?->typed_name,
                'document_sha256' => $acceptance?->document_sha256,
                'terms_version' => $acceptance?->terms_version,
            ];
        }

        return $rows;
    }

    /**
     * Eventos relevantes da trilha (a lista fica em config; o resto é ruído interno).
     *
     * @return list<array<string, mixed>>
     */
    private function timeline(Envelope $envelope, string $timezone): array
    {
        /** @var list<string> $types */
        $types = (array) $this->config->get('assinavelox.evidence.timeline_events', []);

        // A aprovação (Fase 2 §2.4) é tão evidência quanto o aceite: entra na linha do tempo
        // sempre que a lista configurada incluir o aceite.
        if (in_array(AuditEventType::AcceptanceRecorded->value, $types, true)) {
            $types[] = AuditEventType::ApprovalRecorded->value;
            // Fase 3 §3.3 (F-FLOW): delegação e etapas também são evidência. Só existem com as
            // flags `delegation`/`conditional_steps`; sem elas a linha do tempo é a de antes.
            $types[] = AuditEventType::DelegationRequested->value;
            $types[] = AuditEventType::RecipientDelegated->value;
            $types[] = AuditEventType::DelegationRejected->value;
            $types[] = AuditEventType::EnvelopeStepStarted->value;
            $types[] = AuditEventType::EnvelopeStepSkipped->value;
        }
        $limit = max(10, (int) $this->config->get('assinavelox.evidence.timeline_limit', 200));

        $events = AuditEvent::query()
            ->with('recipient')
            ->where('envelope_id', $envelope->getKey())
            ->when($types !== [], fn ($query) => $query->whereIn('event_type', $types))
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $rows = [];

        foreach ($events as $event) {
            $rows[] = [
                'at' => $this->local($event->occurred_at, $timezone),
                'at_utc' => $event->occurred_at->copy()->utc()->format('d/m/Y H:i:s'),
                'type' => $event->event_type->value,
                'label' => $event->event_type->label(),
                'actor' => $event->actor_type->value,
                'recipient' => $event->recipient?->name,
            ];
        }

        return $rows;
    }

    /**
     * Frase técnica curta do resultado da validação. Nunca afirma confiança que o pdftool
     * não afirmou, e diz explicitamente o que não foi verificado.
     *
     * @param  array<string, mixed>|null  $validationResult
     */
    private function validationSummary(?array $validationResult): ?string
    {
        if ($validationResult === null) {
            return null;
        }

        $result = is_array($validationResult['result'] ?? null) ? $validationResult['result'] : null;

        if ($result === null) {
            return null;
        }

        $count = (int) ($result['signature_count'] ?? 0);
        $intact = ($result['all_intact'] ?? false) === true;
        $valid = ($result['all_valid'] ?? false) === true;
        $trusted = ($result['all_trusted'] ?? false) === true;

        return sprintf(
            '%d assinatura(s); integridade: %s; validade criptográfica: %s; cadeia de confiança: %s; revogação (CRL/OCSP): não verificada; carimbo do tempo: ausente.',
            $count,
            $intact ? 'confirmada' : 'não confirmada',
            $valid ? 'confirmada' : 'não confirmada',
            $trusted ? 'validada até uma raiz configurada' : 'não validada nesta instalação',
        );
    }

    private function local(?CarbonInterface $value, string $timezone): ?string
    {
        return $value?->copy()->setTimezone($timezone)->format('d/m/Y H:i:s');
    }
}
