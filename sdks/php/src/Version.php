<?php

declare(strict_types=1);

namespace AssinaVelox\Sdk;

/**
 * Versões do SDK e da API. Gerado por tools/sdkgen a partir de sdks/openapi/v1.json — não edite; rode python tools/sdkgen/sdkgen.py generate.
 */
final class Version
{
    public const SDK = '1.0.0';

    public const API = '1.0.0';

    public const API_MAJOR = 1;

    /** Impressão digital (SHA-256) da especificação de onde este SDK foi gerado. */
    public const SPEC_SHA256 = 'a569628f9193f3d9eb67183b32e4ad7bfc854f97c6ca82dac1485a0dc1d7921b';
}
