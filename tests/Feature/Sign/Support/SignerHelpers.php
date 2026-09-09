<?php

/*
|--------------------------------------------------------------------------
| Helpers dos testes do fluxo público do signatário (B-SIGN)
|--------------------------------------------------------------------------
| Incluído com require_once pelos arquivos de teste. Não contém testes.
|
| Monta um envelope JÁ ENVIADO (in_progress, com sent_document_version_id congelado, bytes
| reais no disco falso, destinatários, campos e links de convite com token bruto conhecido).
| Nada aqui usa o caminho de envio real: os testes deste módulo verificam a página pública,
| não o envio.
*/

use App\Enums\AccessLinkPurpose;
use App\Enums\DocumentProcessingStatus;
use App\Enums\DocumentVersionKind;
use App\Enums\EnvelopeStatus;
use App\Enums\FieldType;
use App\Enums\RecipientStatus;
use App\Enums\SigningOrder;
use App\Integrations\Contracts\PdfSigner;
use App\Integrations\Dto\SignRequest;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Envelope;
use App\Models\Organization;
use App\Models\Recipient;
use App\Models\RecipientAccessLink;
use App\Models\SigningField;
use App\Models\User;
use App\Notifications\Signing\SignerOtpNotification;
use App\Services\Pdf\Dto\SignResult;
use App\Services\Pdf\Dto\ValidationResult;
use App\Services\Signing\SignerTokens;
use Database\Factories\DocumentVersionFactory;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

if (! function_exists('signerDisk')) {
    /**
     * Disco `documents` com raiz exclusiva do teste (a mesma tática de B-DOC: uma raiz
     * compartilhada com Storage::fake falha de forma intermitente no Windows).
     */
    function signerDisk(string $root): void
    {
        config()->set('filesystems.disks.documents', [
            'driver' => 'local',
            'root' => $root,
            'visibility' => 'private',
            'serve' => false,
            'throw' => true,
            'report' => false,
        ]);

        Storage::forgetDisk('documents');
    }
}

if (! function_exists('signerPdfBytes')) {
    /**
     * Bytes de um PDF mínimo. O fluxo público não interpreta o arquivo — apenas o transmite —
     * então não vale gastar o `pdftool` aqui.
     */
    function signerPdfBytes(string $marker = 'contrato'): string
    {
        return "%PDF-1.7\n% ".$marker."\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
    }
}

