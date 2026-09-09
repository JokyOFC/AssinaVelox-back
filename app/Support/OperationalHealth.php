<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Indicadores de saúde das partes que trabalham fora da requisição HTTP
 * (docs/seguranca-operacional.md §6).
 *
 * A pergunta que estes indicadores respondem não é "a aplicação está no ar" — para isso
 * basta `/up`. É a pergunta que realmente dói neste domínio: **alguma coisa parou no meio
 * do caminho e ninguém percebeu?** Um documento preso em `converting`, um envelope preso
 * em `finalizing`, um e-mail que nunca saiu da fila, um webhook de pagamento recebido e
 * nunca processado — cada um desses é um cliente esperando sem saber por quê.
 *
 * Todas as leituras usam `DB::table` de propósito: nada aqui pode passar pelo escopo global
 * de organização (não há organização corrente num worker ou num cron).
 *
 * Nenhum indicador expõe conteúdo de documento, nome, e-mail ou IP — apenas contagens.
 */
final class OperationalHealth
{
    public const OK = 'ok';

    public const DEGRADED = 'degradado';

    public const UNKNOWN = 'desconhecido';

    /**
     * @return array<string, mixed>
     */
    public static function report(): array
    {
        $indicators = [
            'queue' => self::queue(),
            'conversion' => self::conversion(),
            'signature' => self::signature(),
            'email_delivery' => self::emailDelivery(),
            'payment_reconciliation' => self::paymentReconciliation(),
        ];

        $degraded = array_keys(array_filter(
            $indicators,
            static fn (array $indicator): bool => $indicator['status'] === self::DEGRADED,
        ));

        return [
            'ok' => $degraded === [],
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'correlation_id' => Correlation::id(),
            'degraded' => $degraded,
            'indicators' => $indicators,
        ];
    }

    /**
     * Fila: trabalho acumulado e falhas recentes. Só o driver `database` é observável
     * daqui; com Redis a fonte é o Horizon.
     *
     * @return array<string, mixed>
     */
    private static function queue(): array
    {
        $connection = (string) config('queue.default');

        if ($connection !== 'database') {
            return self::indicator(self::UNKNOWN, [
                'connection' => $connection,
            ], "Fila `{$connection}`: contagens não são legíveis por SQL. Em produção use o Horizon.");
        }

        return self::guard(static function (): array {
            $pending = (int) DB::table('jobs')->count();
            $failed = (int) DB::table('failed_jobs')->count();
            $threshold = (int) config('assinavelox.observability.health.queue_backlog_warning', 100);

            $window = (int) config('assinavelox.observability.health.failed_jobs_window_hours', 24);
            $recentFailures = (int) DB::table('failed_jobs')
                ->where('failed_at', '>=', CarbonImmutable::now()->subHours($window))
                ->count();

            return self::indicator(
                $pending > $threshold || $recentFailures > 0 ? self::DEGRADED : self::OK,
                [
                    'connection' => 'database',
                    'pending' => $pending,
                    'failed_total' => $failed,
                    'failed_recent' => $recentFailures,
                    'backlog_threshold' => $threshold,
                ],
                $pending > $threshold
                    ? "{$pending} jobs esperando (limiar {$threshold})."
                    : ($recentFailures > 0 ? "{$recentFailures} job(s) falharam nas últimas {$window} h." : 'Sem acúmulo nem falhas recentes.'),
            );
        });
    }

    /**
     * Conversão documental: documentos presos em `converting` e conversões que falharam.
     *
     * @return array<string, mixed>
     */
    private static function conversion(): array
    {
        return self::guard(static function (): array {
            $minutes = (int) config('assinavelox.observability.health.conversion_stuck_minutes', 30);
            $cutoff = CarbonImmutable::now()->subMinutes($minutes);

            $stuck = (int) DB::table('documents')
                ->where('processing_status', 'converting')
                ->where('updated_at', '<', $cutoff)
                ->count();

            $failed = (int) DB::table('documents')
                ->whereIn('processing_status', ['failed'])
                ->where('updated_at', '>=', CarbonImmutable::now()->subDay())
                ->count();

            return self::indicator($stuck > 0 ? self::DEGRADED : self::OK, [
                'stuck' => $stuck,
                'stuck_after_minutes' => $minutes,
                'failed_last_24h' => $failed,
            ], $stuck > 0
                ? "{$stuck} documento(s) preso(s) em `converting` há mais de {$minutes} min: o worker da fila `conversions` está rodando?"
                : 'Nenhuma conversão travada.');
        });
    }

