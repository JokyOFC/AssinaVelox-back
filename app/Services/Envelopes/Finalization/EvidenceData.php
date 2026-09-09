<?php

namespace App\Services\Envelopes\Finalization;

use App\Enums\SignatureStatus;
use App\Models\AuditEvent;
use App\Models\CertificateReference;
use App\Models\DocumentVersion;
use App\Models\Envelope;
use App\Models\SignatureAcceptance;
use App\Services\Envelopes\Finalization\Support\QrCode;
use App\Services\Signing\ConsentText;
use App\Support\IpDisplay;
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
            ],
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
        ];
    }

    /**
     * Participantes conforme a política de privacidade da organização.
     *
     * @return list<array<string, mixed>>
     */
    private function participants(Envelope $envelope, string $timezone): array
    {
        $organization = $envelope->organization;

        $rows = [];

        foreach ($envelope->recipients()->with('acceptance')->get() as $recipient) {
            /** @var SignatureAcceptance|null $acceptance */
            $acceptance = $recipient->acceptance;

            $rows[] = [
                'name' => $recipient->name,
                'email' => $recipient->email,
                'status' => $recipient->status->value,
                'status_label' => $recipient->status->label(),
                'order_index' => (int) $recipient->order_index,
                'auth_method' => $recipient->auth_method->label(),
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
