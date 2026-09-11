<?php

namespace App\Integrations\Email;

use App\Enums\AuditEventType;
use App\Integrations\Contracts\Exceptions\ProviderDisabledException;
use App\Integrations\Contracts\SenderDomainVerifier;
use App\Models\Organization;
use App\Models\SenderDomain;
use App\Models\User;
use App\Services\AdminLog\OrganizationTrail;
use App\Services\Signing\Channels\ChannelFeatures;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Cadastro e verificação de domínios de envio (Fase 2 §2.8, flag `sender_domains`, classe B).
 *
 * A verificação real depende da API do serviço de e-mail do proprietário, que não tem
 * documentação: em produção o verificador é o HttpSenderDomainVerifier, desabilitado, e o
 * cadastro é recusado com o motivo. O simulador serve para desenvolvimento e testes — e um
 * domínio "verificado" por ele fica `is_simulated = true` e nunca vira remetente
 * ({@see SenderDomainVerification}).
 *
 * Isolamento: todo acesso recebe a organização e filtra por ela.
 * Rotas e tela ficam para a integração (fora da área C-CAN) — ver docs/fase-2/canais-e-pin.md §6.
 */
final class SenderDomainRegistry
{
    public function __construct(private readonly Container $container) {}

    public function verifier(): SenderDomainVerifier
    {
        return $this->container->make(SenderDomainVerifier::class);
    }

    /**
     * @return array{available: bool, simulated: bool, provider: string, reason_code: string|null, reason: string|null}
     */
    public function availability(?Organization $organization): array
    {
        $verifier = $this->verifier();
        $base = ['simulated' => $verifier->isSimulated(), 'provider' => $verifier->name()];

        if (! ChannelFeatures::senderDomains($organization)) {
            return $base + [
                'available' => false,
                'reason_code' => 'feature_disabled',
                'reason' => 'Domínios de envio não estão habilitados para esta organização.',
            ];
        }

        if (! $verifier->isConfigured()) {
            return $base + [
                'available' => false,
                'reason_code' => 'provider_disabled',
                'reason' => 'A verificação de domínio está desativada nesta instalação: a API do serviço de e-mail ainda não foi integrada (faltam documentação e credenciais). Os e-mails saem pelo remetente padrão, com Reply-To da sua organização.',
            ];
        }

        return $base + ['available' => true, 'reason_code' => null, 'reason' => null];
    }

    /**
     * @throws ValidationException
     */
    public function create(Organization $organization, string $domain, ?User $by = null): SenderDomain
    {
        $availability = $this->availability($organization);

        if (! $availability['available']) {
            throw ValidationException::withMessages(['domain' => (string) $availability['reason']]);
        }

        $normalized = self::normalizeDomain($domain);

        if ($normalized === null) {
            throw ValidationException::withMessages(['domain' => 'Informe um domínio válido, como empresa.com.br.']);
        }

        if (in_array($normalized, self::platformDomains(), true)) {
            throw ValidationException::withMessages(['domain' => 'Este domínio é da plataforma e não pode ser usado como remetente próprio.']);
        }

        $query = SenderDomain::forOrganization($organization);

        if ((clone $query)->where('domain', $normalized)->exists()) {
            throw ValidationException::withMessages(['domain' => 'Este domínio já está cadastrado.']);
        }

        $max = max(1, (int) config('assinavelox.sender_domains.max_per_organization', 5));

        if ((clone $query)->count() >= $max) {
            throw ValidationException::withMessages(['domain' => sprintf('Sua organização já tem o máximo de %d domínios de envio.', $max)]);
        }

        $verifier = $this->verifier();
        $token = bin2hex(random_bytes(16));
        $correlationId = (string) Str::ulid();

        try {
            $registered = $verifier->register($normalized, $token, $correlationId);
        } catch (ProviderDisabledException $exception) {
            throw ValidationException::withMessages(['domain' => 'A verificação de domínio está desativada nesta instalação.']);
        }

        /** @var SenderDomain $senderDomain */
        $senderDomain = SenderDomain::query()->create([
            'organization_id' => $organization->getKey(),
            'domain' => $normalized,
            'status' => SenderDomain::STATUS_PENDING,
            'verification_token' => $token,
            'expected_records' => $registered['records'],
            'provider' => $verifier->name(),
            'provider_domain_id' => $registered['provider_domain_id'],
            'is_simulated' => $verifier->isSimulated(),
            'created_by_user_id' => $by?->getKey(),
        ]);

        OrganizationTrail::record((int) $organization->getKey(), AuditEventType::SenderDomainCreated, [
            'sender_domain' => $senderDomain->ulid,
            'domain' => $normalized,
            'simulated' => $verifier->isSimulated(),
        ], $by, $correlationId);

        return $senderDomain;
    }

