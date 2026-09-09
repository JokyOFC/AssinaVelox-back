<?php

namespace App\Services\Signing;

use App\Enums\AuthMethod;
use App\Enums\DocumentVersionKind;
use App\Enums\EnvelopeStatus;
use App\Models\DocumentVersion;
use App\Models\Recipient;
use App\Models\SignatureAcceptance;
use App\Models\SigningSession;
use App\Models\User;
use App\Services\Verification\SignatureNarrative;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Props de `pages/sign/Show.tsx` (ROUTES §2.18), por `screen`.
 *
 * ## O princípio: dado mínimo por etapa
 *
 * A página é pública — basta ter o link. Então cada etapa recebe só o que aquela etapa
 * precisa mostrar:
 *
 * - **`identify`** (antes de confirmar o código): remetente, título, código do documento,
 *   prazo, e-mail **mascarado** do próprio destinatário, aviso de privacidade e estado do
 *   código. **Sem** URL do PDF, sem campos, sem os nomes dos demais participantes com
 *   detalhe, sem IP, sem e-mail de ninguém.
 * - **`sign`** (depois do código): tudo acima mais a URL do PDF (que ainda exige sessão para
 *   responder), os campos do próprio destinatário, a indicação dos campos de terceiros, o
 *   texto de aceite e o token de autorização.
 * - **`completed` / `finalizing` / `already_signed_pending_others`**: o comprovante do que
 *   foi registrado.
 * - **`refused` / `expired` / `canceled` / `invalid`**: só o suficiente para explicar.
 *
 * ## O que nunca sai daqui
 *
 * E-mail de outro participante (nem mascarado), IP de outra pessoa, valores preenchidos por
 * outro participante, token de convite de terceiros, código OTP, caminho de arquivo no
 * disco. O e-mail do próprio destinatário sai **mascarado**, porque a tela precisa dizer
 * para onde o código foi sem confirmar o endereço inteiro a quem roubou o link.
 */
final class SignerPageProps
{
    public function __construct(
        private readonly Challenges $challenges,
        private readonly SignerPresentation $presentation,
        private readonly SignerSessions $sessions,
        private readonly SignerDownloadGrants $grants,
    ) {}