    /**
     * Finalização e assinatura: envelopes presos em `finalizing` e a proporção de
     * conclusões que saíram COM assinatura criptográfica da operadora.
     *
     * A proporção não é um alarme por si — sem certificado configurado, concluir como
     * aceite eletrônico com evidências é o comportamento correto e documentado. Ela
     * existe para flagrar o caso oposto: certificado configurado e, ainda assim,
     * envelopes concluindo sem assinatura.
     *
     * @return array<string, mixed>
     */
    private static function signature(): array
    {
        return self::guard(static function (): array {
            $minutes = (int) config('assinavelox.observability.health.finalization_stuck_minutes', 30);
            $cutoff = CarbonImmutable::now()->subMinutes($minutes);

            $stuck = (int) DB::table('envelopes')
                ->where('status', 'finalizing')
                ->where('updated_at', '<', $cutoff)
                ->count();

            $since = CarbonImmutable::now()->subDay();
            $recent = (int) DB::table('verification_records')->where('created_at', '>=', $since)->count();
            $signed = (int) DB::table('verification_records')
                ->where('created_at', '>=', $since)
                ->where('signature_status', 'company_a1')
                ->count();

            return self::indicator($stuck > 0 ? self::DEGRADED : self::OK, [
                'finalizing_stuck' => $stuck,
                'stuck_after_minutes' => $minutes,
                'completed_last_24h' => $recent,
                'signed_company_a1_last_24h' => $signed,
                'accepted_without_signature_last_24h' => $recent - $signed,
            ], $stuck > 0
                ? "{$stuck} envelope(s) preso(s) em `finalizing` há mais de {$minutes} min: a fila `finalization` está rodando?"
                : 'Nenhuma finalização travada.');
        });
    }

    /**
     * Entrega de e-mail. "Enfileirado" e "enviado" não são "entregue" — o indicador
     * separa os três, como o resto do sistema faz.
     *
     * @return array<string, mixed>
     */
    private static function emailDelivery(): array
    {
        return self::guard(static function (): array {
            $minutes = (int) config('assinavelox.observability.health.delivery_stuck_minutes', 60);
            $cutoff = CarbonImmutable::now()->subMinutes($minutes);
            $since = CarbonImmutable::now()->subDay();

            $stuck = (int) DB::table('delivery_attempts')
                ->where('status', 'queued')
                ->where('created_at', '<', $cutoff)
                ->count();

            $failed = (int) DB::table('delivery_attempts')
                ->whereIn('status', ['failed', 'bounced'])
                ->where('created_at', '>=', $since)
                ->count();

            $inconclusive = (int) DB::table('delivery_attempts')
                ->where('status', 'unknown')
                ->where('created_at', '>=', $since)
                ->count();

            return self::indicator($stuck > 0 || $failed > 0 ? self::DEGRADED : self::OK, [
                'queued_stuck' => $stuck,
                'stuck_after_minutes' => $minutes,
                'failed_or_bounced_last_24h' => $failed,
                'inconclusive_last_24h' => $inconclusive,
            ], match (true) {
                $stuck > 0 => "{$stuck} envio(s) parado(s) em `queued` há mais de {$minutes} min.",
                $failed > 0 => "{$failed} envio(s) falharam ou voltaram nas últimas 24 h.",
                default => 'Sem envios parados nem falhas recentes.',
            });
        });
    }

    /**
     * Conciliação de pagamento: recibos de webhook recebidos e nunca processados, e
     * pagamentos aprovados sem plano ativado.
     *
     * @return array<string, mixed>
     */
    private static function paymentReconciliation(): array
    {
        return self::guard(static function (): array {
            $minutes = (int) config('assinavelox.observability.health.webhook_stuck_minutes', 30);
            $cutoff = CarbonImmutable::now()->subMinutes($minutes);

            $stuck = (int) DB::table('payment_webhook_receipts')
                ->where('processing_status', 'received')
                ->where('received_at', '<', $cutoff)
                ->count();

            $failed = (int) DB::table('payment_webhook_receipts')
                ->where('processing_status', 'failed')
                ->where('received_at', '>=', CarbonImmutable::now()->subDay())
                ->count();

            $rejectedSignature = (int) DB::table('payment_webhook_receipts')
                ->where('signature_valid', false)
                ->where('received_at', '>=', CarbonImmutable::now()->subDay())
                ->count();

            $unapplied = (int) DB::table('payments')
                ->where('status', 'approved')
                ->whereNull('activated_at')
                ->where('updated_at', '<', $cutoff)
                ->count();

            $degraded = $stuck > 0 || $failed > 0 || $unapplied > 0;

            return self::indicator($degraded ? self::DEGRADED : self::OK, [
                'receipts_stuck' => $stuck,
                'stuck_after_minutes' => $minutes,
                'receipts_failed_last_24h' => $failed,
                'receipts_invalid_signature_last_24h' => $rejectedSignature,
                'approved_payments_not_applied' => $unapplied,
            ], match (true) {
                $unapplied > 0 => "{$unapplied} pagamento(s) aprovado(s) sem plano ativado: o cliente pagou e não recebeu.",
                $stuck > 0 => "{$stuck} recibo(s) de webhook parados em `received` há mais de {$minutes} min.",
                $failed > 0 => "{$failed} recibo(s) de webhook falharam nas últimas 24 h.",
                default => 'Conciliação em dia.',
            });
        });
    }

    /**
     * @param  array<string, mixed>  $metrics
     * @return array<string, mixed>
     */
    private static function indicator(string $status, array $metrics, string $message): array
    {
        return ['status' => $status, 'metrics' => $metrics, 'message' => $message];
    }

    /**
     * Um indicador que não pôde ser lido responde `desconhecido` — nunca `ok`. Painel que
     * mostra verde porque a consulta falhou é pior do que painel nenhum.
     *
     * @param  callable(): array<string, mixed>  $callback
     * @return array<string, mixed>
     */
    private static function guard(callable $callback): array
    {
        try {
            return $callback();
        } catch (Throwable $exception) {
            return self::indicator(self::UNKNOWN, [], 'Indisponível: '.mb_substr($exception->getMessage(), 0, 160));
        }
    }
}
