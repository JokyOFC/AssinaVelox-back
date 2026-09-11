<?php

namespace App\Console\Commands;

use App\Services\Timestamp\Exceptions\TsaException;
use App\Services\Timestamp\TsaToolRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Env;

/**
 * `php artisan tsa:generate-test` — gera uma TSA de TESTE para desenvolvimento e homologação
 * (roadmap §2.13; docs/fase-2/carimbo-e-dossie.md §2.4).
 *
 * - AC interna de teste + certificado da TSA com EKU `timeStamping` crítica, CN com "TESTE";
 * - PKCS#12 protegido pela senha que JÁ está na variável de ambiente nomeada em `--pass-env`
 *   (o comando nunca inventa, imprime ou registra a senha);
 * - RECUSA rodar em `APP_ENV=production`, sem exceção: TSA de teste nunca é de produção.
 */
class TsaGenerateTestCommand extends Command
{
    protected $signature = 'tsa:generate-test
        {--dir= : Diretório de saída (padrão storage/app/private/tsa-test)}
        {--pass-env=ASSINAVELOX_TSA_PASSWORD : NOME da variável de ambiente com a senha do PKCS#12}
        {--days=365 : Validade do certificado de teste}
        {--key=rsa-3072 : rsa-3072 ou ec-p256}
        {--force : Sobrescreve arquivos existentes}';

    protected $description = 'Gera uma TSA RFC 3161 de TESTE (autoassinada, marcada TESTE) — nunca para produção';

    public function handle(TsaToolRunner $runner): int
    {
        if ($this->laravel->environment('production')) {
            $this->error('Recusado: uma TSA de TESTE nunca é gerada em produção. Produção exige chave em HSM/KMS e certificado da AC interna (tsa:status).');

            return self::FAILURE;
        }

        $passEnv = (string) $this->option('pass-env');

        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $passEnv) !== 1) {
            $this->error('Nome de variável de ambiente inválido.');

            return self::FAILURE;
        }

        $password = Env::getRepository()->get($passEnv);

        if (! is_string($password) || $password === '') {
            $this->error("Defina a senha na variável de ambiente {$passEnv} antes de gerar (ela nunca é impressa nem gravada pelo comando).");

            return self::FAILURE;
        }

        $key = (string) $this->option('key');

        if (! in_array($key, ['rsa-3072', 'ec-p256'], true)) {
            $this->error('--key deve ser rsa-3072 ou ec-p256.');

            return self::FAILURE;
        }

        $dir = rtrim((string) ($this->option('dir') ?: storage_path('app/private/tsa-test')), '/\\');
        $pfx = $dir.DIRECTORY_SEPARATOR.'tsa-teste.pfx';
        $root = $dir.DIRECTORY_SEPARATOR.'ac-interna-teste.pem';
        $chain = $dir.DIRECTORY_SEPARATOR.'cadeia-tsa-teste.pem';

        if (! $this->option('force') && (is_file($pfx) || is_file($root))) {
            $this->error('Já existe uma TSA de teste nesse diretório. Use --force para substituir.');

            return self::FAILURE;
        }

        if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
            $this->error('Não foi possível criar o diretório de saída.');

            return self::FAILURE;
        }

        try {
            $result = $runner->run('tsa-gen-test', [
                '--out-pfx', $pfx,
                '--pass-env', $passEnv,
                '--out-root-pem', $root,
                '--out-chain-pem', $chain,
                '--days', (string) max(1, min(3650, (int) $this->option('days'))),
                '--key', $key,
            ], [$passEnv]);
        } catch (TsaException $exception) {
            $this->error('Falha ao gerar a TSA de teste: '.$exception->errorCode);

            return self::FAILURE;
        }

        $this->warn('TSA de TESTE gerada. NÃO é ACT ICP-Brasil, não tem validade jurídica e não pode ir para produção.');
        $this->line('Titular: '.(string) ($result['tsa_subject'] ?? ''));
        $this->line('Impressão digital SHA-256: '.(string) ($result['tsa_cert_fingerprint_sha256'] ?? ''));
        $this->line('Válido até: '.(string) ($result['not_after'] ?? ''));
        $this->newLine();
        $this->line('Acrescente ao .env (a senha continua só na variável '.$passEnv.'):');
        $this->line('ASSINAVELOX_TSA_PFX_PATH='.$pfx);
        $this->line('ASSINAVELOX_TSA_PASSWORD_ENV='.$passEnv);
        $this->line('ASSINAVELOX_TSA_CHAIN_PEM='.$chain);
        $this->line('ASSINAVELOX_TSA_TRUST_ROOTS='.$root);
        $this->line('ASSINAVELOX_TSA_ENVIRONMENT=test');
        $this->line('ASSINAVELOX_FEATURE_OPERATOR_TSA=true');

        return self::SUCCESS;
    }
}
