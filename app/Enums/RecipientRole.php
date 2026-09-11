<?php

namespace App\Enums;

/**
 * Papel de domínio do participante (roadmap §2.4, RECONCILIACAO §1).
 *
 * Não confundir com `recipients.role_label` — o rótulo livre digitado no wizard
 * ("Locatária", "Fiador"). Este enum decide o EFEITO na máquina de estados:
 *
 * | papel      | aceite         | campo de assinatura | conta para conclusão | vez no sequencial |
 * |------------|----------------|---------------------|----------------------|-------------------|
 * | `signer`   | `sign`         | obrigatório (≥ 1)   | sim                  | sim               |
 * | `witness`  | `witness`      | obrigatório (≥ 1)   | sim                  | sim               |
 * | `approver` | `approve`      | proibido            | sim                  | sim               |
 * | `viewer`   | nenhum         | nenhum campo        | não                  | não (order 0)     |
 *
 * Semântica (arquitetura §2): nenhum desses papéis produz "assinatura digital". Testemunha e
 * signatário registram **aceite eletrônico** com representação visual; o aprovador registra
 * **aprovação eletrônica** sem representação visual; o visualizador só recebe cópia.
 */
enum RecipientRole: string
{
    case Signer = 'signer';
    case Witness = 'witness';
    case Approver = 'approver';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::Signer => 'Signatário',
            self::Witness => 'Testemunha',
            self::Approver => 'Aprovador',
            self::Viewer => 'Visualizador',
        };
    }

    /**
     * Registra aceite/aprovação, tem vez na ordem e conta para a conclusão.
     */
    public function participates(): bool
    {
        return $this !== self::Viewer;
    }

    /**
     * Precisa de pelo menos um campo de assinatura obrigatório para o envelope ficar `ready`.
     */
    public function requiresSignatureField(): bool
    {
        return in_array($this, [self::Signer, self::Witness], true);
    }

    /**
     * Pode receber campos de assinatura/rubrica (representação visual).
     */
    public function allowsVisualSignature(): bool
    {
        return $this->requiresSignatureField();
    }

    /**
     * Pode receber algum campo (texto, data, caixa…).
     */
    public function allowsFields(): bool
    {
        return $this !== self::Viewer;
    }

    /**
     * Ação gravada no aceite. `null` para o visualizador, que não registra aceite.
     */
    public function acceptanceAction(): ?AcceptanceAction
    {
        return match ($this) {
            self::Signer => AcceptanceAction::Sign,
            self::Witness => AcceptanceAction::Witness,
            self::Approver => AcceptanceAction::Approve,
            self::Viewer => null,
        };
    }

    /**
     * Papéis que participam da coleta (ordem, pendência, conclusão).
     *
     * @return list<string>
     */
    public static function participatingValues(): array
    {
        return [self::Signer->value, self::Witness->value, self::Approver->value];
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
