<?php

namespace App\Services\Organizations;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Membership;
use App\Models\MembershipInvitation;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\MembershipInvitationNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Emissão, reenvio e aceite de convites de membros. O token bruto (32 bytes → base64url)
 * só existe na URL enviada por e-mail; o banco guarda apenas o digest SHA-256.
 */
class Invitations
{
    /** Chave de sessão com o token bruto de um convite aguardando cadastro/verificação de e-mail. */
    public const PENDING_SESSION_KEY = 'invitation.pending_token';

    public static function digest(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function generateToken(): string
    {
        $bytes = max(16, (int) config('assinavelox.invitations.token_bytes', 32));

        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    public static function findByToken(?string $token): ?MembershipInvitation
    {
        if ($token === null || $token === '' || Str::length($token) > 128) {
            return null;
        }

        return MembershipInvitation::query()
            ->with(['organization', 'inviter'])
            ->where('token_digest', self::digest($token))
            ->first();
    }

    /**
     * Cria o convite e envia o e-mail. Retorna a invitation (o token bruto não é retido).
     */
    public function invite(Organization $organization, User $inviter, string $email, MembershipRole $role): MembershipInvitation
    {
        $token = self::generateToken();

        $invitation = MembershipInvitation::query()->create([
            'organization_id' => $organization->getKey(),
            'email' => Str::lower(trim($email)),
            'role' => $role,
            'token_digest' => self::digest($token),
            'invited_by_user_id' => $inviter->getKey(),
            'expires_at' => now()->addDays((int) config('assinavelox.invitations.expires_in_days', 7)),
        ]);

        $this->send($invitation, $token);

        return $invitation;
    }

    /**
     * Reenvia gerando um NOVO token (o anterior deixa de valer) e renovando a expiração.
     */
    public function resend(MembershipInvitation $invitation): MembershipInvitation
    {
        $token = self::generateToken();

        $invitation->forceFill([
            'token_digest' => self::digest($token),
            'expires_at' => now()->addDays((int) config('assinavelox.invitations.expires_in_days', 7)),
            'revoked_at' => null,
        ])->save();

        $this->send($invitation, $token);

        return $invitation;
    }

    public function revoke(MembershipInvitation $invitation): void
    {
        $invitation->forceFill(['revoked_at' => now()])->save();
    }

    /**
     * Aceite: cria (ou reativa) a membership com o papel do convite e marca accepted_at.
     * Idempotente: convite já aceito ou usuário já membro não gera erro.
     */
    public function accept(MembershipInvitation $invitation, User $user): Membership
    {
        return DB::transaction(function () use ($invitation, $user): Membership {
            $invitation = MembershipInvitation::query()->lockForUpdate()->whereKey($invitation->getKey())->firstOrFail();

            $membership = Membership::query()
                ->where('organization_id', $invitation->organization_id)
                ->where('user_id', $user->getKey())
                ->first();

            if ($membership === null) {
                $membership = Membership::query()->create([
                    'organization_id' => $invitation->organization_id,
                    'user_id' => $user->getKey(),
                    'role' => $invitation->role,
                    'status' => MembershipStatus::Active,
                ]);
            } elseif ($membership->status !== MembershipStatus::Active) {
                $membership->forceFill(['status' => MembershipStatus::Active])->save();
            }

            if ($invitation->accepted_at === null) {
                $invitation->forceFill(['accepted_at' => now()])->save();
            }

            $user->forceFill(['current_organization_id' => $invitation->organization_id])->save();

            return $membership;
        });
    }

    protected function send(MembershipInvitation $invitation, string $token): void
    {
        Notification::route('mail', $invitation->email)
            ->notify(new MembershipInvitationNotification($invitation, $token));
    }
}
