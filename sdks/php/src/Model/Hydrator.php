<?php

declare(strict_types=1);

namespace AssinaVelox\Sdk\Model;

/**
 * Apoio dos modelos (uso interno). Gerado por tools/sdkgen a partir de sdks/openapi/v1.json — não edite; rode python tools/sdkgen/sdkgen.py generate.
 */
final class Hydrator
{
    /**
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @param  array<mixed>  $items
     * @return list<T>
     */
    public static function listOf(string $class, array $items): array
    {
        $result = [];

        foreach ($items as $item) {
            if (is_array($item)) {
                $result[] = $class::fromArray($item);
            }
        }

        return $result;
    }
}