if (! function_exists('signerEnvelope')) {
    /**
     * Envelope enviado, pronto para a página pública.
     *
     * @param  list<array{name: string, email: string, order?: int, status?: RecipientStatus, fields?: list<FieldType>}>  $recipients
     * @return array{
     *     organization: Organization,
     *     owner: User,
     *     envelope: Envelope,
     *     version: DocumentVersion,
     *     recipients: array<string, Recipient>,
     *     tokens: array<string, string>,
     *     links: array<string, RecipientAccessLink>,
     * }
     */
    function signerEnvelope(
        array $recipients = [],
        SigningOrder $order = SigningOrder::Sequential,
        array $envelopeAttributes = [],
        ?Organization $organization = null,
        ?User $owner = null,
    ): array {
        if ($organization === null || $owner === null) {
            ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
        }

        $recipients = $recipients === []
            ? [['name' => 'Maria Alves Souza', 'email' => 'maria@exemplo.test', 'fields' => [FieldType::Signature]]]
            : $recipients;

        $envelope = Envelope::factory()->forOrganization($organization, $owner)->draft()->create(array_merge([
            'title' => 'Contrato de locação',
            'signing_order' => $order,
            'terms_version' => 'v1-2026-09-08',
        ], $envelopeAttributes));

        $document = Document::factory()->forEnvelope($envelope)->create([
            'processing_status' => DocumentProcessingStatus::Ready,
            'page_count' => 2,
        ]);

        $bytes = signerPdfBytes($envelope->ulid);
        $versionUlid = (string) Str::ulid();
        $path = sprintf('orgs/%s/envelopes/%s/%s.pdf', $organization->ulid, $envelope->ulid, $versionUlid);

        Storage::disk('documents')->put($path, $bytes);

        $version = DocumentVersion::factory()->forDocument($document)->create([
            'ulid' => $versionUlid,
            'kind' => DocumentVersionKind::Original,
            'storage_path' => $path,
            'mime_type' => 'application/pdf',
            'size_bytes' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'page_count' => 2,
            'pages_meta' => DocumentVersionFactory::pagesMeta(2),
        ]);

        $document->forceFill(['current_version_id' => $version->id])->save();

        $envelope->forceFill([
            'status' => EnvelopeStatus::InProgress,
            'sent_at' => now()->subDay(),
            'expires_at' => now()->addDays(10),
            'sent_document_version_id' => $version->id,
            'verification_code' => Envelope::generateVerificationCode(),
            'current_order' => 1,
        ])->save();

        $models = [];
        $tokens = [];
        $links = [];

        foreach ($recipients as $index => $spec) {
            $recipientOrder = $spec['order'] ?? ($order === SigningOrder::Sequential ? $index + 1 : 1);
            $status = $spec['status'] ?? ($recipientOrder === 1 ? RecipientStatus::Notified : RecipientStatus::Pending);

            $recipient = Recipient::factory()->forEnvelope($envelope, $recipientOrder)->create([
                'name' => $spec['name'],
                'email' => $spec['email'],
                'status' => $status,
                'notification_count' => $status === RecipientStatus::Pending ? 0 : 1,
                'last_notified_at' => $status === RecipientStatus::Pending ? null : now()->subDay(),
            ]);

            foreach ($spec['fields'] ?? [FieldType::Signature] as $position => $type) {
                SigningField::factory()->create([
                    'envelope_id' => $envelope->id,
                    'document_version_id' => $version->id,
                    'recipient_id' => $recipient->id,
                    'organization_id' => $organization->id,
                    'type' => $type,
                    'page' => 1,
                    'x' => 0.1,
                    'y' => 0.1 + ($position * 0.1),
                    'width' => 0.3,
                    'height' => 0.06,
                    'required' => true,
                    'sort_order' => $position,
                ]);
            }

            $raw = SignerTokens::generate();

            $link = RecipientAccessLink::query()->create([
                'recipient_id' => $recipient->id,
                'envelope_id' => $envelope->id,
                'document_version_id' => $version->id,
                'organization_id' => $organization->id,
                'token_digest' => SignerTokens::digest($raw),
                'purpose' => AccessLinkPurpose::Signing,
                'expires_at' => $envelope->expires_at,
            ]);

            $models[$spec['email']] = $recipient;
            $tokens[$spec['email']] = $raw;
            $links[$spec['email']] = $link;
        }

        return [
            'organization' => $organization,
            'owner' => $owner,
            'envelope' => $envelope->fresh(),
            'version' => $version,
            'recipients' => $models,
            'tokens' => $tokens,
            'links' => $links,
        ];
    }
}

if (! function_exists('signerCaptureCodes')) {
    /**
     * Escuta o evento de envio de notificação e guarda o código de cada
     * SignerOtpNotification — sem `Notification::fake()`, para que o canal rastreado rode de
     * verdade e a linha de `delivery_attempts` continue sendo criada.
     *
     * É a única forma honesta de um teste conhecer o código: ele não existe em lugar nenhum
     * do banco (só o HMAC) e não aparece em log nem na trilha. Quebrar o HMAC por força bruta
     * (10^6 tentativas) funcionaria, mas custa segundos por teste.
     *
     * @return ArrayObject<int, string> preenchido na ordem dos envios
     */
    function signerCaptureCodes(): ArrayObject
    {
        /** @var ArrayObject<int, string> $codes */
        $codes = new ArrayObject;

        Event::listen(NotificationSending::class, function (NotificationSending $event) use ($codes): void {
            if ($event->notification instanceof SignerOtpNotification) {
                $codes[] = $event->notification->code;
            }
        });

        return $codes;
    }
}

