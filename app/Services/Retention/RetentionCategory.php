<?php

namespace App\Services\Retention;

/**
 * Categorias da política de retenção (Fase 2 §2.19 — docs/fase-2/retencao-e-preservacao.md §3).
 *
 * Cada categoria tem uma data de referência, um mínimo definido pela OPERADORA
 * ({@see RetentionConfig::minimumDays()}) e uma lista fechada do que é apagado e do que é
 * preservado. Os textos abaixo são os mesmos que a tela de Configurações › Retenção mostra:
 * uma única redação para o que a interface promete e o que o job faz.
 */
enum RetentionCategory: string
{
    case Completed = 'completed';
    case TerminalOther = 'terminal_other';
    case Draft = 'draft';
    case IdentityCapture = 'identity_capture';
    case Dossier = 'dossier';
    case AuditTrail = 'audit_trail';

    public function column(): string
    {
        return match ($this) {
            self::Completed => 'completed_days',
            self::TerminalOther => 'terminal_days',
            self::Draft => 'draft_days',
            self::IdentityCapture => 'identity_capture_days',
            self::Dossier => 'dossier_days',
            self::AuditTrail => 'audit_trail_days',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Completed => 'Documentos concluídos',
            self::TerminalOther => 'Documentos recusados, expirados ou cancelados',
            self::Draft => 'Rascunhos abandonados ou excluídos',
            self::IdentityCapture => 'Fotos da captura de identidade',
            self::Dossier => 'Dossiês gerados (ZIP)',
            self::AuditTrail => 'Trilha de auditoria sem documento',
        };
    }

    /** A partir de quando o prazo é contado. */
    public function reference(): string
    {
        return match ($this) {
            self::Completed => 'Contado a partir da conclusão do documento.',
            self::TerminalOther => 'Contado a partir da recusa, da expiração ou do cancelamento.',
            self::Draft => 'Contado a partir da última alteração do rascunho (ou da exclusão, se ele foi excluído).',
            self::IdentityCapture => 'Contado a partir do envio da foto pelo participante.',
            self::Dossier => 'Contado a partir da geração do arquivo.',
            self::AuditTrail => 'Contado a partir de cada evento registrado.',
        };
    }

    /** Envelopes inteiros (as três primeiras) ou artefatos soltos. */
    public function purgesEnvelopes(): bool
    {
        return in_array($this, [self::Completed, self::TerminalOther, self::Draft], true);
    }

    /**
     * O que é apagado — lista fechada, exibida na tela.
     *
     * @return list<string>
     */
    public function deletes(): array
    {
        return match ($this) {
            self::Completed, self::TerminalOther => [
                'Todos os arquivos do documento no armazenamento: original, versões convertidas, versão enviada, relatório de evidências e arquivo final assinado.',
                'Imagens de assinatura e rubrica, e as fotos da captura de identidade.',
                'Dossiês gerados, carimbos do tempo e demais derivados do documento.',
                'Participantes (nomes, e-mails, telefones), convites, sessões, códigos, aceites, valores dos campos e tentativas de entrega.',
                'O registro de verificação pública completo (título, organização, participantes e linha do tempo).',
            ],
            self::Draft => [
                'O rascunho inteiro: arquivos enviados, versões, participantes e campos.',
                'Rascunhos excluídos manualmente continuam no armazenamento até este prazo; depois saem de vez.',
            ],
            self::IdentityCapture => [
                'O arquivo de cada foto (rosto e documento).',
            ],
            self::Dossier => [
                'O arquivo ZIP do dossiê e o registro do pedido de exportação.',
            ],
            self::AuditTrail => [
                'Eventos da trilha que não estão mais ligados a um documento existente (documentos já excluídos e eventos gerais da conta), com IP e navegador.',
            ],
        };
    }

    /**
     * O que é preservado — lista fechada, exibida na tela.
     *
     * @return list<string>
     */
    public function preserves(): array
    {
        return match ($this) {
            self::Completed, self::TerminalOther => [
                'Um recibo de exclusão sem dados pessoais: categoria, data e contagens.',
                'Para documentos que já tinham código de verificação: o aviso público de remoção, conforme a regra da operadora (veja abaixo).',
                'A trilha de auditoria, pelo prazo da categoria "Trilha de auditoria".',
            ],
            self::Draft => [
                'Um recibo de exclusão sem dados pessoais.',
            ],
            self::IdentityCapture => [
                'O resumo SHA-256 e o registro de que a foto existiu e foi excluída (a evidência do aceite continua coerente).',
            ],
            self::Dossier => [
                'Nada do arquivo; o documento em si segue a sua própria categoria.',
            ],
            self::AuditTrail => [
                'Eventos de documentos que ainda existem nunca saem por esta categoria.',
                'Os resumos encadeados de auditoria da plataforma (checkpoints).',
            ],
        };
    }

    /**
     * Mínimo de partida quando a operadora não configura (dias). Valores conservadores e
     * MARCADOS PARA REVISÃO JURÍDICA (docs/fase-2/retencao-e-preservacao.md §4).
     */
    public function defaultMinimumDays(): int
    {
        return match ($this) {
            self::Completed => 1825,
            self::TerminalOther => 180,
            self::Draft => 30,
            self::IdentityCapture => 7,
            self::Dossier => 1,
            self::AuditTrail => 1825,
        };
    }

    /**
     * @return list<self>
     */
    public static function envelopeCategories(): array
    {
        return [self::Completed, self::TerminalOther, self::Draft];
    }
}
