<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasPublicUlid;
use App\Services\Webhooks\WebhookEventType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Endpoint de webhook de saída de uma organização (roadmap §2.16; docs/fase-2/webhooks.md).
 *
 * O segredo é gerado pela plataforma, mostrado UMA vez e guardado cifrado (cast `encrypted`).
 * Nunca entra em `toArray()`/JSON (`$hidden`), log, fila ou payload.
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property int|null $created_by_user_id
 * @property string $url
 * @property string|null $description
 * @property list<string>|null $events
 * @property string $secret
 * @property string $secret_hint
 * @property string|null $previous_secret
 * @property Carbon|null $previous_secret_expires_at
 * @property Carbon|null $secret_rotated_at
 * @property bool $is_active
 * @property Carbon|null $paused_at
 * @property string|null $paused_reason
 * @property int $consecutive_failures
 * @property Carbon|null $last_success_at
 * @property Carbon|null $last_failure_at
 * @property string $source
 * @property int|null $api_token_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class WebhookEndpoint extends Model
{
    use BelongsToOrganization, HasPublicUlid, SoftDeletes;

    public const SOURCE_WEB = 'web';

    public const SOURCE_API = 'api';

    public const SOURCE_REST_HOOK = 'rest_hook';

    public const PAUSED_MANUAL = 'manual';

    public const PAUSED_FAILURES = 'consecutive_failures';

    public const PAUSED_CREATOR_WITHOUT_ACCESS = 'creator_without_access';

    /** @var list<string> */
    protected $fillable = [
        'organization_id',
        'created_by_user_id',
        'url',
        'description',
        'events',
        'secret',
        'secret_hint',
        'previous_secret',
        'previous_secret_expires_at',
        'secret_rotated_at',
        'is_active',
        'paused_at',
        'paused_reason',
        'consecutive_failures',
        'last_success_at',
        'last_failure_at',
        'source',
        'api_token_id',
    ];

    /** @var list<string> */
    protected $hidden = ['secret', 'previous_secret'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'events' => 'array',
            'secret' => 'encrypted',
            'previous_secret' => 'encrypted',
            'previous_secret_expires_at' => 'datetime',
            'secret_rotated_at' => 'datetime',
            'is_active' => 'boolean',
            'paused_at' => 'datetime',
            'consecutive_failures' => 'integer',
            'last_success_at' => 'datetime',
            'last_failure_at' => 'datetime',
            'api_token_id' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return HasMany<WebhookDelivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    /**
     * Segredos que assinam uma entrega AGORA: o vigente e, só dentro da janela de rotação, o
     * anterior. O receptor aceita se qualquer assinatura conferir.
     *
     * @return list<string>
     */
    public function signingSecrets(?Carbon $now = null): array
    {
        $secrets = [(string) $this->secret];

        if ($this->previousSecretActive($now)) {
            $secrets[] = (string) $this->previous_secret;
        }

        return $secrets;
    }

    public function previousSecretActive(?Carbon $now = null): bool
    {
        $now ??= Carbon::now();

        return $this->previous_secret !== null
            && $this->previous_secret !== ''
            && $this->previous_secret_expires_at !== null
            && $this->previous_secret_expires_at->isAfter($now);
    }

    public function subscribesTo(WebhookEventType $type): bool
    {
        if ($type === WebhookEventType::Ping) {
            return true;
        }

        $events = (array) ($this->events ?? []);

        return in_array(WebhookEventType::ALL, $events, true) || in_array($type->value, $events, true);
    }

    public function host(): string
    {
        return (string) parse_url($this->url, PHP_URL_HOST);
    }

    public function pausedReasonLabel(): ?string
    {
        if ($this->is_active) {
            return null;
        }

        return match ($this->paused_reason) {
            self::PAUSED_FAILURES => 'Pausado automaticamente depois de '.$this->consecutive_failures.' falhas seguidas',
            self::PAUSED_CREATOR_WITHOUT_ACCESS => 'Pausado: quem responde pelo endpoint não tem mais acesso a API e webhooks',
            default => 'Pausado manualmente',
        };
    }
}
