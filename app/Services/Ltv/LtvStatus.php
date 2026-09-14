<?php

namespace App\Services\Ltv;

/**
 * `verification_records.ltv_status` — estado TÉCNICO interno do arquivo final (P3-LTV).
 *
 * Não é o perfil anunciado. Enquanto `pades_ltv_advertise` estiver desligada, a interface e a
 * verificação pública continuam dizendo PAdES-B-B (roadmap T2); estes rótulos são para a
 * operação e para a página autenticada de evidências, sempre com o aviso de "não anunciado".
 */
enum LtvStatus: string
{
    case NotApplicable = 'not_applicable';
    case BT = 'b_t';
    case BLt = 'b_lt';
    case BLta = 'b_lta';

    public const NOT_ANNOUNCED_NOTICE = 'Estado técnico interno. O perfil anunciado continua PAdES-B-B até a validação '
        .'externa independente; o carimbo é da TSA da operadora e não é carimbo ICP-Brasil.';

    /**
     * Nível devolvido pelo pdftool (`B-B`, `B-T`, `B-LT`, `B-LTA`, ou nulo) → estado.
     */
    public static function fromLevel(?string $level): self
    {
        return match ($level) {
            'B-T' => self::BT,
            'B-LT' => self::BLt,
            'B-LTA' => self::BLta,
            default => self::NotApplicable,
        };
    }

    public function level(): ?string
    {
        return match ($this) {
            self::NotApplicable => null,
            self::BT => 'B-T',
            self::BLt => 'B-LT',
            self::BLta => 'B-LTA',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::NotApplicable => 'Sem material de longo prazo',
            self::BT => 'Carimbo do tempo da operadora na assinatura',
            self::BLt => 'Carimbo da operadora e informações de revogação embutidas',
            self::BLta => 'Carimbo da operadora, revogação embutida e carimbo de arquivamento',
        };
    }

    public function isArchival(): bool
    {
        return $this === self::BLta;
    }
}
