<?php

namespace App\Integrations\Cpf;

use App\Integrations\Contracts\CpfVerificationProvider;
use App\Services\Identity\CpfNumber;
use App\Support\TaxId;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;

/**
 * Simulador IDENTIFICADO da consulta cadastral de CPF (contrato reservado, roadmap §2.11).
 * **Nenhuma consulta é feita.**
 *
 * - Dígitos verificadores errados → `invalid` (`reason_code = check_digits`).
 * - Dígitos corretos → `inconclusive` (`reason_code = simulated`): dígitos válidos não provam
 *   que a pessoa é a titular do CPF, e um simulador não conhece a situação cadastral.
 * - {@see self::simulate()} força o resultado nos testes (inclusive `valid`, sempre com
 *   `details.simulated = true`, que a interface rotula "(simulado)").
 * - Log `[SIMULADO]` com o CPF mascarado; o CPF completo nunca é registrado.
 *
 * - Só responde com `assinavelox.channels.allow_simulated` ligado e fora de produção
 *   ({@see self::isConfigured()}); desligado, `verify()` devolve `inconclusive`/`not_configured`.
 *
 * Quem escolhe o adaptador é App\Integrations\Cpf\CpfVerificationFactory (área C-ID,
 * `assinavelox.cpf_lookup.driver = fake`, recusado em produção e com o interruptor desligado).
 *
 * Nota de integração: este arquivo foi reescrito pela área C-CAN depois de uma sobrescrita
 * acidental do original do C-ID; o contrato usado por App\Services\Identity\CpfLookup
 * (`NAME`, `details.reason_code`, `details.simulated`) foi preservado.
 */
final class FakeCpfVerificationProvider implements CpfVerificationProvider
{
    public const NAME = 'cpf_simulado';

    /** @var 'valid'|'invalid'|'inconclusive'|null */
    private ?string $forced = null;

    public function __construct(private readonly LoggerInterface $logger) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function isSimulated(): bool
    {
        return true;
    }

    /**
     * Mesmo interruptor dos demais simuladores (SMS, WhatsApp, carimbo do tempo):
     * `assinavelox.channels.allow_simulated` ligado E fora de produção
     * (docs/fase-2/identidade.md §2.2). A fábrica também confere, mas o simulador não depende
     * dela: resolvido direto do contêiner com o interruptor desligado, ele não responde.
     */
    public function isConfigured(): bool
    {
        return (bool) config('assinavelox.channels.allow_simulated', false)
            && ! app()->environment('production');
    }

    /**
     * @param  'valid'|'invalid'|'inconclusive'|null  $status  null volta ao comportamento padrão
     */
    public function simulate(?string $status): void
    {
        $this->forced = $status;
    }

    public function verify(string $cpf, array $context = [], ?string $correlationId = null): array
    {
        // Interruptor desligado: não simula nada — resultado desconhecido, nunca sucesso (T5).
        if (! $this->isConfigured()) {
            return [
                'status' => 'inconclusive',
                'provider' => self::NAME,
                'checked_at' => Carbon::now()->toIso8601String(),
                'details' => [
                    'simulated' => true,
                    'reason_code' => 'not_configured',
                    'message' => 'Simulador de consulta cadastral desativado nesta instalação (assinavelox.channels.allow_simulated).',
                ],
            ];
        }

        $validDigits = TaxId::isCpf($cpf);
        $status = $this->forced ?? ($validDigits ? 'inconclusive' : 'invalid');
        $reason = match (true) {
            $this->forced !== null => 'simulated',
            ! $validDigits => 'check_digits',
            default => 'simulated',
        };

        $this->logger->warning('[SIMULADO] Consulta cadastral de CPF NÃO realizada — FakeCpfVerificationProvider.', [
            'provider' => self::NAME,
            'cpf' => CpfNumber::mask($cpf),
            'status' => $status,
            'correlation_id' => $correlationId,
        ]);

        return [
            'status' => $status,
            'provider' => self::NAME,
            'checked_at' => Carbon::now()->toIso8601String(),
            'details' => [
                'simulated' => true,
                'reason_code' => $reason,
                'message' => $validDigits
                    ? 'Simulador: nenhuma consulta cadastral foi feita. Dígitos válidos não provam que a pessoa é a titular do CPF.'
                    : 'Os dígitos verificadores do CPF não conferem.',
            ],
        ];
    }
}
