<?php

namespace App\Services\Retention;

/**
 * Configuração da retenção (Fase 2 §2.19). Padrões no código; tudo pode ser sobrescrito em
 * `config('assinavelox.retention.*')` — bloco opcional, ainda não declarado em
 * config/assinavelox.php (arquivo fora da área K-RET; contrato em
 * docs/fase-2/retencao-e-preservacao.md §8), no mesmo padrão de `PublicFormsConfig`.
 */
final class RetentionConfig
{
    /** Frase que a pessoa digita para reduzir prazos ou ativar a exclusão automática. */
    public const REDUCTION_CONFIRMATION = 'REDUZIR PRAZOS';

    public const MAX_DAYS = 36500;

    /**
     * Mínimo legal da categoria, definido pela OPERADORA. A organização não fica abaixo dele.
     */
    public static function minimumDays(RetentionCategory $category): int
    {
        $value = config('assinavelox.retention.minimum_days.'.$category->value);

        return is_numeric($value) ? max(1, (int) $value) : $category->defaultMinimumDays();
    }

    /**
     * @return array<string, int>
     */
    public static function minimums(): array
    {
        $minimums = [];

        foreach (RetentionCategory::cases() as $category) {
            $minimums[$category->value] = self::minimumDays($category);
        }

        return $minimums;
    }

    public static function verificationMode(): VerificationAfterPurge
    {
        $value = config('assinavelox.retention.verification_after_purge');

        return is_string($value)
            ? (VerificationAfterPurge::tryFrom($value) ?? VerificationAfterPurge::RECOMMENDED)
            : VerificationAfterPurge::RECOMMENDED;
    }

    /**
     * A trilha de auditoria é append-only (roadmap T7; docs/seguranca-operacional.md §3). Apagar
     * eventos exige DELETE em `audit_events`, que a recomendação de GRANT retira. Por isso a
     * categoria "Trilha de auditoria" só é aplicada quando a operadora liga isto de propósito.
     */
    public static function auditTrailDeletionAllowed(): bool
    {
        return config('assinavelox.retention.allow_audit_trail_deletion', false) === true;
    }

    /** Envelopes por categoria e organização em cada execução. */
    public static function batchSize(): int
    {
        return max(1, (int) config('assinavelox.retention.batch_size', 100));
    }

    /** Janela de rotação dos backups declarada na Política de Privacidade (dias). */
    public static function backupWindowDays(): int
    {
        return max(0, (int) config('assinavelox.retention.backup_window_days', 35));
    }

    /**
     * Tabelas de artefatos DERIVADOS do envelope criadas por outros itens (dossiês e carimbos
     * de §2.13, assinaturas de participante de §2.12). A exclusão do envelope apaga os
     * arquivos (`path_columns`) e as linhas (`envelope_id`) de cada uma que EXISTIR; a
     * categoria "Dossiês" usa as marcadas com `category = dossier` e a coluna de data.
     * Nada é presumido: tabela ou coluna ausente é simplesmente ignorada.
     *
     * @return list<array{table: string, path_columns: list<string>, date_column: string|null, category: string|null}>
     */
    public static function derivedArtifacts(): array
    {
        $configured = config('assinavelox.retention.derived_artifacts');

        // Padrão alinhado às tabelas da onda C (K-TSA: 2026_09_11_130102/130103; K-A1:
        // 2026_09_11_130001/130002). Ordem: quem referencia antes de quem é referenciado.
        $entries = is_array($configured) ? $configured : [
            ['table' => 'timestamp_tokens', 'path_columns' => ['storage_path'], 'date_column' => null, 'category' => null],
            ['table' => 'dossier_exports', 'path_columns' => ['storage_path'], 'date_column' => 'created_at', 'category' => RetentionCategory::Dossier->value],
            ['table' => 'participant_signatures', 'path_columns' => [], 'date_column' => null, 'category' => null],
            ['table' => 'participant_signature_requests', 'path_columns' => [], 'date_column' => null, 'category' => null],
        ];

        $normalized = [];

        foreach ($entries as $entry) {
            if (! is_array($entry) || ! is_string($entry['table'] ?? null) || $entry['table'] === '') {
                continue;
            }

            $normalized[] = [
                'table' => $entry['table'],
                'path_columns' => array_values(array_filter((array) ($entry['path_columns'] ?? []), 'is_string')),
                'date_column' => is_string($entry['date_column'] ?? null) ? $entry['date_column'] : null,
                'category' => is_string($entry['category'] ?? null) ? $entry['category'] : null,
            ];
        }

        return $normalized;
    }

    public static function pathPrefix(): string
    {
        return trim((string) config('assinavelox.upload.path_prefix', 'orgs'), '/');
    }
}
