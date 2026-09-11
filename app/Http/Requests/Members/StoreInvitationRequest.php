<?php

namespace App\Http\Requests\Members;

use App\Enums\FolderAccessLevel;
use App\Enums\Permission;
use App\Http\Requests\Members\Concerns\ResolvesTargetRole;
use App\Models\MembershipInvitation;
use App\Support\CurrentOrganization;
use App\Support\Permissions;
use App\Support\PermissionsFolderAccess;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * POST /usuarios/convites (ROUTES §2.10): `emails` (string separada por vírgula OU array,
 * 1–20, cada um válido, não membro e sem convite pendente) + `role ∈ admin | member`.
 *
 * Fase 2 (flag `custom_roles`): `role_id` (função personalizada) e `folders[]`
 * ({folder, level}; "Pastas com acesso", exige `manage_folders`). Anti-escalada: a função
 * oferecida não pode ter permissões que o ator não tem.
 */
class StoreInvitationRequest extends FormRequest
{
    use ResolvesTargetRole;

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
            'role' => ['required_without:role_id', 'nullable', 'string', Rule::in(self::ASSIGNABLE_SYSTEM_ROLES)],
            'role_id' => ['nullable', 'string', 'size:26'],
            'folders' => ['sometimes', 'array', 'max:500'],
            'folders.*.folder' => ['required', 'string', 'size:26'],
            'folders.*.level' => ['required', 'string', Rule::enum(FolderAccessLevel::class)],
        ];
    }

    /**
     * @return array<int, Closure>
     */
    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->resolveTargetRole($validator),
            fn (Validator $validator) => $this->checkFolders($validator),
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
            'role_id' => 'função',
            'folders' => 'pastas com acesso',
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
            'role.required_without' => 'Escolha uma função.',
        ];
    }

    /**
     * @return list<string>
     */
    public function emails(): array
    {
        return array_values($this->validated('emails'));
    }

    /**
     * @return array<int, FolderAccessLevel>
     */
    public function folderGrants(): array
    {
        $organization = CurrentOrganization::instance()->get();

        if ($organization === null || ! array_key_exists('folders', $this->validated())) {
            return [];
        }

        return PermissionsFolderAccess::parse((int) $organization->getKey(), (array) $this->validated('folders', []));
    }

    protected function checkFolders(Validator $validator): void
    {
        if ($validator->errors()->isNotEmpty() || ! is_array($this->input('folders')) || $this->input('folders') === []) {
            return;
        }

        $current = CurrentOrganization::instance();

        if (! Permissions::customRolesEnabled($current->get())) {
            $validator->errors()->add('folders', 'Acesso por pasta não está disponível no plano desta conta.');

            return;
        }

        if (! ($current->membership()?->hasPermission(Permission::ManageFolders) ?? false)) {
            $validator->errors()->add('folders', 'Você não pode definir pastas com acesso.');
        }
    }
}
