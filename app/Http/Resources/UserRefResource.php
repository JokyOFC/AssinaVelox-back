<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * UserRef (ROUTES §2): { id, name, initials, email? }.
 *
 * @mixin User
 */
class UserRefResource extends JsonResource
{
    public static $wrap = null;

    public function __construct($resource, protected bool $withEmail = false)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->getKey(),
            'name' => $this->name,
            'initials' => $this->initials,
            'email' => $this->when($this->withEmail, fn () => $this->email),
        ];
    }

    /**
     * @return ($user is null ? null : array{id: string, name: string, initials: string, email?: string})
     */
    public static function ref(?User $user, bool $withEmail = false): ?array
    {
        if ($user === null) {
            return null;
        }

        $ref = ['id' => (string) $user->getKey(), 'name' => $user->name, 'initials' => $user->initials];

        if ($withEmail) {
            $ref['email'] = $user->email;
        }

        return $ref;
    }
}
