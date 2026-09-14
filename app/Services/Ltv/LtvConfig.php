<?php

namespace App\Services\Ltv;

/**
 * Leitura tipada de `assinavelox.ltv` (P3-LTV). Sem segredos: só caminhos e parâmetros.
 */
final class LtvConfig
{
    public const LEVELS = ['B-T', 'B-LT', 'B-LTA'];

    public function level(): string
    {
        $level = strtoupper((string) config('assinavelox.ltv.level', 'B-LTA'));

        return in_array($level, self::LEVELS, true) ? $level : 'B-LTA';
    }

    /**
     * @return list<string>
     */
    public function trustRoots(): array
    {
        $roots = $this->paths(config('assinavelox.ltv.trust_roots', []));

        if ($roots === []) {
            $roots = array_values(array_unique([
                ...$this->paths(config('pdftool.trust_roots', [])),
                ...$this->paths(config('assinavelox.tsa.trust_roots', [])),
            ]));
        }

        return $roots;
    }

    /**
     * @return list<string>
     */
    public function crlPaths(): array
    {
        return $this->paths(config('assinavelox.ltv.crl_paths', []));
    }

    /**
     * @return list<string>
     */
    public function ocspPaths(): array
    {
        return $this->paths(config('assinavelox.ltv.ocsp_paths', []));
    }

    public function allowFetching(): bool
    {
        return config('assinavelox.ltv.allow_fetching', false) === true;
    }

    /** Nunca `soft-fail` (viabilidade R5): qualquer outro valor vale `hard-fail`. */
    public function revocationMode(): string
    {
        return config('assinavelox.ltv.revocation_mode') === 'require' ? 'require' : 'hard-fail';
    }

    public function timeout(): int
    {
        return max(30, (int) config('assinavelox.ltv.timeout_seconds', 180));
    }

    public function refreshMarginDays(): int
    {
        return max(1, (int) config('assinavelox.ltv.refresh.margin_days', 30));
    }

    public function refreshBatchSize(): int
    {
        return max(1, (int) config('assinavelox.ltv.refresh.batch_size', 100));
    }

    public function refreshQueue(): string
    {
        return (string) config('assinavelox.ltv.refresh.queue', 'default');
    }

    public function lockWaitSeconds(): int
    {
        return max(0, (int) config('assinavelox.ltv.refresh.lock_wait_seconds', 120));
    }

    /**
     * Argumentos de revogação/confiança comuns a `ltv-sign` e `ltv-refresh`.
     *
     * @return list<string>
     */
    public function revinfoArguments(): array
    {
        $args = [];

        foreach ($this->trustRoots() as $path) {
            array_push($args, '--trust', $path);
        }

        foreach ($this->crlPaths() as $path) {
            array_push($args, '--crl', $path);
        }

        foreach ($this->ocspPaths() as $path) {
            array_push($args, '--ocsp', $path);
        }

        array_push($args, '--revocation-mode', $this->revocationMode());

        if ($this->allowFetching()) {
            $args[] = '--allow-fetching';
        }

        return $args;
    }

    /**
     * @return list<string>
     */
    private function paths(mixed $value): array
    {
        if (is_string($value)) {
            $value = explode(';', $value);
        }

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $path): string => trim((string) $path),
            $value,
        ), static fn (string $path): bool => $path !== ''));
    }
}
