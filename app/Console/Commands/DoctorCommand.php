<?php

namespace App\Console\Commands;

use App\Integrations\Pdf\LibreOfficeConverter;
use App\Integrations\Pdf\PyHankoSigner;
use App\Services\Pdf\PdfToolClient;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Saúde da instalação (docs/seguranca-operacional.md §4).
 *
 *   php artisan assinavelox:doctor [--json] [--strict]
 *
 * Regra absoluta deste comando: **nenhum segredo é impresso**. Ele responde "está
 * definido?", "é válido?", "vence quando?" — nunca "vale quanto". Chave de aplicação,
 * senha do PKCS#12, token do Mercado Pago e segredo do webhook aparecem apenas como
 * `definido` / `ausente`, com o NOME da variável.
 *
 * Cada item tem um estado: `ok`, `aviso` (funciona, mas há um risco ou uma funcionalidade
 * desligada) ou `falha` (a instalação não está em condições). O código de saída é 1 quando
 * há falha; com `--strict`, também quando há aviso.
 */
class DoctorCommand extends Command
{
    protected $signature = 'assinavelox:doctor
        {--json : Saída em JSON (para monitoramento)}
        {--strict : Trata avisos como falha no código de saída}';

    protected $description = 'Verifica a saúde da instalação (configuração, disco, fila, pdftool, certificado, cobrança). Nunca imprime segredos.';

    private const OK = 'ok';

    private const WARN = 'aviso';

    private const FAIL = 'falha';

    /** @var list<array<string, mixed>> */
    private array $checks = [];

