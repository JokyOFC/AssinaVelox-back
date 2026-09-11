<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasPublicUlid;
use App\Services\PublicForms\PublicFormDestination;
use App\Services\PublicForms\PublicFormStatus;
use App\Services\PublicForms\SubmissionPeriod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Formulário público que gera envelope a partir de um modelo (Fase 2 §2.2 —
 * docs/fase-2/formulario-publico.md).
 *
 * `ulid` identifica o formulário nas rotas INTERNAS; `public_token` (40 caracteres
 * aleatórios) é o único identificador da URL pública e nunca é serializado por padrão.
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property int $template_id
 * @property int $template_version_id
 * @property int|null $responsible_user_id
 * @property int|null $created_by_user_id
 * @property string $public_token
 * @property string $title
 * @property string|null $instructions
 * @property PublicFormStatus $status
 * @property PublicFormDestination $destination
 * @property array<string, mixed>|null $schema
 * @property array<string, mixed>|null $settings
 * @property Carbon|null $expires_at
 * @property Carbon|null $published_at
 * @property Carbon|null $paused_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Template $template
 * @property-read TemplateVersion $templateVersion
 * @property-read User|null $responsibleUser
 */
class PublicForm extends Model
{
    use BelongsToOrganization, HasPublicUlid;

    public const TOKEN_LENGTH = 40;

    public const DEFAULT_SUBMISSIONS_LIMIT = 50;

    /** @var list<string> */
    protected $fillable = [
        'organization_id',
        'template_id',
        'template_version_id',
        'responsible_user_id',
        'created_by_user_id',
        'title',
        'instructions',
        'status',
        'destination',
        'schema',
        'settings',
        'expires_at',
        'published_at',
        'paused_at',
        'revoked_at',
    ];

    /** @var list<string> */
    protected $hidden = ['public_token'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'draft',
        'destination' => 'review',
    ];

    public static function booted(): void
    {
        static::creating(function (PublicForm $form): void {
            if (empty($form->public_token)) {
                $form->public_token = Str::random(self::TOKEN_LENGTH);
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PublicFormStatus::class,
            'destination' => PublicFormDestination::class,
            'schema' => 'array',
            'settings' => 'array',
            'expires_at' => 'datetime',
            'published_at' => 'datetime',
            'paused_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Template, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }

    /** @return BelongsTo<TemplateVersion, $this> */
    public function templateVersion(): BelongsTo
    {
        return $this->belongsTo(TemplateVersion::class);
    }

    /** @return BelongsTo<User, $this> */
    public function responsibleUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    /** @return HasMany<PublicFormSubmission, $this> */
    public function submissions(): HasMany
    {
        return $this->hasMany(PublicFormSubmission::class);
    }

    // -- Configuração -------------------------------------------------------------------

    /**
     * Chaves das variáveis que o público preenche.
     *
     * @return list<string>
     */
    public function publicVariables(): array
    {
        $keys = $this->schema['public_variables'] ?? [];

        return is_array($keys) ? array_values(array_filter($keys, 'is_string')) : [];
    }

    /**
     * Valores fixos (definidos por quem publica) das variáveis que o público NÃO preenche.
     *
     * @return array<string, string>
     */
    public function fixedValues(): array
    {
        $values = $this->schema['fixed_values'] ?? [];

        return is_array($values) ? array_map('strval', array_filter($values, 'is_scalar')) : [];
    }

    /** ULID do papel do modelo ocupado por quem preenche. */
    public function fillerRole(): ?string
    {
        $role = $this->schema['filler_role'] ?? null;

        return is_string($role) && $role !== '' ? $role : null;
    }

    /**
     * Participantes fixos dos demais papéis, por ULID do papel.
     *
     * @return array<string, array{name: string, email: string}>
     */
    public function fixedParticipants(): array
    {
        $rows = $this->schema['fixed_participants'] ?? [];
        $participants = [];

        if (! is_array($rows)) {
            return [];
        }

        foreach ($rows as $ulid => $row) {
            if (is_string($ulid) && is_array($row)) {
                $participants[$ulid] = [
                    'name' => is_scalar($row['name'] ?? null) ? (string) $row['name'] : '',
                    'email' => is_scalar($row['email'] ?? null) ? (string) $row['email'] : '',
                ];
            }
        }

        return $participants;
    }

    /** Início do título do documento gerado (o nome de quem preencheu é acrescentado). */
    public function envelopeTitle(): string
    {
        $title = trim((string) ($this->settings['envelope_title'] ?? ''));

        return $title !== '' ? $title : $this->title;
    }

    public function submissionsLimit(): int
    {
        $limit = (int) ($this->settings['submissions_limit'] ?? self::DEFAULT_SUBMISSIONS_LIMIT);

        return max(1, $limit);
    }

    public function submissionsPeriod(): SubmissionPeriod
    {
        return SubmissionPeriod::tryFrom((string) ($this->settings['submissions_period'] ?? '')) ?? SubmissionPeriod::Day;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isRevoked(): bool
    {
        return $this->status === PublicFormStatus::Revoked;
    }

    public function publicUrl(): string
    {
        return route('form_fill.show', ['token' => $this->public_token]);
    }
}
