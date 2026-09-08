<?php

namespace App\Http\Requests\Members;

use App\Enums\MembershipRole;
use App\Models\MembershipInvitation;
use App\Support\CurrentOrganization;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * POST /usuarios/convites (ROUTES §2.10): `emails` (string separada por vírgula OU array,
 * 1–20, cada um válido, não membro e sem convite pendente) + `role ∈ admin | member`.
 */
class StoreInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', MembershipInvitation::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $raw = $this->input('emails');

        if (is_string($raw)) {
            $raw = preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }

        $emails = collect(is_array($raw) ? $raw : [])
            ->map(fn ($email): string => Str::lower(trim((string) $email)))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $this->merge(['emails' => $emails]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $organization = CurrentOrganization::instance()->get();

        return [
            'emails' => ['required', 'array', 'min:1', 'max:20'],
            'emails.*' => [
                'required',
                'string',
                'email:rfc',
                'max:255',
                function (string $attribute, mixed $value, Closure $fail) use ($organization): void {
                    if ($organization === null) {
                        return;
                    }

                    $isMember = $organization->memberships()
                        ->whereHas('user', fn ($query) => $query->where('email', $value))
                        ->exists();

                    if ($isMember) {
                        $fail("{$value} já é membro desta organização.");

                        return;
                    }

                    $pending = $organization->invitations()
                        ->where('email', $value)
                        ->whereNull('accepted_at')
                        ->whereNull('revoked_at')
                        ->where('expires_at', '>', now())
                        ->exists();

                    if ($pending) {
                        $fail("{$value} já tem um convite pendente.");
                    }
                },
            ],
            'role' => ['required', 'string', Rule::in([MembershipRole::Admin->value, MembershipRole::Member->value])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'emails' => 'e-mails',
            'emails.*' => 'e-mail',
            'role' => 'função',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'emails.required' => 'Informe pelo menos um e-mail.',
            'emails.max' => 'Convide no máximo 20 e-mails por vez.',
            'emails.*.email' => 'O e-mail :input não é válido.',
        ];
    }

    /**
     * @return list<string>
     */
    public function emails(): array
    {
        return array_values($this->validated('emails'));
    }

    public function role(): MembershipRole
    {
        return MembershipRole::from($this->validated('role'));
    }
}