    /**
     * Consulta o provedor e atualiza a situação. Resposta inconclusiva não muda o status.
     */
    public function check(SenderDomain $senderDomain, ?User $by = null): SenderDomain
    {
        $verifier = $this->verifier();

        if (! $verifier->isConfigured() || $verifier->name() !== $senderDomain->provider) {
            return $senderDomain;
        }

        $correlationId = (string) Str::ulid();

        try {
            $result = $verifier->check($senderDomain->domain, $senderDomain->provider_domain_id, $correlationId);
        } catch (Throwable $exception) {
            Log::warning('sender_domains.check.inconclusive', [
                'sender_domain' => $senderDomain->ulid,
                'exception' => $exception::class,
                'correlation_id' => $correlationId,
            ]);

            $senderDomain->forceFill(['last_checked_at' => Carbon::now()])->save();

            return $senderDomain;
        }

        $previous = $senderDomain->status;
        $status = $result['status'];

        $senderDomain->forceFill([
            'status' => $status,
            'last_checked_at' => Carbon::now(),
            'verified_at' => $status === SenderDomain::STATUS_VERIFIED ? ($senderDomain->verified_at ?? Carbon::now()) : null,
            'failed_at' => $status === SenderDomain::STATUS_FAILED ? Carbon::now() : null,
            'failure_reason' => $status === SenderDomain::STATUS_FAILED ? Str::limit((string) ($result['reason'] ?? 'O provedor recusou o domínio.'), 250, '') : null,
            'is_simulated' => $verifier->isSimulated(),
        ])->save();

        if ($previous !== $status && in_array($status, [SenderDomain::STATUS_VERIFIED, SenderDomain::STATUS_FAILED], true)) {
            OrganizationTrail::record(
                (int) $senderDomain->organization_id,
                $status === SenderDomain::STATUS_VERIFIED ? AuditEventType::SenderDomainVerified : AuditEventType::SenderDomainFailed,
                ['sender_domain' => $senderDomain->ulid, 'domain' => $senderDomain->domain, 'simulated' => $verifier->isSimulated()],
                $by,
                $correlationId,
            );
        }

        return $senderDomain;
    }

    public function delete(SenderDomain $senderDomain, ?User $by = null): void
    {
        $payload = ['sender_domain' => $senderDomain->ulid, 'domain' => $senderDomain->domain];
        $organizationId = (int) $senderDomain->organization_id;

        $senderDomain->delete();

        OrganizationTrail::record($organizationId, AuditEventType::SenderDomainDeleted, $payload, $by);
    }

    /**
     * Props da futura tela de domínios (settings): disponibilidade + lista.
     *
     * @return array<string, mixed>
     */
    public function props(Organization $organization): array
    {
        return [
            'availability' => $this->availability($organization),
            'domains' => SenderDomain::forOrganization($organization)
                ->orderBy('domain')
                ->get()
                ->map(fn (SenderDomain $domain): array => [
                    'id' => $domain->ulid,
                    'domain' => $domain->domain,
                    'status' => $domain->status,
                    'status_label' => SenderDomain::statusLabel($domain->status),
                    'simulated' => $domain->is_simulated,
                    'usable_as_sender' => $domain->isVerified() && ! $domain->is_simulated,
                    'records' => $domain->expected_records ?? [],
                    'verified_at' => $domain->verified_at?->toIso8601String(),
                    'last_checked_at' => $domain->last_checked_at?->toIso8601String(),
                    'failure_reason' => $domain->failure_reason,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Domínio em minúsculas, ASCII (IDN convertido), sem ponto final; null quando inválido.
     */
    public static function normalizeDomain(string $raw): ?string
    {
        $domain = rtrim(mb_strtolower(trim($raw)), '.');

        if ($domain === '' || str_contains($domain, '@') || str_contains($domain, '/')) {
            return null;
        }

        if (preg_match('/[^\x20-\x7E]/', $domain) === 1) {
            $ascii = function_exists('idn_to_ascii') ? idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) : false;

            if (! is_string($ascii)) {
                return null;
            }

            $domain = $ascii;
        }

        if (strlen($domain) > 253 || ! str_contains($domain, '.')) {
            return null;
        }

        $pattern = '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+(?:[a-z]{2,63}|xn--[a-z0-9-]{1,59})$/';

        return preg_match($pattern, $domain) === 1 ? $domain : null;
    }

    /**
     * @return list<string>
     */
    private static function platformDomains(): array
    {
        $domains = [];
        $from = config('mail.from.address');

        if (is_string($from) && str_contains($from, '@')) {
            $domains[] = mb_strtolower((string) substr((string) strrchr($from, '@'), 1));
        }

        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        if (is_string($host) && $host !== '') {
            $domains[] = mb_strtolower($host);
        }

        return $domains;
    }
}
