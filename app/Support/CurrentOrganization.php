<?php

namespace App\Support;

use App\Enums\MembershipRole;
use App\Models\Membership;
use App\Models\Organization;

/**
 * Organização corrente da requisição/job. Resolvida como singleton do container
 * (registrado em AppServiceProvider; instance() garante o singleton mesmo sem registro).
 *
 * O middleware EnsureCurrentOrganization define organização + membership a partir da
 * sessão; jobs recebem organization_id e chamam set() antes de tocar em models escopados.
 *
 * Ajuste B2 (documentado em docs/autorizacao-e-isolamento.md): além da organização,
 * guarda a membership do usuário autenticado para que policies e middleware de papel
 * não precisem consultar o banco novamente.
 */
class CurrentOrganization
{
    protected ?Organization $organization = null;

    protected ?Membership $membership = null;

    public static function instance(): self
    {
        app()->singletonIf(self::class);

        return app(self::class);
    }

    public function set(?Organization $organization, ?Membership $membership = null): void
    {
        $this->organization = $organization;
        $this->membership = $membership;
    }

    public function setMembership(?Membership $membership): void
    {
        $this->membership = $membership;
    }

    public function clear(): void
    {
        $this->organization = null;
        $this->membership = null;
    }

    public function get(): ?Organization
    {
        return $this->organization;
    }

    public function membership(): ?Membership
    {
        return $this->membership;
    }

    public function role(): ?MembershipRole
    {
        return $this->membership?->role;
    }

    public function id(): ?int
    {
        return $this->organization?->getKey();
    }

    public function has(): bool
    {
        return $this->organization !== null;
    }

    /**
     * Executa o callback com outra organização corrente (ou nenhuma) e restaura a anterior.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function runAs(?Organization $organization, callable $callback, ?Membership $membership = null): mixed
    {
        $previousOrganization = $this->organization;
        $previousMembership = $this->membership;
        $this->organization = $organization;
        $this->membership = $membership;

        try {
            return $callback();
        } finally {
            $this->organization = $previousOrganization;
            $this->membership = $previousMembership;
        }
    }
}
