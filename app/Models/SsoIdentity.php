<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Vínculo sujeito do IdP ↔ usuário do painel (docs/fase-3/sso.md §6). O sujeito só existe
 * como SHA-256 ligado à conexão (`subject_hash`).
 *
 * @property int $id
 * @property int $sso_connection_id
 * @property int $user_id
 * @property string $subject_hash
 * @property Carbon|null $last_login_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read SsoConnection $connection
 * @property-read User $user
 */
class SsoIdentity extends Model
{
    /** @var list<string> */
    protected $fillable = ['sso_connection_id', 'user_id', 'subject_hash', 'last_login_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['last_login_at' => 'datetime'];
    }

    public static function hashSubject(int $connectionId, string $subject): string
    {
        return hash('sha256', $connectionId.'|'.$subject);
    }

    /**
     * Vínculo de um IdP que a conexão não usa mais (o emissor OIDC ou o entityID SAML mudou):
     * o hash é trocado por um marcador que nunca casa com um sujeito — SHA-256 é hexadecimal,
     * e o marcador começa com "x". A linha continua (histórico, `last_login_at`), mas não
     * autentica ninguém; o próximo login da pessoa pelo IdP novo refaz o vínculo pelo e-mail.
     */
    public const INVALIDATED_PREFIX = 'x';

    public static function invalidatedHash(int $identityId): string
    {
        return self::INVALIDATED_PREFIX.substr(hash('sha256', 'invalidated|'.$identityId.'|'.bin2hex(random_bytes(16))), 0, 63);
    }

    public function isInvalidated(): bool
    {
        return str_starts_with($this->subject_hash, self::INVALIDATED_PREFIX);
    }

    /** @return BelongsTo<SsoConnection, $this> */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(SsoConnection::class, 'sso_connection_id')->withoutGlobalScopes();
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