if (! function_exists('pngDataUri')) {
    /**
     * PNG real (gerado com GD) em data URI, para os testes de captura de assinatura.
     */
    function pngDataUri(int $width = 300, int $height = 100): string
    {
        return 'data:image/png;base64,'.base64_encode(pngBytes($width, $height));
    }
}

if (! function_exists('pngBytes')) {
    function pngBytes(int $width = 300, int $height = 100): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagesavealpha($image, true);
        $ink = imagecolorallocate($image, 10, 20, 40);
        imageline($image, 5, (int) ($height / 2), $width - 5, (int) ($height / 3), (int) $ink);

        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }
}

if (! function_exists('pngWithTextChunk')) {
    /**
     * Insere um chunk `tEXt` válido (metadado) logo depois do IHDR de um PNG.
     * Serve para provar que a normalização reencoda a imagem do zero e descarta metadados.
     */
    function pngWithTextChunk(string $png, string $keyword, string $text): string
    {
        $data = $keyword.chr(0).$text;
        $chunk = pack('N', strlen($data)).'tEXt'.$data.pack('N', crc32('tEXt'.$data));

        // 8 bytes de assinatura + IHDR completo (4 tamanho + 4 tipo + 13 dados + 4 CRC).
        $offset = 8 + 25;

        return substr($png, 0, $offset).$chunk.substr($png, $offset);
    }
}

if (! function_exists('authenticateSigner')) {
    /**
     * Percorre a etapa "Confirmar identidade" pelo caminho real (pede o código, lê o código
     * capturado do e-mail, verifica) e devolve as props da tela `sign` — que já trazem o
     * token de autorização emitido na renderização.
     *
     * O teste que chama precisa ter `$this->codes = signerCaptureCodes()` no beforeEach.
     *
     * @return array<string, mixed>
     */
    function authenticateSigner(object $test, string $token): array
    {
        $test->post(route('sign.otp.send', ['token' => $token]))->assertRedirect();

        $codes = $test->codes;

        $test->post(route('sign.otp.verify', ['token' => $token]), [
            'code' => $codes[count($codes) - 1],
        ])->assertRedirect();

        $props = $test->get(route('sign.show', ['token' => $token]))->viewData('page')['props'];

        // O navegador do signatário busca o PDF assim que a tela carrega — é isso que marca
        // `signing_sessions.document_presented_at` e grava `document.presented` na trilha,
        // exigido por `RecordAcceptance` antes de gravar o aceite. Quem precisa do caminho
        // SEM apresentação (para provar que ele é recusado) usa `authenticateSignerOnly()`.
        $test->get(route('sign.document', ['token' => $token]));

        return $props;
    }
}

if (! function_exists('authenticateSignerOnly')) {
    /**
     * Confirma o código por e-mail SEM buscar o documento: a sessão fica sem
     * `document_presented_at`.
     */
    function authenticateSignerOnly(object $test, string $token): array
    {
        $test->post(route('sign.otp.send', ['token' => $token]))->assertRedirect();

        $codes = $test->codes;

        $test->post(route('sign.otp.verify', ['token' => $token]), [
            'code' => $codes[count($codes) - 1],
        ])->assertRedirect();

        return $test->get(route('sign.show', ['token' => $token]))->viewData('page')['props'];
    }
}

if (! function_exists('bindConfiguredSigner')) {
    /**
     * Substitui o `PdfSigner` do contêiner por um duplo que apenas informa se o adaptador
     * está configurado. `ConsentText::operatorCertificateActive()` consulta esse contrato
     * para não prometer ao signatário uma assinatura que nunca será aplicada.
     */
    function bindConfiguredSigner(bool $configured): void
    {
        app()->instance(PdfSigner::class, new class($configured) implements PdfSigner
        {
            public function __construct(private readonly bool $configured) {}

            public function isConfigured(): bool
            {
                return $this->configured;
            }

            public function sign(SignRequest $request): SignResult
            {
                throw new RuntimeException('O duplo de teste não assina nada.');
            }

            public function validate(string $pdfPath, array $trustRoots = []): ValidationResult
            {
                throw new RuntimeException('O duplo de teste não valida nada.');
            }
        });
    }
}
