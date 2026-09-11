<?php

namespace App\Integrations\Cpf;

use App\Integrations\Contracts\CpfVerificationProvider;
use App\Services\Identity\CpfNumber;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;

/**
 * Consulta cadastral de CPF no **serviço próprio do proprietário** — PRODUÇÃO DESABILITADA.
 *
 * O serviço não tem documentação disponível (viabilidade, regra fixa 1). Por isso este
 * adaptador NÃO faz chamada nenhuma e não modela endpoint, autenticação ou formato
 * inventados (T4): responde sempre `inconclusive` com `reason_code = not_configured` e a
 * lista exata do que falta em {@see self::MISSING}. Inconclusivo nunca bloqueia o aceite e
 * nunca vira sucesso.
 *
 * SERPRO Consulta CPF, Conecta gov.br e BrasilAPI NÃO são alternativas: o primeiro seria
 * substituição por terceiro (proibida), o segundo só atende órgãos públicos e o terceiro
 * só valida dígitos (docs/integracoes/cnpj-cpf.md §3).
 */
final class OwnServiceCpfVerificationProvider implements CpfVerificationProvider
{
    public const NAME = 'own_service';

    /**
     * O que o proprietário precisa entregar para este adaptador existir de verdade.
     *
     * @var list<string>
     */
    public const MISSING = [
        'URL base e caminho do endpoint de consulta (homologação e produção)',
        'Método de autenticação e credenciais de homologação e produção',
        'Entradas exigidas além do CPF (ex.: data de nascimento, nome) e seu formato',
        'Formato da resposta, campos devolvidos e códigos de situação cadastral',
        'Códigos de erro e o significado de tempo esgotado, 4xx e 5xx',
        'Limites de taxa, SLA e janela de manutenção',
        'Custo por consulta (para os limites por plano)',
        'Base legal e finalidade LGPD aprovadas pelo jurídico (viabilidade §4.4 item 21) e decisão sobre o modo "estrito"',
    ];

    public function __construct(private readonly LoggerInterface $logger) {}

    public static function disabledMessage(): string
    {
        return 'A consulta cadastral de CPF pelo serviço próprio está desabilitada: não há documentação do serviço. Faltam: '
            .implode('; ', self::MISSING).'.';
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function verify(string $cpf, array $context = [], ?string $correlationId = null): array
    {
        $this->logger->warning('Consulta cadastral de CPF não realizada: serviço próprio sem documentação (produção desabilitada).', [
            'cpf' => CpfNumber::mask($cpf),
            'correlation_id' => $correlationId,
        ]);

        return [
            'status' => 'inconclusive',
            'provider' => self::NAME,
            'checked_at' => Carbon::now()->toIso8601String(),
            'details' => [
                'reason_code' => 'not_configured',
                'missing' => self::MISSING,
            ],
        ];
    }
}
