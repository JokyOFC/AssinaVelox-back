<?php

namespace App\Services\InPerson;

use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\SigningSession;
use App\Models\SigningSessionDocument;
use App\Services\Signing\ConsentText;
use App\Services\Signing\RecordAcceptance;
use App\Services\Signing\RecordRefusal;
use App\Services\Signing\SignerContext;
use App\Services\Signing\SignerPageProps;
use App\Services\Signing\SignerPresentation;
use App\Services\Signing\SignerSessions;

/**
 * Props da etapa "revisar e registrar o aceite" para o presencial e o lote.
 *
 * Mesma montagem de `SignerPageProps::signingProps()` (a tela `sign` do fluxo individual) e,
 * sobretudo, o MESMO snapshot que {@see RecordAcceptance} recalcula sob lock:
 * `SignerPresentation::snapshotFor()` sobre os documentos congelados, os campos do
 * participante e a declaração de `ConsentText::statement()`. O token de autorização emitido
 * aqui fica preso a esse hash; se o documento, os campos ou o texto mudarem entre a tela e o
 * clique, o aceite é recusado (`stale_presentation`) — igual ao fluxo individual.
 *
 * A única diferença é a URL do PDF, que quem chama fornece: o presencial e o lote têm rotas
 * próprias, autorizadas pela sessão presencial / pelo lote E pela sessão de assinatura do
 * participante.
 */
final class ParticipantSigningProps
{
    public function __construct(
        private readonly SignerPresentation $presentation,
        private readonly SignerSessions $sessions,
    ) {}

    /**
     * @param  callable(Document): string  $pdfUrl
     * @return array<string, mixed>|null
     */
    public function build(SignerContext $context, SigningSession $session, callable $pdfUrl): ?array
    {
        $version = $context->sentVersion();
        $sent = $context->sentDocuments();

        if ($version === null || $sent === []) {
            return null;
        }

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

        $snapshot = $this->presentation->snapshotFor($context, $sent, $fields, $consentText);
        $authorization = $this->sessions->issueAuthorization($session, SignerPresentation::hash($snapshot));
        $count = count($sent);

        $presented = SigningSessionDocument::withoutOrganizationScope()
            ->where('signing_session_id', $session->getKey())
            ->pluck('document_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $documents = [];

        foreach ($sent as $row) {
            $documents[] = [
                'id' => $row['document']->ulid,
                'position' => (int) $row['document']->position,
                'name' => $row['document']->name,
                'pages' => (int) ($row['version']->page_count ?? 0),
                'pdf_url' => $pdfUrl($row['document']),
                'page_sizes' => self::pageSizes($row['version']),
                'sha256' => $row['version']->sha256,
                'presented' => in_array((int) $row['document']->getKey(), $presented, true)
                    || ($count === 1 && $session->document_presented_at !== null),
            ];
        }

        return [
            'envelope' => [
                'display_code' => $context->envelope->display_code,
                'title' => $context->envelope->title,
                'pages' => (int) ($version->page_count ?? 0),
                'expires_at' => $context->envelope->expires_at?->toIso8601String(),
                'message' => $context->envelope->message,
            ],
            'action' => SignerPageProps::action($context),
            'documents' => $documents,
            'my_fields' => $this->presentation->myFieldProps($fields, $context, SignerPresentation::documentUlidsByVersion($sent)),
            'other_fields' => $multi
                ? $this->presentation->otherFieldsForDocuments($context, $sent)
                : $this->presentation->otherFields($context, $version),
            'consent' => [
                'version' => ConsentText::versionFor($context->envelope, $context->recipient, $count),
                'checkbox_label' => ConsentText::checkboxLabel($context->envelope, $context->recipient, $count),
                'statement' => $consentText,
                'completion_notice' => ConsentText::completionNotice(),
            ],
            'authorization' => [
                'token' => $authorization,
                'expires_at' => $session->refresh()->authorization_expires_at?->toIso8601String(),
            ],
            'signature_options' => self::signatureOptions(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function signatureOptions(): array
    {
        return [
            'draw' => true,
            'type' => true,
            'upload' => true,
            'fonts' => RecordAcceptance::FONTS,
            'certificate' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function limits(): array
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

    /**
     * @return array<string, string>
     */
    public static function legal(): array
    {
        return [
            'terms_url' => route('legal.terms'),
            'privacy_url' => route('legal.privacy'),
        ];
    }

    /**
     * @return array{version: string, summary: string, notice: string}
     */
    public static function privacy(SignerContext $context): array
    {
        return [
            'version' => ConsentText::privacyNoticeVersion($context->recipient->role, $context->recipient),
            'summary' => ConsentText::privacySummary($context->organization, $context->recipient->role, $context->recipient),
            'notice' => ConsentText::privacyNotice($context->organization, $context->recipient->role, $context->recipient),
        ];
    }

    /**
     * @return list<array{page: int, width_pt: float, height_pt: float, rotation: int}>
     */
    public static function pageSizes(DocumentVersion $version): array
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

    public static function firstName(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];

        return $parts[0] ?? $name;
    }
}
