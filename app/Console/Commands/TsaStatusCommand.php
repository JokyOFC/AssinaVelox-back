<?php

namespace App\Console\Commands;

use App\Services\Timestamp\Models\OperatorTsaIssuance;
use App\Services\Timestamp\OperatorTsaConfig;
use App\Services\Timestamp\PadesProfilePolicy;
use App\Services\Timestamp\TimestampFeatures;
use Illuminate\Console\Command;

/**
 * `php artisan tsa:status [--json]` — situação da TSA da operadora e o checklist de produção
 * (docs/fase-2/carimbo-e-dossie.md §2.5). Nunca imprime senha nem conteúdo de chave: só
 * "definida/ausente", caminhos e metadados públicos.
 *
 * O que o software NÃO consegue verificar sozinho (HSM/KMS, NTP monitorado, AC interna) sai
 * como pendência do proprietário, nunca como "ok".
 */
class TsaStatusCommand extends Command
{
    protected $signature = 'tsa:status {--json : Saída em JSON}';

    protected $description = 'Situação da TSA RFC 3161 da operadora e checklist de produção (sem segredos)';

    public function handle(OperatorTsaConfig $config): int
    {
        $production = $config->environment() === 'production';
        $exampleOid = $config->policyOid() === OperatorTsaConfig::EXAMPLE_POLICY_OID;

        $counts = [];

        foreach ([OperatorTsaIssuance::STATUS_GRANTED, OperatorTsaIssuance::STATUS_REJECTED, OperatorTsaIssuance::STATUS_FAILED, OperatorTsaIssuance::STATUS_RESERVED] as $status) {
            $counts[$status] = OperatorTsaIssuance::query()->where('status', $status)->count();
        }

        $report = [
            'flags' => [
                'operator_tsa' => TimestampFeatures::operatorTsa(),
                'pades_bt' => TimestampFeatures::padesBt(),
            ],
            'environment' => $config->environment(),
            'configured' => $config->isConfigured(),
            'missing' => $config->missing(),
            'pfx_path' => $config->pfxPath(),
            'password_env' => $config->passwordEnv(),
            'password_set' => $config->passwordIsSet(),
            'policy_oid' => $config->policyOid(),
            'accuracy_ms' => $config->accuracyMs(),
            'trust_roots' => count($config->trustRoots()),
            'chain_pem_contains_private_key' => $config->chainPemContainsKey(),
            'issuances' => $counts,
            'announced_pades_profile' => PadesProfilePolicy::declaredProfile(),
            'production_checklist' => [
                ['item' => 'Chave da TSA em HSM/KMS (arquivo restrito só até clientes pagantes)', 'status' => 'pendente — atestação do proprietário'],
                ['item' => 'NTP monitorado, com recusa (timeNotAvailable) fora da precisão declarada', 'status' => 'pendente — infraestrutura; o software não mede o relógio'],
                ['item' => 'OID de política próprio (arco da operadora, ex.: PEN IANA)', 'status' => $exampleOid ? 'pendente — em uso o OID de exemplo 2.25 (só teste)' : 'configurado'],
                ['item' => 'Certificado da TSA emitido pela AC interna, EKU timeStamping crítica', 'status' => $production ? 'declarado produção — conferir a cadeia' : 'pendente — em uso TSA de teste'],
                ['item' => 'Rótulo "carimbo do tempo da operadora — não é carimbo ICP-Brasil" nos Termos', 'status' => 'pendente — revisão jurídica'],
            ],
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->line('TSA da operadora (RFC 3161) — carimbo do tempo da operadora, não é carimbo ICP-Brasil');
        $this->line('Flag operator_tsa: '.($report['flags']['operator_tsa'] ? 'ligada' : 'desligada').' · pades_bt: '.($report['flags']['pades_bt'] ? 'ligada' : 'desligada'));
        $this->line('Ambiente: '.$report['environment'].' · configurada: '.($report['configured'] ? 'sim' : 'não'));
        $this->line('Senha ('.$report['password_env'].'): '.($report['password_set'] ? 'definida' : 'ausente'));
        $this->line('Política: '.$report['policy_oid'].($exampleOid ? ' (OID de EXEMPLO — só teste)' : ''));

        foreach ($report['missing'] as $missing) {
            $this->warn('Falta: '.$missing);
        }

        if ($report['chain_pem_contains_private_key']) {
            $this->error('ASSINAVELOX_TSA_CHAIN_PEM contém uma CHAVE PRIVADA. O dossiê só leva os certificados, mas o arquivo não deve ter a chave: gere a cadeia sem ela (openssl pkcs12 -nokeys) e proteja/rotacione a chave exposta.');
        }

        $this->line(sprintf('Emissões: %d concedidas, %d recusadas, %d falhas', $counts['granted'], $counts['rejected'], $counts['failed']));
        $this->line('Perfil PAdES anunciado: '.$report['announced_pades_profile']);
        $this->newLine();
        $this->line('Checklist de produção:');

        foreach ($report['production_checklist'] as $row) {
            $this->line(' - '.$row['item'].': '.$row['status']);
        }

        return self::SUCCESS;
    }
}
