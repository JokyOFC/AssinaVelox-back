<?php

namespace App\Integrations\Exceptions;

use RuntimeException;

/**
 * Base das falhas dos adaptadores de App\Integrations (configuração ausente,
 * tipo não suportado). Falhas do próprio documento não são exceções: viram
 * ConversionResult blocked/failed.
 */
class IntegrationException extends RuntimeException {}
