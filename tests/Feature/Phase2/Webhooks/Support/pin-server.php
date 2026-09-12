<?php

/*
 * Roteador do servidor embutido do PHP (`php -S 127.0.0.1:<porta> pin-server.php`) usado pelo
 * teste de integração do pino de IP (PinnedConnectionTest). Registra cada requisição recebida
 * numa linha JSON do arquivo indicado em PIN_SERVER_LOG. Não é carregado pela suíte.
 */

$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$log = getenv('PIN_SERVER_LOG');

if (is_string($log) && $log !== '') {
    file_put_contents($log, json_encode([
        'method' => $_SERVER['REQUEST_METHOD'] ?? null,
        'path' => $path,
        'host' => $_SERVER['HTTP_HOST'] ?? null,
        'delivery' => $_SERVER['HTTP_X_ASSINAVELOX_DELIVERY_ID'] ?? null,
        'timestamp' => $_SERVER['HTTP_X_ASSINAVELOX_TIMESTAMP'] ?? null,
        'signature' => $_SERVER['HTTP_X_ASSINAVELOX_SIGNATURE'] ?? null,
        'body' => file_get_contents('php://input'),
    ])."\n", FILE_APPEND | LOCK_EX);
}

switch ($path) {
    case '/redirect':
        header('Location: http://127.0.0.1:'.($_SERVER['SERVER_PORT'] ?? '80').'/internal', true, 302);
        echo 'moved';
        break;
    case '/internal':
        echo 'INTERNAL';
        break;
    case '/slow':
        sleep(3);
        echo 'late';
        break;
    default:
        header('Content-Type: text/plain');
        echo 'recebido';
}

return true;
