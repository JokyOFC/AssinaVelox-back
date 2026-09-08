<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\Organization;
use App\Models\User;
use App\Rules\CpfOrCnpj;
use App\Services\Organizations\CreateOrganization;
use App\Services\Organizations\Invitations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\CreatesNewUsers;

/**
 * Cadastro (ROUTES §2.2, RECONCILIACAO Q4/Q8): cria User + Organization + Membership(owner, active)
 * + Subscription(free, active) em transação. Cadastro vindo de um convite válido
 * (`invitation` = token) NÃO cria organização — o convite é aceito após verificar o e-mail.
 */
class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    public function __construct(protected CreateOrganization $createOrganization) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(array $input): User
    {
        $invitation = Invitations::findByToken($input['invitation'] ?? null);

        if ($invitation !== null && ! $invitation->isPending()) {
            $invitation = null;
        }

        $viaInvitation = $invitation !== null;

        Validator::make($input, [
            'name' => ['required', 'string', 'min:3', 'max:120'],
            'email' => $this->emailRules(),
            'organization_name' => $viaInvitation ? ['nullable', 'string', 'max:120'] : ['required', 'string', 'min:2', 'max:120'],
            'organization_tax_id' => ['nullable', 'string', 'max:20', new CpfOrCnpj],
            'password' => $this->passwordRules(),
            'terms' => ['accepted'],
            'timezone' => ['nullable', 'string', 'timezone:all'],
        ], [], [
            'name' => 'nome completo',
            'email' => 'e-mail',
            'organization_name' => 'empresa',
            'organization_tax_id' => 'CPF/CNPJ',
            'password' => 'senha',
            'terms' => 'termos de uso',
        ])->validate();

        return DB::transaction(function () use ($input, $invitation): User {
            $user = User::create([
                'name' => trim((string) $input['name']),
                'email' => Str::lower(trim((string) $input['email'])),
                'password' => $input['password'],
                'timezone' => $input['timezone'] ?? null,
                'locale' => Organization::DEFAULT_LOCALE,
                'terms_accepted_at' => now(),
                'terms_version' => (string) config('assinavelox.terms_version'),
            ]);

            if ($invitation !== null) {
                // Aceite efetivo acontece em invitations.accept.store após verificar o e-mail;
                // aqui apenas apontamos a organização do convite como "corrente" preferida.
                $user->forceFill(['current_organization_id' => $invitation->organization_id])->save();

                if (app()->bound('session') && app('session')->isStarted()) {
                    session()->put(Invitations::PENDING_SESSION_KEY, (string) $input['invitation']);
                }

                return $user;
            }

            $this->createOrganization->handle($user, [
                'name' => (string) $input['organization_name'],
                'tax_id' => $input['organization_tax_id'] ?? null,
                'timezone' => $input['timezone'] ?? null,
            ]);

            return $user;
        });
    }
}