    /**
     * Props do link que não abre. Idênticas para token inexistente, revogado, vencido, fora
     * da vez e de envelope em rascunho — a resposta não pode diferenciar os casos.
     *
     * @return array<string, mixed>
     */
    public static function invalid(): array
    {
        return [
            'token' => null,
            'screen' => 'invalid',
            'sender' => [
                'organization_name' => (string) config('app.name', 'AssinaVelox'),
                'organization_initials' => 'AV',
                'logo_url' => null,
                'user_name' => '',
            ],
            'envelope' => null,
            'recipient' => null,
            'others' => [],
            'signing_order' => 'sequential',
            'otp' => null,
            'document' => null,
            'my_fields' => [],
            'other_fields' => [],
            'signature_options' => self::signatureOptions(),
            'consent_text' => '',
            'consent' => null,
            'privacy' => null,
            'authorization' => null,
            'legal' => self::legal(),
            'receipt' => null,
            'refusal' => null,
            'auth_methods' => [AuthMethod::EmailOtp->value],
            'limits' => self::limits(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function build(SignerContext $context, Request $request, ?SigningSession $session = null): array
    {
        $screen = $this->screen($context, $session);
        $version = $context->sentVersion();

        $recipients = Recipient::withoutOrganizationScope()
            ->where('envelope_id', $context->envelope->getKey())
            ->orderBy('order_index')
            ->orderBy('id')
            ->get();

        $props = [
            'token' => $context->token,
            'screen' => $screen,
            'sender' => $this->sender($context),
            'envelope' => $this->envelope($context, $version),
            'recipient' => $this->recipient($context),
            'others' => $this->others($context, $recipients),
            'signing_order' => $context->envelope->signing_order->value,
            'otp' => $screen === 'identify' ? $this->challenges->props($context) : null,
            'document' => null,
            'my_fields' => [],
            'other_fields' => [],
            'signature_options' => self::signatureOptions(),
            'consent_text' => '',
            'consent' => null,
            'privacy' => [
                'version' => ConsentText::PRIVACY_NOTICE_VERSION,
                'summary' => ConsentText::privacySummary($context->organization),
                'notice' => ConsentText::privacyNotice($context->organization),
            ],
            'authorization' => null,
            'legal' => self::legal(),
            'receipt' => null,
            'refusal' => $this->refusal($context),
            'auth_methods' => [AuthMethod::EmailOtp->value],
            'limits' => self::limits(),
        ];

        if ($screen === 'sign' && $session !== null && $version !== null) {
            $props = array_replace($props, $this->signingProps($context, $session, $version));
        }

        if (in_array($screen, ['completed', 'finalizing', 'already_signed_pending_others'], true)) {
            $props['receipt'] = $this->receipt($context, $recipients, $request);
        }

        return $props;
    }

    /**
     * `identify` vs `sign` depende da sessão do navegador; os demais vêm do estado do convite.
     */
    public function screen(SignerContext $context, ?SigningSession $session): string
    {
        if (! $context->isActive()) {
            return $context->state;
        }

        return $session !== null ? 'sign' : 'identify';
    }

    // -- Blocos -------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function signingProps(SignerContext $context, SigningSession $session, DocumentVersion $version): array
    {
        $fields = $this->presentation->myFields($context, $version);
        $consentText = ConsentText::statement($context->envelope, $context->recipient, $context->organization, $version->sha256);

        $snapshot = $this->presentation->snapshot($context, $version, $fields, $consentText);
        $authorization = $this->sessions->issueAuthorization($session, SignerPresentation::hash($snapshot));

        return [
            'document' => [
                // Exige sessão autenticada: o PDF nunca é servido por URL pública.
                'pdf_url' => route('sign.document', ['token' => $context->token]),
                // Miniatura PNG no servidor foi descontinuada (docs/preparacao-documental.md):
                // o rail de páginas é renderizado no navegador com PDF.js.
                'page_thumb_url_template' => null,
                'page_sizes' => $this->pageSizes($version),
                'sha256' => $version->sha256,
            ],
            'my_fields' => $this->presentation->myFieldProps($fields, $context),
            'other_fields' => $this->presentation->otherFields($context, $version),
            'consent_text' => $consentText,
            'consent' => [
                'version' => ConsentText::versionFor($context->envelope),
                'checkbox_label' => ConsentText::checkboxLabel($context->envelope),
                'statement' => $consentText,
                'completion_notice' => ConsentText::completionNotice(),
            ],
            'authorization' => [
                'token' => $authorization,
                'expires_at' => $session->refresh()->authorization_expires_at?->toIso8601String(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sender(SignerContext $context): array
    {
        /** @var User|null $creator */
        $creator = User::query()->whereKey($context->envelope->created_by_user_id)->first();

        return [
            'organization_name' => $context->organization->name,
            'organization_initials' => $context->organization->initials,
            'logo_url' => null,
            // Nome do remetente, sem e-mail: a pessoa precisa saber com quem falar, e o
            // canal de contato é a organização, não o endereço pessoal de quem enviou.
            'user_name' => $creator === null ? $context->organization->name : $creator->name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function envelope(SignerContext $context, ?DocumentVersion $version): array
    {
        $envelope = $context->envelope;

        return [
            'display_code' => $envelope->display_code,
            'title' => $envelope->title,
            'pages' => (int) ($version->page_count ?? 0),
            'sent_at' => $envelope->sent_at?->toIso8601String(),
            'expires_at' => $envelope->expires_at?->toIso8601String(),
            'status' => $envelope->status->value,
            'completed_at' => $envelope->completed_at?->toIso8601String(),
            'message' => $envelope->message,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function recipient(SignerContext $context): array
    {
        $recipient = $context->recipient;
        $parts = preg_split('/\s+/', trim($recipient->name)) ?: [];

        return [
            'first_name' => $parts[0] ?? $recipient->name,
            'name' => $recipient->name,
            'role' => SignerPresentation::roleLabel($recipient),
            'email_masked' => $recipient->masked_email,
            'status' => $recipient->status->value,
            'order' => (int) $recipient->order_index,
        ];
    }

    /**
     * Demais participantes: nome, papel, ordem, status e se assinam depois. **Sem e-mail.**
     *
     * @param  Collection<int, Recipient>  $recipients
     * @return list<array<string, mixed>>
     */
    private function others(SignerContext $context, Collection $recipients): array
    {
        $sequential = $context->envelope->isSequential();
        $myOrder = (int) $context->recipient->order_index;

        /** @var list<array<string, mixed>> */
        return $recipients
            ->reject(fn (Recipient $r): bool => $r->getKey() === $context->recipient->getKey())
            ->map(fn (Recipient $r): array => [
                'name' => $r->name,
                'role' => SignerPresentation::roleLabel($r),
                'order' => (int) $r->order_index,
                'status' => $r->status->value,
                'signs_after_me' => $sequential && (int) $r->order_index > $myOrder,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function refusal(SignerContext $context): ?array
    {
        if ($context->state !== SignerContext::STATE_REFUSED) {
            return null;
        }

        return [
            'refused_at' => $context->recipient->refused_at?->toIso8601String(),
            'reason' => (string) $context->recipient->refusal_reason,
        ];
    }

    /**
     * Comprovante do aceite. O IP exibido respeita `settings.evidence_show_ip`; o IP gravado
     * é sempre completo (é evidência), o que muda é o que a tela mostra.
     *
     * `can_download` diz se ESTE navegador ainda está dentro da janela de download
     * (arquitetura §4.7). Sem ele a tela oferecia botões que o servidor responde com 404 —
     * a autorização deixou de ser "existe um aceite" e passou a ser
     * {@see SignerDownloadGrants}.
     *
     * @param  Collection<int, Recipient>  $recipients
     * @return array<string, mixed>|null
     */
    private function receipt(SignerContext $context, Collection $recipients, Request $request): ?array
    {
        /** @var SignatureAcceptance|null $acceptance */
        $acceptance = SignatureAcceptance::withoutOrganizationScope()
            ->where('recipient_id', $context->recipient->getKey())
            ->first();

        if ($acceptance === null) {
            return null;
        }

        $final = $context->envelope->final_document_version_id === null
            ? null
            : DocumentVersion::withoutOrganizationScope()
                ->whereKey($context->envelope->final_document_version_id)
                ->where('kind', DocumentVersionKind::Final->value)
                ->first();

        $mode = (string) $context->organization->setting('evidence_show_ip', 'masked');

        return [
            'signed_at' => $acceptance->accepted_at->toIso8601String(),
            'verification_code' => (string) ($context->envelope->verification_code ?? ''),
            // Resumo dos bytes que a pessoa viu. O resumo do arquivo FINAL só existe depois
            // da finalização e é publicado na página de verificação.
            'document_sha256' => $acceptance->document_sha256,
            'signed_sha256' => $final?->sha256,
            'ip' => SignerRequestFacts::displayIp($acceptance->ip_address, $mode),
            'auth_label' => $acceptance->auth_method->label(),
            'terms_version' => $acceptance->terms_version,
            'download_url' => route('sign.download', ['token' => $context->token, 'type' => 'evidence']),
            'final_pdf_url' => route('sign.download', ['token' => $context->token, 'type' => 'signed']),
            'final_pdf_available' => $final !== null,
            'can_download' => $this->grants->current($context, $request) !== null
                || $this->sessions->current($context, $request) !== null,
            'pending_others' => $recipients
                ->reject(fn (Recipient $r): bool => $r->getKey() === $context->recipient->getKey())
                ->filter(fn (Recipient $r): bool => $r->status->isPendingSignature())
                ->count(),
            /*
             * A coleta terminou SEM conclusão? Quem já tinha assinado continua com acesso ao
             * próprio comprovante (é a intenção de `SignerLinkResolver::stateFor`), mas a tela
             * caía em `already_signed_pending_others` e prometia "você receberá o arquivo final
             * quando todos os participantes concluírem" — ou, com zero pendentes, "o arquivo
             * final está sendo preparado". Nos dois casos falso: nenhum arquivo final virá.
             * Mesmo motivo pelo qual `STATE_FINALIZING` foi criado; aqui o fato vai como dado,
             * sem inventar uma tela nova.
             */
            'collection_closed' => match ($context->envelope->status) {
                EnvelopeStatus::Expired => 'expired',
                EnvelopeStatus::Canceled => 'canceled',
                EnvelopeStatus::Refused => 'refused',
                default => null,
            },
            /*
             * O que a plataforma afirma sobre a conclusão. Enquanto a coleta corre é uma
             * previsão derivada da configuração (`ConsentText::completionNotice()`); depois
             * que o envelope entra num estado terminal é um FATO, e o fato é o que
             * `verification_records` registrou. Resolver pela configuração do momento da
             * visita faria o comprovante de um arquivo sem assinatura nenhuma passar a
             * prometer assinatura criptográfica assim que o certificado fosse ligado —
             * exatamente o que a arquitetura §2 proíbe — além de falar no futuro numa tela
             * intitulada "Documento concluído".
             */
            'completion_notice' => $context->envelope->status->isTerminal()
                ? SignatureNarrative::for($context->envelope, $context->envelope->verificationRecord)['statement']
                : ConsentText::completionNotice(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function pageSizes(DocumentVersion $version): array
    {
        $sizes = [];

        foreach ($version->pages_meta ?? [] as $index => $page) {
            $sizes[] = [
                'page' => $index + 1,
                'width_pt' => (float) ($page['width_pt'] ?? 0),
                'height_pt' => (float) ($page['height_pt'] ?? 0),
                'rotation' => (int) ($page['rotation'] ?? 0),
            ];
        }

        return $sizes;
    }

    // -- Constantes de tela ---------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private static function signatureOptions(): array
    {
        return [
            'draw' => true,
            'type' => true,
            'upload' => true,
            'fonts' => RecordAcceptance::FONTS,
            // Certificado ICP-Brasil é Fase 2 (RECONCILIACAO §5): oculto, nunca simulado.
            'certificate' => false,
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function legal(): array
    {
        return [
            'terms_url' => route('legal.terms'),
            'privacy_url' => route('legal.privacy'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function limits(): array
    {
        return [
            'otp_length' => (int) config('assinavelox.otp.code_length', 6),
            'otp_ttl_minutes' => (int) config('assinavelox.otp.ttl_minutes', 10),
            'otp_max_attempts' => (int) config('assinavelox.otp.max_attempts', 5),
            'max_text_length' => (int) config('assinavelox.signing_session.max_text_field_length', 500),
            'signature_image_max_kb' => (int) config('assinavelox.signing_session.signature_image.max_decoded_kb', 3072),
            'refusal_reason' => ['min' => RecordRefusal::MIN_REASON, 'max' => RecordRefusal::MAX_REASON],
            'typed_name' => ['min' => 2, 'max' => 80],
        ];
    }
}
