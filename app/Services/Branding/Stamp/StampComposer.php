<?php

namespace App\Services\Branding\Stamp;

use App\Models\SigningField;
use App\Services\Pdf\Dto\ComposePlan;

/**
 * Leva o carimbo visual ao `pdftool compose` pelo **caminho de imagem que já existe**.
 *
 * O pdftool conhece dois tipos de imagem (`signature`, `initials`), e os dois fazem a
 * mesma coisa: encaixar a imagem no retângulo, centralizada, sem distorcer, com o alfa
 * virando /SMask. O carimbo usa esse caminho (`signature`) em vez de exigir um tipo novo no
 * pdftool — por isso não há mudança no Python nem no DTO `ComposePlan`.
 *
 * Contrato para `ConsolidationPlanner::addField()` (fora desta área), antes do ramo de
 * texto: `if ($field->type === FieldType::Stamp) { ... StampComposer::add($plan, $field, $local) ... }`
 * com `$local` = cópia de `signing_field_values.image_path` no diretório temporário (o
 * mesmo `copyImage()` da assinatura).
 */
final class StampComposer
{
    /** Tipo de imagem do pdftool usado para o carimbo. */
    public const PDFTOOL_IMAGE_TYPE = 'signature';

    public static function add(ComposePlan $plan, SigningField $field, string $localImagePath): ComposePlan
    {
        return self::addAt(
            $plan,
            $field->ulid,
            (int) $field->page,
            (float) $field->x,
            (float) $field->y,
            (float) $field->width,
            (float) $field->height,
            $localImagePath,
            $field->box_type->value,
        );
    }

    public static function addAt(
        ComposePlan $plan,
        string $id,
        int $page,
        float $x,
        float $y,
        float $width,
        float $height,
        string $localImagePath,
        ?string $box = null,
    ): ComposePlan {
        return $plan->addImage($id, $page, self::PDFTOOL_IMAGE_TYPE, $x, $y, $width, $height, $localImagePath, array_filter(
            ['box' => $box],
            static fn (?string $value): bool => $value !== null,
        ));
    }
}
