<?php

namespace App\Services\Identity\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasPublicUlid;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Services\Identity\CaptureKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Imagem da captura simples (`identity_captures`, docs/fase-2/identidade.md §5).
 *
 * O arquivo em `storage_path` está CIFRADO (chave da aplicação) e contém só os pixels
 * reencodados; `sha256` é dos bytes normalizados em claro. Depois da retenção,
 * `storage_path` é nulo e `purged_at` diz quando a imagem saiu.
 *
 * Mora em `App\Services\Identity\Models` porque `app/Models` está fora da área C-ID; a
 * integração pode movê-lo sem mudar a tabela.
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property int $envelope_id
 * @property int $recipient_id
 * @property int|null $signing_session_id
 * @property int|null $signature_acceptance_id
 * @property CaptureKind $kind
 * @property string|null $storage_path
 * @property string $sha256
 * @property string $mime_type
 * @property int $width
 * @property int $height
 * @property int $size_bytes
 * @property string|null $source
 * @property Carbon $captured_at
 * @property Carbon|null $purged_at
 *
 * Fase 3 §3.3 (F-VIDEO) — só para `kind = video` (nulos nas fotos):
 * @property string|null $container `webm` | `matroska` | `mp4`, lido dos bytes
 * @property int|null $duration_ms duração lida do arquivo, quando legível
 * @property int|null $declared_duration_ms duração informada pelo navegador (não verificada)
 * @property Carbon|null $consented_at
 * @property string|null $consent_version SHA-256 do texto de consentimento exibido
 */
class IdentityCapture extends Model
{
    use BelongsToOrganization, HasPublicUlid;

    protected $table = 'identity_captures';

    protected $fillable = [
        'organization_id',
        'envelope_id',
        'recipient_id',
        'signing_session_id',
        'signature_acceptance_id',
        'kind',
        'storage_path',
        'sha256',
        'mime_type',
        'width',
        'height',
        'size_bytes',
        'source',
        'captured_at',
        'purged_at',
        'container',
        'duration_ms',
        'declared_duration_ms',
        'consented_at',
        'consent_version',
    ];

    /**
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'kind' => CaptureKind::class,
            'width' => 'integer',
            'height' => 'integer',
            'size_bytes' => 'integer',
            'captured_at' => 'datetime',
            'purged_at' => 'datetime',
            'duration_ms' => 'integer',
            'declared_duration_ms' => 'integer',
            'consented_at' => 'datetime',
        ];
    }

    public function isVideo(): bool
    {
        return $this->kind === CaptureKind::Video;
    }

    /**
     * @return BelongsTo<Envelope, $this>
     */
    public function envelope(): BelongsTo
    {
        return $this->belongsTo(Envelope::class);
    }

    /**
     * @return BelongsTo<Recipient, $this>
     */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(Recipient::class);
    }

    public function isAvailable(): bool
    {
        return $this->storage_path !== null && $this->purged_at === null;
    }
}
