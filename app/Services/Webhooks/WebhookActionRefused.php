<?php

namespace App\Services\Webhooks;

use RuntimeException;

/**
 * Ação de gestão recusada por regra de negócio (limite de endpoints, endpoint pausado,
 * removido...). A mensagem é em português e pode ser mostrada ao usuário.
 */
final class WebhookActionRefused extends RuntimeException {}
