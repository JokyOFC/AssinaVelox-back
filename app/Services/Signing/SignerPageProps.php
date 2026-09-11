<?php

namespace App\Services\Signing;

use App\Enums\AuthMethod;
use App\Enums\DocumentVersionKind;
use App\Enums\EnvelopeStatus;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Recipient;
use App\Models\SignatureAcceptance;
use App\Models\SigningSession;
use App\Models\SigningSessionDocument;
use App\Models\User;
use App\Services\Branding\BrandingPresenter;
use App\Services\Identity\CaptureStep;
use App\Services\Signing\Certificates\ParticipantCertificateService;
use App\Services\Signing\Channels\SignerAuthProps;
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
 * - **`view`** (Fase 2 §2.4, visualizador depois do código): o(s) documento(s) em modo
 *   leitura e, depois da conclusão, a cópia final. Sem campos, sem aceite, sem autorização.
 * - **`completed` / `finalizing` / `already_signed_pending_others`**: o comprovante do que
 *   foi registrado.
 * - **`refused` / `expired` / `canceled` / `invalid`**: só o suficiente para explicar.
 *
 * ## Fase 2 (aditivo — docs/fase-2/multi-documento-e-papeis.md §8)
 *
 * `documents` (um item por arquivo), `action` (o que o botão faz: assinar, assinar como
 * testemunha, aprovar ou só visualizar), `copy` (cópia do visualizador) e o papel de domínio
 * em `recipient`/`others`. Com um documento e papel `signer`, os campos da Fase 1 continuam
 * idênticos.
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
        // Fase 2, onda B: canal do código e PIN (C-CAN), captura simples (C-ID), marca (C-BRAND).
        private readonly SignerAuthProps $auth,
        private readonly CaptureStep $captureStep,
        private readonly BrandingPresenter $branding,
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
            'documents' => [],
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
            'action' => null,
            'copy' => null,
            // Fase 2, onda B: nada a dizer sobre um link que não abre.
            'signer_auth' => null,
            'identity_capture' => null,
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
            'documents' => [],
            'my_fields' => [],
            'other_fields' => [],
            'signature_options' => self::signatureOptions(),
            'consent_text' => '',
            'consent' => null,
            'privacy' => [
                // O texto depende do papel: o visualizador não registra aceite e o aprovador
                // não grava imagem de assinatura (Fase 2 §2.4, T1).
                // Fase 2, onda B: o texto também depende do canal do código, do PIN, do campo
                // CPF e das fotos exigidas deste participante (idêntico ao da Fase 1 sem eles).
                'version' => ConsentText::privacyNoticeVersion($context->recipient->role, $context->recipient),
                'summary' => ConsentText::privacySummary($context->organization, $context->recipient->role, $context->recipient),
                'notice' => ConsentText::privacyNotice($context->organization, $context->recipient->role, $context->recipient),
            ],
            'authorization' => null,
            'legal' => self::legal(),
            'receipt' => null,
            'refusal' => $this->refusal($context),
            // Fase 2 §2.9 (C-CAN): o método do participante e, quando há PIN, `sender_pin`.
            // Participante da Fase 1: `['email_otp']`, como antes.
            'auth_methods' => $this->auth->authMethods($context),
            'limits' => self::limits(),
            'action' => self::action($context),
            'copy' => null,
            // Fase 2 §2.9 (C-CAN): canal do código, destino mascarado e etapa do PIN. Nome
            // `signer_auth` (e não `auth`) para não sombrear a prop compartilhada `auth.user`.
            'signer_auth' => $screen === 'identify' ? $this->auth->for($context, $request) : null,
            // Fase 2 §2.10 (C-ID): etapa de captura simples; null quando não se aplica.
            'identity_capture' => in_array($screen, ['identify', 'sign'], true)
                ? $this->captureStep->props($context, $screen === 'sign' ? $session : null)
                : null,
        ];

        if ($screen === 'sign' && $session !== null && $version !== null) {
            $props = array_replace($props, $this->signingProps($context, $session, $version));
        }

        if ($screen === 'view' && $session !== null) {
            $props = array_replace($props, $this->viewProps($context, $session, $request));
        }

        if (in_array($screen, ['completed', 'finalizing', 'already_signed_pending_others'], true)) {
            $props['receipt'] = $this->receipt($context, $recipients, $request);

            // Fase 2 §2.12: quem já aceitou e volta para enviar o certificado sem a janela de
            // download precisa confirmar a identidade de novo. As props do código (e do canal/
            // PIN) vêm também no comprovante — sem elas o cartão do certificado era um beco
            // sem saída. Só enquanto o envio do certificado está aberto para esta pessoa.
            $certificates = app(ParticipantCertificateService::class);

            if ($certificates->awaitsReturningSigner($context) && ! $certificates->authenticated($context, $request)) {
                $props['otp'] = $this->challenges->props($context);
                $props['signer_auth'] = $this->auth->for($context, $request);
            }
        }

        return $props;
    }

    /**
     * A assinatura com o certificado do PRÓPRIO participante é oferecida a esta pessoa? A
     * previsão de conclusão fica condicional (T1): pode haver assinatura criptográfica dela.
     */
    private function participantCertificateOffered(SignerContext $context): bool
    {
        return app(ParticipantCertificateService::class)->offeredTo($context);
    }

    /**
     * `identify` vs `sign`/`view` depende da sessão do navegador; os demais vêm do estado do
     * convite. O visualizador nunca chega a `sign`: depois do código ele vê `view`.
     */
    public function screen(SignerContext $context, ?SigningSession $session): string
    {
        if (! $context->isActive()) {
            return $context->state;
        }

        if ($session === null) {
            return 'identify';
        }

        return $context->isViewer() ? 'view' : 'sign';
    }

    // -- Blocos -------------------------------------------------------------------------

    /**
     * O que o botão principal faz (Fase 2 §2.4).
     *
     * @return array{type: string, label: string, button_label: string|null, requires_signature: bool, requires_consent: bool}
     */
    public static function action(SignerContext $context): array
    {
        $action = $context->action();

        if ($action === null) {
            return [
                'type' => 'view',
                'label' => 'Cópia para acompanhamento',
                'button_label' => null,
                'requires_signature' => false,
                'requires_consent' => false,
            ];
        }

        return [
            'type' => $action->value,
            'label' => $action->label(),
            'button_label' => $action->buttonLabel(),
            'requires_signature' => $action->requiresVisualSignature(),
            'requires_consent' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function signingProps(SignerContext $context, SigningSession $session, DocumentVersion $version): array
    {
        $sent = $context->sentDocuments();
        $multi = count($sent) > 1;

        $fields = $multi
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

        $snapshot = $sent === []
            ? $this->presentation->snapshot($context, $version, $fields, $consentText)
            : $this->presentation->snapshotFor($context, $sent, $fields, $consentText);

        $authorization = $this->sessions->issueAuthorization($session, SignerPresentation::hash($snapshot));
        $count = max(1, count($sent));

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
            'documents' => $this->documentList($context, $session, $sent),
            'my_fields' => $this->presentation->myFieldProps($fields, $context, SignerPresentation::documentUlidsByVersion($sent)),
            'other_fields' => $multi
                ? $this->presentation->otherFieldsForDocuments($context, $sent)
                : $this->presentation->otherFields($context, $version),
            'consent_text' => $consentText,
            'consent' => [
                'version' => ConsentText::versionFor($context->envelope, $context->recipient, $count),
                'checkbox_label' => ConsentText::checkboxLabel($context->envelope, $context->recipient, $count),
                'statement' => $consentText,
                'completion_notice' => ConsentText::completionNotice(null, $this->participantCertificateOffered($context)),
            ],
            'authorization' => [
                'token' => $authorization,
                'expires_at' => $session->refresh()->authorization_expires_at?->toIso8601String(),
            ],
        ];
    }

    /**
     * Tela `view` do visualizador: documentos em modo leitura e, concluído o envelope, a cópia
     * final. Nada de campos, aceite ou autorização.
     *
     * @return array<string, mixed>
     */
    private function viewProps(SignerContext $context, SigningSession $session, Request $request): array
    {
        $sent = $context->sentDocuments();
        $first = $sent[0]['version'] ?? null;
        $completed = $context->envelope->status === EnvelopeStatus::Completed;

        $finals = [];

        if ($completed) {
            $finalIds = array_values(array_filter(array_map(
                static fn (array $row): ?int => $row['document']->final_version_id,
                $sent,
            )));

            $finals = DocumentVersion::withoutOrganizationScope()
                ->whereIn('id', $finalIds === [] ? [0] : $finalIds)
                ->where('kind', DocumentVersionKind::Final->value)
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();
        }

        $downloads = [];

        foreach ($sent as $row) {
            $available = in_array((int) $row['document']->final_version_id, $finals, true);

            $downloads[] = [
                'document_id' => $row['document']->ulid,
                'name' => $row['document']->name,
                'position' => (int) $row['document']->position,
                'available' => $available,
                'url' => $available
                    ? route('sign.download', ['token' => $context->token, 'type' => 'signed', 'document' => $row['document']->ulid])
                    : null,
            ];
        }

        return [
            'document' => $first === null ? null : [
                'pdf_url' => route('sign.document', ['token' => $context->token]),
                'page_thumb_url_template' => null,
                'page_sizes' => $this->pageSizes($first),
                'sha256' => $first->sha256,
            ],
            'documents' => $this->documentList($context, $session, $sent),
            'copy' => [
                'final_available' => $completed && $downloads !== [] && collect($downloads)->every(fn (array $item): bool => $item['available']),
                'completed_at' => $context->envelope->completed_at?->toIso8601String(),
                'can_download' => true,
                'downloads' => $downloads,
                'notice' => $completed
                    ? 'Você recebeu uma cópia deste documento para acompanhamento. Os participantes concluíram o processo.'
                    : 'Você recebeu este documento para acompanhamento. Não é necessário assinar nem aprovar; você receberá a cópia final quando os participantes concluírem.',
            ],
        ];
    }

    /**
     * Um item por documento apresentado, na ordem do envelope.
     *
     * @param  list<array{document: Document, version: DocumentVersion}>  $sent
     * @return list<array<string, mixed>>
     */
    private function documentList(SignerContext $context, SigningSession $session, array $sent): array
    {
        $presented = SigningSessionDocument::withoutOrganizationScope()
            ->where('signing_session_id', $session->getKey())
            ->pluck('document_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $items = [];

        foreach ($sent as $index => $row) {
            $items[] = [
                'id' => $row['document']->ulid,
                'position' => (int) $row['document']->position,
                'name' => $row['document']->name,
                'pages' => (int) ($row['version']->page_count ?? 0),
                // O primeiro arquivo usa a URL da Fase 1; os demais, `?document={ulid}`.
                'pdf_url' => route('sign.document', $index === 0
                    ? ['token' => $context->token]
                    : ['token' => $context->token, 'document' => $row['document']->ulid]),
                'page_sizes' => $this->pageSizes($row['version']),
                'sha256' => $row['version']->sha256,
                // Entregue a ESTA sessão? O aceite exige todos (RecordAcceptance).
                'presented' => in_array((int) $row['document']->getKey(), $presented, true),
            ];
        }

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    private function sender(SignerContext $context): array
    {
        /** @var User|null $creator */
        $creator = User::query()->whereKey($context->envelope->created_by_user_id)->first();

        // Fase 2 §2.8 (C-BRAND): flag `branding` + marca salva; senão null (Fase 1).
        $brand = $this->branding->forSigner($context->organization);

        return [
            'organization_name' => $context->organization->name,
            'organization_initials' => $context->organization->initials,
            'logo_url' => $brand['logo_url'] ?? null,
            'brand' => $brand,
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
            // Fase 2 §2.4: papel de domínio (o `role` acima é o rótulo livre).
            'participant_role' => $recipient->role->value,
            'participant_role_label' => $recipient->role->label(),
        ];
    }

    /**
     * Demais participantes: nome, papel, ordem, status e se assinam depois. **Sem e-mail.**
     * Visualizadores não aparecem: não participam da coleta e não há por que expor seus nomes.
     *
     * @param  Collection<int, Recipient>  $recipients
     * @return list<array<string, mixed>>
     */
    private function others(SignerContext $context, Collection $recipients): array
    {
        // O visualizador não entra na ordem (Fase 2 §2.4): ninguém "assina depois" dele.
        $sequential = $context->envelope->isSequential() && $context->recipient->participates();
        $myOrder = (int) $context->recipient->order_index;

        /** @var list<array<string, mixed>> */
        return $recipients
            ->reject(fn (Recipient $r): bool => $r->getKey() === $context->recipient->getKey())
            ->filter(fn (Recipient $r): bool => $r->participates())
            ->map(fn (Recipient $r): array => [
                'name' => $r->name,
                'role' => SignerPresentation::roleLabel($r),
                'order' => (int) $r->order_index,
                'status' => $r->status->value,
                'signs_after_me' => $sequential && (int) $r->order_index > $myOrder,
                'participant_role' => $r->role->value,
                'participant_role_label' => $r->role->label(),
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
                ->filter(fn (Recipient $r): bool => $r->isPendingParticipant())
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
                : ConsentText::completionNotice(null, $this->participantCertificateOffered($context)),
            // Fase 2 (aditivos): o que foi registrado e sobre quais documentos.
            'action' => $acceptance->action->value,
            'action_label' => $acceptance->action->label(),
            'documents' => $this->receiptDocuments($context, $acceptance),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function receiptDocuments(SignerContext $context, SignatureAcceptance $acceptance): array
    {
        $completed = $context->envelope->status === EnvelopeStatus::Completed;

        return array_values($acceptance->documents()
            ->with('document:id,ulid,name,position,final_version_id')
            ->get()
            ->map(fn ($row): array => [
                'id' => $row->document?->ulid,
                'position' => (int) $row->position,
                'name' => $row->document?->name,
                'sha256' => $row->document_sha256,
                'final_pdf_url' => $completed && $row->document?->final_version_id !== null
                    ? route('sign.download', ['token' => $context->token, 'type' => 'signed', 'document' => $row->document->ulid])
                    : null,
            ])
            ->all());
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
