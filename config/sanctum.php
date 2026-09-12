<?php

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;

/*
|--------------------------------------------------------------------------
| Sanctum — só tokens da API v1 (Fase 2 §2.15, docs/fase-2/api-v1.md)
|--------------------------------------------------------------------------
|
| O front é Inertia na mesma origem (sessão + CSRF do Laravel): o modo SPA "stateful" do
| Sanctum não é usado. Por isso `stateful` e `guard` ficam VAZIOS — nenhuma requisição de API
| é autenticada pela sessão web (sem isso o Sanctum aceitaria o cookie de sessão e daria ao
| navegador um token "transiente" com todas as abilities).
|
| A autenticação da API é feita por App\Http\Middleware\ApiAuthenticate sobre o modelo
| App\Models\ApiToken (extensão do PersonalAccessToken: hash SHA-256, texto exibido uma vez).
|
*/

return [

    // Nenhum domínio é "stateful": a API v1 só aceita `Authorization: Bearer`.
    'stateful' => [],

    // Nenhum guard de sessão é consultado antes do token.
    'guard' => [],

    // A expiração é por token (`expires_at`, opcional, definida na criação).
    'expiration' => null,

    // Prefixo do segredo, para ferramentas de varredura de segredos reconhecerem o token.
    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', 'avk_'),

    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => ValidateCsrfToken::class,
    ],

];
