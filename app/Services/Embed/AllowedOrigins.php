<?php

namespace App\Services\Embed;

use App\Models\IntegrationSetting;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Origens que podem hospedar o widget de assinatura embutida (Fase 3 §3.9 —
 * docs/fase-3/widget-embutido.md §4). Tabela `integration_settings.allowed_origins`.
 *
 * Uma origem é EXATA: `https://host[:porta]`, como o navegador a serializa em `event.origin` e
 * como a CSP a compara em `frame-ancestors`. Por isso a forma canônica:
 *
 * - esquema `https` (e `http` só para `localhost`, `127.0.0.1` e `[::1]`, e só fora de produção);
 * - host em minúsculas, em ASCII (IDN convertido para punycode), com pelo menos um ponto;
 * - sem curinga, sem usuário/senha, sem caminho (além de "/"), sem query, sem fragmento;
 * - sem IP literal (exceto o loopback fora de produção);
 * - porta padrão removida (`https://a.com:443` vira `https://a.com`), outras mantidas.
 *
 * A leitura também passa pela normalização: uma origem de loopback gravada em homologação e
 * copiada para produção simplesmente deixa de valer lá.
 */
final class AllowedOrigins
{
    private const LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '[::1]'];

    public static function maxPerOrganization(): int
    {
        return max(1, (int) config('assinavelox.embedded_signing.max_allowed_origins', 20));
    }

    /**
     * Forma canônica da origem, ou `null` quando ela não é aceitável.
     */
    public static function normalize(string $raw): ?string
    {
        $value = trim($raw);

        if ($value === '' || strlen($value) > 255 || preg_match('/[\s*\\\\"\'<>`]/', $value) === 1) {
            return null;
        }

        $parts = parse_url($value);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }

        if (isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/') {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        $port = isset($parts['port']) ? (int) $parts['port'] : null;

        // `parse_url` já recusa porta acima de 65535; a porta 0 não é origem.
        if ($port !== null && $port < 1) {
            return null;
        }

        $loopback = in_array($host, self::LOOPBACK_HOSTS, true);

        if ($loopback) {
            if (app()->environment('production')) {
                return null;
            }

            if (! in_array($scheme, ['http', 'https'], true)) {
                return null;
            }
        } else {
            if ($scheme !== 'https') {
                return null;
            }

            $host = self::asciiHost($host);

            if ($host === null) {
                return null;
            }
        }

        $defaultPort = $scheme === 'https' ? 443 : 80;
        $suffix = $port !== null && $port !== $defaultPort ? ':'.$port : '';

        return $scheme.'://'.$host.$suffix;
    }

    /**
     * Hostname DNS em ASCII, sem IP literal e com pelo menos um ponto.
     */
    private static function asciiHost(string $host): ?string
    {
        if (str_starts_with($host, '[') || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return null;
        }

        // Formatos alternativos de IPv4 (decimal, octal, hexadecimal: `2130706433`, `0x7f.0.0.1`,
        // `127.1`): nenhum domínio real termina num rótulo numérico, então o último rótulo decide.
        $labels = explode('.', rtrim($host, '.'));

        if (preg_match('/^(0x[0-9a-f]*|\d+)$/', (string) end($labels)) === 1) {
            return null;
        }

        if (preg_match('/[^\x20-\x7e]/', $host) === 1) {
            if (! function_exists('idn_to_ascii')) {
                return null;
            }

            $converted = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

            if (! is_string($converted) || $converted === '') {
                return null;
            }

            $host = strtolower($converted);
        }

        $host = rtrim($host, '.');

        if (strlen($host) > 253 || ! str_contains($host, '.')) {
            return null;
        }

        foreach (explode('.', $host) as $label) {
            if (preg_match('/^(?!-)[a-z0-9-]{1,63}(?<!-)$/', $label) !== 1) {
                return null;
            }
        }

        return $host;
    }

    /**
     * Origens válidas da organização, na forma canônica e sem repetição.
     *
     * @return list<string>
     */
    public static function forOrganization(Organization $organization): array
    {
        /** @var IntegrationSetting|null $settings */
        $settings = IntegrationSetting::withoutGlobalScopes()
            ->where('organization_id', $organization->getKey())
            ->first();

        $origins = [];

        foreach ($settings->allowed_origins ?? [] as $raw) {
            $origin = self::normalize($raw);

            if ($origin !== null && ! in_array($origin, $origins, true)) {
                $origins[] = $origin;
            }
        }

        return $origins;
    }

    public static function allows(Organization $organization, string $origin): bool
    {
        $normalized = self::normalize($origin);

        return $normalized !== null && in_array($normalized, self::forOrganization($organization), true);
    }

    /**
     * Substitui a lista. Cada item é normalizado; qualquer item inválido recusa a lista inteira
     * (ValidationException com a mensagem no índice), para a tela não gravar metade do que a
     * pessoa digitou.
     *
     * @param  list<mixed>  $raw
     * @return list<string>
     *
     * @throws ValidationException
     */
    public static function replace(Organization $organization, array $raw, ?User $by = null): array
    {
        $origins = [];
        $errors = [];

        foreach ($raw as $index => $value) {
            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            $origin = self::normalize($value);

            if ($origin === null) {
                $errors["origins.{$index}"] = [self::invalidMessage()];

                continue;
            }

            if (! in_array($origin, $origins, true)) {
                $origins[] = $origin;
            }
        }

        if (count($origins) > self::maxPerOrganization()) {
            $errors['origins'] = [sprintf('Cadastre no máximo %d origens.', self::maxPerOrganization())];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        IntegrationSetting::withoutGlobalScopes()->updateOrCreate(
            ['organization_id' => $organization->getKey()],
            ['allowed_origins' => $origins, 'updated_by_user_id' => $by?->getKey()],
        );

        return $origins;
    }

    public static function invalidMessage(): string
    {
        return app()->environment('production')
            ? 'Use uma origem exata no formato https://site.com.br (com porta, se houver), sem caminho nem curinga.'
            : 'Use uma origem exata no formato https://site.com.br (com porta, se houver), sem caminho nem curinga. Fora de produção, http://localhost também é aceito.';
    }
}
