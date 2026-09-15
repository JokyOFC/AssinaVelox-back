<?php

namespace App\Services\Sso\Domains;

use App\Enums\AuditEventType;
use App\Models\Organization;
use App\Models\SsoDomain;
use App\Models\User;
use App\Services\Sso\SsoFailure;
use App\Services\Sso\SsoTrail;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Domínios de e-mail do login corporativo (docs/fase-3/sso.md §4).
 *
 *  - o domínio só vale depois de VERIFICADO: registro TXT `_assinavelox-sso.{domínio}` com o
 *    valor `assinavelox-sso={token}` — a consulta é real (TxtRecordResolver), com fake nos testes;
 *  - verificado, pertence a UMA organização (UNIQUE em `verified_domain`): outra organização
 *    pode ter a reivindicação pendente, mas nunca verificá-la;
 *  - remover o domínio desliga a entrada por ele na hora (a conexão continua).
 */
final class SsoDomainManager
{
    /**
     * @throws SsoFailure
     */
    public function add(Organization $organization, string $input, User $actor): SsoDomain
    {
        $domain = self::normalize($input);

        $existing = SsoDomain::withoutOrganizationScope()
            ->where('organization_id', $organization->getKey())
            ->where('domain', $domain)
            ->exists();

        if ($existing) {
            throw new SsoFailure('domain_duplicate', 'Este domínio já está na lista.');
        }

        if (SsoDomain::withoutOrganizationScope()->where('verified_domain', $domain)->exists()) {
            throw new SsoFailure('domain_taken', 'Este domínio já foi verificado por outra organização.');
        }

        $count = SsoDomain::withoutOrganizationScope()->where('organization_id', $organization->getKey())->count();

        if ($count >= max(1, (int) config('assinavelox.sso.domains.max_per_organization', 10))) {
            throw new SsoFailure('domain_limit', 'Limite de domínios atingido para esta organização.');
        }

        $record = SsoDomain::withoutOrganizationScope()->create([
            'organization_id' => $organization->getKey(),
            'created_by_user_id' => $actor->getKey(),
            'domain' => $domain,
            'verification_token' => Str::lower(Str::random(40)),
        ]);

        SsoTrail::record((int) $organization->getKey(), AuditEventType::SsoDomainAdded, ['domain' => $domain, 'sso_domain' => $record->ulid], $actor);

        return $record;
    }

    /**
     * @throws SsoFailure
     */
    public function verify(SsoDomain $domain, User $actor): SsoDomain
    {
        if ($domain->isVerified()) {
            return $domain;
        }

        $expected = self::recordValue($domain);
        // Resolvido a cada verificação (o controller pode ficar em cache na rota).
        $found = in_array($expected, array_map('trim', app(TxtRecordResolver::class)->txt(self::recordName($domain))), true);

        $domain->forceFill(['last_checked_at' => Carbon::now(), 'last_check_status' => $found ? 'found' : 'not_found'])->save();

        if (! $found) {
            throw new SsoFailure('domain_txt_not_found', 'Não encontramos o registro TXT. Confira o nome e o valor; a propagação do DNS pode levar alguns minutos.');
        }

        try {
            $domain->forceFill(['verified_domain' => $domain->domain, 'verified_at' => Carbon::now()])->save();
        } catch (UniqueConstraintViolationException) {
            $domain->forceFill(['verified_domain' => null, 'verified_at' => null, 'last_check_status' => 'taken'])->save();

            throw new SsoFailure('domain_taken', 'Este domínio já foi verificado por outra organização.');
        }

        SsoTrail::record((int) $domain->organization_id, AuditEventType::SsoDomainVerified, ['domain' => $domain->domain, 'sso_domain' => $domain->ulid], $actor);

        return $domain;
    }

    public function remove(SsoDomain $domain, User $actor): void
    {
        $organizationId = (int) $domain->organization_id;
        $payload = ['domain' => $domain->domain, 'sso_domain' => $domain->ulid, 'was_verified' => $domain->isVerified()];
        $domain->delete();

        SsoTrail::record($organizationId, AuditEventType::SsoDomainRemoved, $payload, $actor);
    }

    /**
     * Domínio verificado → organização dona (entrada pelo e-mail na tela de login).
     */
    public static function verifiedOwner(string $domain): ?SsoDomain
    {
        return SsoDomain::withoutOrganizationScope()->where('verified_domain', strtolower($domain))->first();
    }

    public static function isVerifiedFor(int $organizationId, string $domain): bool
    {
        return SsoDomain::withoutOrganizationScope()
            ->where('organization_id', $organizationId)
            ->where('verified_domain', strtolower($domain))
            ->whereNotNull('verified_at')
            ->exists();
    }

    public static function recordName(SsoDomain $domain): string
    {
        return config('assinavelox.sso.domains.record_prefix', '_assinavelox-sso').'.'.$domain->domain;
    }

    public static function recordValue(SsoDomain $domain): string
    {
        return config('assinavelox.sso.domains.value_prefix', 'assinavelox-sso').'='.$domain->verification_token;
    }

    /**
     * @throws SsoFailure
     */
    public static function normalize(string $input): string
    {
        $domain = strtolower(trim($input));
        $domain = (string) preg_replace('/^[a-z]+:\/\//', '', $domain);
        $domain = rtrim(explode('/', $domain, 2)[0], '.');

        if (str_contains($domain, '@')) {
            $domain = substr($domain, (int) strrpos($domain, '@') + 1);
        }

        if (preg_match('/[^\x21-\x7e]/', $domain) === 1 && function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($domain, IDNA_DEFAULT | IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
            $domain = is_string($ascii) ? strtolower($ascii) : '';
        }

        if (preg_match('/^(?=.{4,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $domain) !== 1) {
            throw new SsoFailure('domain_invalid', 'Informe um domínio completo, como suaempresa.com.br.');
        }

        return $domain;
    }
}
