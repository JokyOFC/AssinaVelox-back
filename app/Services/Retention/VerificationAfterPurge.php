<?php

namespace App\Services\Retention;

/**
 * O que a página pública de verificação responde para o código de um envelope expurgado
 * (decisão pendente viabilidade §4.5 item 30 — docs/fase-2/retencao-e-preservacao.md §6).
 *
 * Decisão do PROPRIETÁRIO, configurável em `assinavelox.retention.verification_after_purge`.
 * Padrão recomendado: {@see self::NoticeWithFinalHash}.
 */
enum VerificationAfterPurge: string
{
    /** Resposta idêntica à de um código inexistente. Nada é guardado para a página. */
    case Hidden = 'hidden';

    /** "Removido por política de retenção em {data}" — sem título, organização, participantes nem resumos. */
    case Notice = 'notice';

    /** O aviso acima + só o(s) resumo(s) SHA-256 do(s) arquivo(s) final(is), sem nomes. */
    case NoticeWithFinalHash = 'notice_with_final_hash';

    public const RECOMMENDED = self::NoticeWithFinalHash;

    public function keepsCode(): bool
    {
        return $this !== self::Hidden;
    }

    public function keepsHashes(): bool
    {
        return $this === self::NoticeWithFinalHash;
    }

    public function label(): string
    {
        return match ($this) {
            self::Hidden => 'O código deixa de existir',
            self::Notice => 'Aviso de remoção, sem resumos',
            self::NoticeWithFinalHash => 'Aviso de remoção com o resumo do arquivo final',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Hidden => 'Quem consultar o código de um documento excluído recebe a mesma resposta de um código inexistente.',
            self::Notice => 'Quem consultar o código vê apenas que o registro foi removido por política de retenção e em que data — sem título, organização, participantes nem resumos.',
            self::NoticeWithFinalHash => 'Quem consultar o código vê que o registro foi removido por política de retenção e em que data, e ainda pode conferir se uma cópia que tenha guardado é o arquivo final emitido (só o resumo SHA-256; sem título, organização nem participantes).',
        };
    }
}