    public function handle(PdfToolClient $pdftool, LibreOfficeConverter $libreOffice, PyHankoSigner $signer): int
    {
        $this->checkApplication();
        $this->checkDatabase();
        $this->checkDocumentsDisk();
        $this->checkStorageEncryption();
        $this->checkQueueAndScheduler();
        $this->checkMail();
        $this->checkPdftool($pdftool);
        $this->checkLibreOffice($libreOffice);
        $this->checkCertificate($signer, $pdftool);
        $this->checkMercadoPago();
        $this->checkAuditCheckpoints();

        $failures = $this->countBy(self::FAIL);
        $warnings = $this->countBy(self::WARN);

        $report = [
            'ok' => $failures === 0 && ! ($this->option('strict') && $warnings > 0),
            'environment' => (string) config('app.env'),
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'failures' => $failures,
            'warnings' => $warnings,
            'checks' => $this->checks,
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return $report['ok'] ? self::SUCCESS : self::FAILURE;
        }

        $this->render($report);

        return $report['ok'] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function add(string $group, string $name, string $status, string $message, array $details = []): void
    {
        $this->checks[] = [
            'group' => $group,
            'name' => $name,
            'status' => $status,
            'message' => $message,
            'details' => $details,
        ];
    }

    private function countBy(string $status): int
    {
        return count(array_filter($this->checks, static fn (array $check): bool => $check['status'] === $status));
    }

    private function checkApplication(): void
    {
        $production = app()->isProduction();

        $key = (string) config('app.key');
        $this->add('Aplicação', 'APP_KEY', $key === '' ? self::FAIL : self::OK,
            $key === ''
                ? 'Ausente. Sem ela não há criptografia de `organizations.tax_id` nem HMAC dos códigos por e-mail.'
                : 'Definida (valor nunca exibido).');

        $url = trim((string) config('app.url'));
        $isHttps = str_starts_with(strtolower($url), 'https://');
        $this->add('Aplicação', 'APP_URL', match (true) {
            $url === '' => self::FAIL,
            $production && ! $isHttps => self::FAIL,
            default => self::OK,
        }, $url === ''
            ? 'Vazia: os links de convite e de redefinição de senha ficam sem raiz confiável.'
            : ($production && ! $isHttps ? "Em produção precisa ser HTTPS (atual: {$url})." : $url));

        $debug = (bool) config('app.debug');
        $this->add('Aplicação', 'APP_DEBUG', $production && $debug ? self::FAIL : self::OK,
            $production && $debug
                ? 'Ligado em produção: a página de erro exporia caminhos, consultas e trechos de configuração.'
                : ($debug ? 'Ligado (ambiente não produtivo).' : 'Desligado.'));

        $this->add('Aplicação', 'Fuso horário', config('app.timezone') === 'UTC' ? self::OK : self::WARN,
            'Timestamps gravados em '.(string) config('app.timezone').' (o contrato é UTC).');

        $proxies = trim((string) config('assinavelox.trusted_proxies', ''));
        $this->add('Aplicação', 'TRUSTED_PROXIES', match (true) {
            $proxies === '' && $production => self::WARN,
            $proxies === '*' || $proxies === '**' => self::WARN,
            default => self::OK,
        }, match (true) {
            $proxies === '' && $production => 'Vazio: atrás de balanceador, o IP dos aceites e dos limitadores é o do balanceador.',
            $proxies === '*' || $proxies === '**' => 'Curinga: o X-Forwarded-For é ignorado de propósito (seria forjável). Configure a lista de IPs/CIDRs.',
            $proxies === '' => 'Vazio (sem proxy confiável).',
            default => 'Lista de proxies configurada.',
        });

        $termsVersion = trim((string) config('assinavelox.terms_version', ''));
        $this->add('Aplicação', 'Versão dos Termos', $termsVersion === '' ? self::FAIL : self::OK,
            $termsVersion === '' ? 'Vazia: o aceite eletrônico ficaria sem versão de termos gravada.' : $termsVersion);

        $csp = (bool) config('assinavelox.security_headers.csp_enabled', true);
        $reportOnly = (bool) config('assinavelox.security_headers.csp_report_only', false);
        $this->add('Aplicação', 'Content-Security-Policy', match (true) {
            ! $csp && $production => self::FAIL,
            $reportOnly && $production => self::WARN,
            default => self::OK,
        }, match (true) {
            ! $csp => 'Desligada.',
            $reportOnly => 'Em modo report-only: as violações são relatadas, nada é bloqueado.',
            default => 'Ligada, com nonce por resposta.',
        });
    }

    private function checkDatabase(): void
    {
        try {
            $driver = DB::connection()->getDriverName();
            DB::connection()->getPdo();

            $pending = $this->pendingMigrations();

            $this->add('Banco', 'Conexão', self::OK, "Conectado ({$driver}).");
            $this->add('Banco', 'Migrations', $pending === null ? self::WARN : ($pending > 0 ? self::FAIL : self::OK),
                match (true) {
                    $pending === null => 'Não foi possível ler a tabela `migrations`.',
                    $pending > 0 => "{$pending} migration(s) pendente(s): rode `php artisan migrate`.",
                    default => 'Em dia.',
                });
        } catch (Throwable $exception) {
            $this->add('Banco', 'Conexão', self::FAIL, 'Falha ao conectar: '.Str::limit($exception->getMessage(), 200));
        }
    }

    private function pendingMigrations(): ?int
    {
        try {
            $applied = DB::table('migrations')->pluck('migration')->all();
            $files = glob(database_path('migrations').DIRECTORY_SEPARATOR.'*.php') ?: [];

            $names = array_map(static fn (string $file): string => basename($file, '.php'), $files);

            return count(array_diff($names, $applied));
        } catch (Throwable) {
            return null;
        }
    }

    private function checkDocumentsDisk(): void
    {
        $driver = (string) config('filesystems.disks.documents.driver', '');
        $visibility = (string) config('filesystems.disks.documents.visibility', '');

        try {
            $disk = Storage::disk('documents');
            $probe = 'hardening/doctor-'.Str::ulid().'.txt';
            $disk->put($probe, 'doctor');
            $writable = $disk->exists($probe);
            $disk->delete($probe);

            $this->add('Armazenamento', 'Disco `documents` gravável', $writable ? self::OK : self::FAIL,
                $writable ? "Driver {$driver}." : 'A escrita de prova não apareceu no disco.');
        } catch (Throwable $exception) {
            $this->add('Armazenamento', 'Disco `documents` gravável', self::FAIL,
                'Falha ao gravar: '.Str::limit($exception->getMessage(), 200));
        }

        $private = $visibility === 'private' && ! (bool) config('filesystems.disks.documents.serve', false);
        $this->add('Armazenamento', 'Disco `documents` privado', $private ? self::OK : self::FAIL,
            $private
                ? 'visibility=private, sem rota de acesso direto (downloads passam por controller autorizado).'
                : "visibility={$visibility} / serve habilitado: os arquivos ficariam acessíveis sem autorização.");
    }

    private function checkQueueAndScheduler(): void
    {
        $connection = (string) config('queue.default');
        $production = app()->isProduction();

        $this->add('Fila', 'Conexão', match (true) {
            $connection === 'sync' && $production => self::FAIL,
            $connection === 'sync' => self::WARN,
            default => self::OK,
        }, match (true) {
            $connection === 'sync' && $production => 'sync em produção: conversão e finalização rodariam dentro da requisição HTTP.',
            $connection === 'sync' => 'sync (nada é enfileirado de verdade).',
            default => $connection,
        });

        $queues = (array) config('assinavelox.queues', []);
        $this->add('Fila', 'Filas nomeadas', $queues === [] ? self::FAIL : self::OK,
            $queues === [] ? 'Nenhuma fila configurada.' : implode(', ', array_values($queues)));

        if ($connection === 'database') {
            try {
                $pending = DB::table('jobs')->count();
                $failed = DB::table('failed_jobs')->count();
                $backlog = (int) config('assinavelox.observability.health.queue_backlog_warning', 100);

                $this->add('Fila', 'Trabalho acumulado', $pending > $backlog ? self::WARN : self::OK,
                    "{$pending} na fila, {$failed} com falha registrada.");
            } catch (Throwable) {
                $this->add('Fila', 'Trabalho acumulado', self::WARN, 'Tabelas `jobs`/`failed_jobs` indisponíveis.');
            }
        }

        // Agendador: existe pelo menos um comando agendado? (o cron em si não é observável daqui)
        try {
            $schedule = app(Schedule::class);
            $events = count($schedule->events());

            $this->add('Agendador', 'Comandos agendados', $events > 0 ? self::OK : self::FAIL,
                $events > 0
                    ? "{$events} comando(s) em routes/console.php. O cron chamando `schedule:run` a cada minuto NÃO é verificável daqui — confira no servidor."
                    : 'Nenhum comando agendado: expiração de envelopes e inadimplência não rodariam.');
        } catch (Throwable $exception) {
            $this->add('Agendador', 'Comandos agendados', self::WARN, Str::limit($exception->getMessage(), 160));
        }
    }

    private function checkMail(): void
    {
        $mailer = (string) config('mail.default');
        $from = (string) config('mail.from.address', '');
        $provider = (string) config('assinavelox.email.provider', 'laravel');
        $inconclusive = (array) config('assinavelox.email.inconclusive_mailers', []);
        $production = app()->isProduction();

        $transmits = $provider !== 'log' && ! in_array($mailer, $inconclusive, true);

        $this->add('E-mail', 'Transporte', match (true) {
            ! $transmits && $production => self::FAIL,
            ! $transmits => self::WARN,
            default => self::OK,
        }, $transmits
            ? "provider={$provider}, mailer={$mailer}."
            : "provider={$provider}, mailer={$mailer}: nada é transmitido; os recibos ficam `unknown` (não `sent`).");

        $this->add('E-mail', 'Remetente', $from === '' ? self::FAIL : self::OK,
            $from === '' ? 'MAIL_FROM_ADDRESS vazio: convites e códigos não sairiam.' : $from);
    }

    private function checkPdftool(PdfToolClient $pdftool): void
    {
        if (! $pdftool->isAvailable()) {
            $this->add('PDF', 'pdftool', self::FAIL,
                'Interpretador ou pacote indisponível em '.$pdftool->pythonBinary().'. Sem ele não há inspeção, composição nem assinatura.');

            return;
        }

        try {
            $result = $pdftool->selftest();
            $ok = (bool) ($result['ok'] ?? false);

            $steps = array_values(array_filter((array) ($result['steps'] ?? []), 'is_array'));
            $failed = array_values(array_filter($steps, static fn (array $step): bool => ! (bool) ($step['ok'] ?? false)));

            $this->add('PDF', 'pdftool selftest', $ok ? self::OK : self::FAIL,
                $ok
                    ? sprintf('%d etapa(s) ok (versão %s).', count($steps), $pdftool->version())
                    : 'Falhou em: '.implode(', ', array_map(static fn (array $s): string => (string) ($s['step'] ?? '?'), $failed)));
        } catch (Throwable $exception) {
            $this->add('PDF', 'pdftool selftest', self::FAIL, Str::limit($exception->getMessage(), 200));
        }

        $tmp = $pdftool->temporaryRoot();
        $this->add('PDF', 'Diretório temporário', is_dir($tmp) && is_writable($tmp) ? self::OK : self::WARN,
            is_dir($tmp) ? $tmp : "{$tmp} (será criado na primeira operação).");
    }

    private function checkLibreOffice(LibreOfficeConverter $libreOffice): void
    {
        $binary = $libreOffice->binary();

        $this->add('PDF', 'LibreOffice (DOCX para PDF)', match (true) {
            $libreOffice->isConfigured() => self::OK,
            $binary === '' => self::WARN,
            default => self::FAIL,
        }, match (true) {
            $libreOffice->isConfigured() => sprintf('%s (timeout %ds).', (string) $libreOffice->resolvedBinary(), $libreOffice->timeout()),
            $binary === '' => 'Ausente (LIBREOFFICE_BIN vazio): o upload de DOCX é recusado com mensagem clara — não é erro, é funcionalidade indisponível.',
            default => "LIBREOFFICE_BIN aponta para {$binary}, que não existe.",
        });
    }

    private function checkCertificate(PyHankoSigner $signer, PdfToolClient $pdftool): void
    {
        if (! $signer->isEnabled()) {
            $this->add('Certificado A1', 'Estado', self::WARN,
                'Desligado (COMPANY_CERT_ENABLED=false). Os envelopes concluem como ACEITE ELETRÔNICO COM EVIDÊNCIAS, '
                .'e a interface diz exatamente isso. Não é defeito.');

            return;
        }

        $problems = $signer->configurationProblems();

        if (! $signer->isConfigured()) {
            $this->add('Certificado A1', 'Configuração', self::FAIL,
                'Ligado mas não configurado: '.(implode(' ', $problems) ?: 'motivo não informado.'),
                ['password_env' => $signer->passwordEnvName()]);

            return;
        }

        $environment = $signer->environment()->value;

        $this->add('Certificado A1', 'Configuração', self::OK, sprintf(
            'Ativo — %s, ambiente %s, perfil %s. Senha lida da variável %s (valor nunca exibido).',
            $signer->certificateName(),
            $environment,
            PyHankoSigner::PROFILE,
            $signer->passwordEnvName(),
        ));

        if ($environment !== 'production') {
            $this->add('Certificado A1', 'Ambiente', self::WARN,
                'Certificado de TESTE: as assinaturas são rotuladas como teste e nunca podem ser apresentadas como ICP-Brasil.');
        }

        try {
            $info = $pdftool->certificateInfo($signer->pfxPath(), $signer->passwordEnvName());

            $notBefore = isset($info['not_before']) ? CarbonImmutable::parse((string) $info['not_before']) : null;
            $notAfter = isset($info['not_after']) ? CarbonImmutable::parse((string) $info['not_after']) : null;
            $now = CarbonImmutable::now();

            if ($notAfter === null) {
                $this->add('Certificado A1', 'Validade', self::WARN, 'O pdftool não devolveu a data de expiração.');

                return;
            }

            $days = (int) floor($now->diffInSeconds($notAfter, false) / 86400);
            $warningDays = (int) config('assinavelox.observability.health.certificate_warning_days', 30);

            $status = match (true) {
                $days < 0 => self::FAIL,
                $notBefore !== null && $now->lessThan($notBefore) => self::FAIL,
                $days <= $warningDays => self::WARN,
                default => self::OK,
            };

            $this->add('Certificado A1', 'Validade', $status, match (true) {
                $days < 0 => sprintf('VENCIDO há %d dia(s) (em %s). Toda finalização volta a concluir como aceite eletrônico.', abs($days), $notAfter->toDateString()),
                $notBefore !== null && $now->lessThan($notBefore) => sprintf('Ainda não vigente (válido a partir de %s).', $notBefore->toDateString()),
                $days <= $warningDays => sprintf('Vence em %d dia(s) (%s). Inicie a renovação — ver ciclo de vida em docs/seguranca-operacional.md §4.', $days, $notAfter->toDateString()),
                default => sprintf('Válido até %s (%d dias restantes).', $notAfter->toDateString(), $days),
            }, [
                'not_before' => $notBefore?->toIso8601String(),
                'not_after' => $notAfter->toIso8601String(),
                'days_remaining' => $days,
                // Metadados públicos: identificam o certificado sem revelar chave nenhuma.
                'subject' => $info['subject'] ?? null,
                'issuer' => $info['issuer'] ?? null,
                'fingerprint_sha256' => $info['cert_fingerprint_sha256'] ?? ($info['fingerprint_sha256'] ?? null),
            ]);
        } catch (Throwable $exception) {
            $this->add('Certificado A1', 'Validade', self::FAIL,
                'Não foi possível ler os metadados do PKCS#12 (senha errada na variável de ambiente, arquivo corrompido?): '
                .Str::limit($exception->getMessage(), 160));
        }

        $roots = $signer->trustRoots();
        $missing = array_values(array_filter($roots, static fn (string $root): bool => ! is_file($root)));

        $this->add('Certificado A1', 'Raízes de confiança', match (true) {
            $missing !== [] => self::FAIL,
            $roots === [] => self::WARN,
            default => self::OK,
        }, match (true) {
            $missing !== [] => 'Arquivo(s) inexistente(s): '.implode(', ', $missing),
            $roots === [] => 'Nenhuma configurada: `validate` afirma integridade, mas devolve trusted=false. Revogação nunca é checada.',
            default => count($roots).' configurada(s).',
        });
    }

    private function checkMercadoPago(): void
    {
        $driver = (string) config('assinavelox.mercadopago.driver', 'auto');
        $environment = (string) config('assinavelox.mercadopago.environment', 'sandbox');
        $hasToken = trim((string) config('assinavelox.mercadopago.access_token', '')) !== '';
        $hasWebhookSecret = trim((string) config('assinavelox.mercadopago.webhook_secret', '')) !== '';
        $production = app()->isProduction();

        $this->add('Cobrança', 'Credenciais', match (true) {
            $hasToken => self::OK,
            $driver === 'fake' => self::WARN,
            $production => self::FAIL,
            default => self::WARN,
        }, match (true) {
            $hasToken => "MERCADOPAGO_ACCESS_TOKEN definido (valor nunca exibido), driver={$driver}.",
            $driver === 'fake' => 'Gateway FAKE forçado: nenhuma cobrança real acontece.',
            default => 'MERCADOPAGO_ACCESS_TOKEN ausente: o checkout responde dizendo que a cobrança não está configurada.',
        });

        $this->add('Cobrança', 'Ambiente', $production && $environment !== 'production' ? self::WARN : self::OK,
            $production && $environment !== 'production'
                ? "Aplicação em produção com MERCADOPAGO_ENVIRONMENT={$environment}."
                : $environment);

        $this->add('Cobrança', 'Segredo do webhook', match (true) {
            $hasWebhookSecret => self::OK,
            $hasToken => self::FAIL,
            default => self::WARN,
        }, $hasWebhookSecret
            ? 'MERCADOPAGO_WEBHOOK_SECRET definido (valor nunca exibido).'
            : 'Ausente: sem ele nenhuma notificação é aceita — e ativação de plano só acontece por webhook autenticado.');
    }

    private function checkStorageEncryption(): void
    {
        $path = (string) config('assinavelox.storage_encryption.receipt_path', 'hardening/storage-verify.json');

        try {
            if (! Storage::disk('local')->exists($path)) {
                $this->add('Armazenamento', 'Criptografia em repouso', self::WARN,
                    'Nunca verificada nesta instalação: rode `php artisan storage:verify`. '
                    .'Atenção: o Flysystem NÃO cifra nada por conta própria.');

                return;
            }

            /** @var array<string, mixed> $receipt */
            $receipt = (array) json_decode((string) Storage::disk('local')->get($path), true);
            $encryption = (array) ($receipt['encryption'] ?? []);
            $checkedAt = (string) ($receipt['checked_at'] ?? '');
            $verified = (bool) ($encryption['verified'] ?? false);
            $attested = (bool) ($encryption['operator_attested'] ?? false);

            $this->add('Armazenamento', 'Criptografia em repouso', match (true) {
                $verified => self::OK,
                $attested => self::WARN,
                default => self::FAIL,
            }, match (true) {
                $verified => sprintf('Verificada junto ao backend em %s (%s).', $checkedAt, (string) ($encryption['method'] ?? '?')),
                $attested => sprintf('ATESTADA pelo operador em %s — em disco local não há verificação possível pelo PHP.', $checkedAt),
                default => sprintf('Não verificada (última execução em %s): %s', $checkedAt, implode(' ', (array) ($encryption['problems'] ?? []))),
            });
        } catch (Throwable $exception) {
            $this->add('Armazenamento', 'Criptografia em repouso', self::WARN,
                'Recibo ilegível: '.Str::limit($exception->getMessage(), 160));
        }
    }

    private function checkAuditCheckpoints(): void
    {
        try {
            $last = DB::table('audit_checkpoints')->orderByDesc('sequence')->first();

            if ($last === null) {
                $this->add('Auditoria', 'Checkpoint da trilha', self::WARN,
                    'Nenhum gerado: rode `php artisan audit:checkpoint`. Sem checkpoint copiado para fora, '
                    .'uma alteração na trilha por quem administra o banco não deixa rastro.');

                return;
            }

            $age = CarbonImmutable::parse($last->created_at)->diffInDays(CarbonImmutable::now());

            $this->add('Auditoria', 'Checkpoint da trilha', $age > 8 ? self::WARN : self::OK, sprintf(
                'Último: #%d em %s (%d dia(s) atrás), %d evento(s). Elo atual %s.',
                $last->sequence,
                CarbonImmutable::parse($last->created_at)->toDateString(),
                $age,
                $last->event_count,
                Str::limit((string) $last->chain_sha256, 16, '…'),
            ));
        } catch (Throwable) {
            $this->add('Auditoria', 'Checkpoint da trilha', self::WARN, 'Tabela `audit_checkpoints` indisponível (migration pendente?).');
        }
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function render(array $report): void
    {
        $group = null;

        foreach ($report['checks'] as $check) {
            if ($check['group'] !== $group) {
                $group = (string) $check['group'];
                $this->newLine();
                $this->components->info($group);
            }

            $this->components->twoColumnDetail(
                $check['name'],
                match ($check['status']) {
                    self::OK => '<fg=green>ok</>',
                    self::WARN => '<fg=yellow>aviso</>',
                    default => '<fg=red>FALHA</>',
                },
            );
            $this->line('    '.$check['message']);
        }

        $this->newLine();
        $this->components->twoColumnDetail('Ambiente', (string) $report['environment']);
        $this->components->twoColumnDetail('Falhas / avisos', sprintf('%d / %d', $report['failures'], $report['warnings']));
        $this->newLine();

        $report['ok']
            ? $this->components->info('Instalação em condições.')
            : $this->components->error('Instalação com pendências. Veja docs/seguranca-operacional.md.');

        $this->components->warn('Nenhum valor de segredo é impresso por este comando — apenas presença, validade e o NOME da variável.');
    }
}
