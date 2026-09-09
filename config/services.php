<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    | Mercado Pago (Checkout Pro) — a configuração canônica fica em
    | config/assinavelox.php, chave `mercadopago`, junto do restante das regras de
    | negócio (ambiente, tolerância do webhook, timeouts, descrição na fatura).
    | Repetir as credenciais aqui só criaria duas fontes de verdade, então este
    | bloco existe apenas como ponteiro: leia `config('assinavelox.mercadopago')`
    | (ou, no código, App\Services\Billing\BillingSettings).
    |
    | Variáveis: MERCADOPAGO_DRIVER, MERCADOPAGO_ENVIRONMENT, MERCADOPAGO_ACCESS_TOKEN,
    | MERCADOPAGO_PUBLIC_KEY, MERCADOPAGO_WEBHOOK_SECRET, MERCADOPAGO_NOTIFICATION_URL.
    | Nunca gravar nenhuma delas em log, exceção, argumento de processo ou fila.
    */

];
