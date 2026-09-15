<?php

declare(strict_types=1);

namespace AssinaVelox\Sdk\Exception;

/**
 * O pedido foi recusado pelo próprio SDK, antes de sair (ex.: Idempotency-Key inválida).
 */
class InvalidRequestException extends AssinaVeloxException {}
