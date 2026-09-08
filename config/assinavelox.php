<?php

/*
|--------------------------------------------------------------------------
| AssinaVelox — configuração do domínio
|--------------------------------------------------------------------------
|
| Todas as chaves são lidas do .env. Aqui não há valores reais de segredos:
| certificados, chaves de API e senhas são apenas REFERÊNCIAS (nome da
| variável de ambiente ou caminho de arquivo). Consulte docs/configuracao.md.
|
*/

return [

    // Versão dos Termos de uso aceitos no cadastro e no aceite eletrônico.
    'terms_version' => env('ASSINAVELOX_TERMS_VERSION', '2026-09'),

    // Prazo padrão (em dias) para assinatura quando a organização não define outro.
    'default_expiration_days' => (int) env('ASSINAVELOX_DEFAULT_EXPIRATION_DAYS', 30),

    // Limites do prazo configurável por organização/envelope.
    'expiration_days' => [
        'min' => 1,
        'max' => 90,
    ],

    'otp' => [
        // Validade do código enviado por e-mail (minutos).
        'ttl_minutes' => (int) env('ASSINAVELOX_OTP_TTL_MINUTES', 10),
        // Tentativas de verificação por desafio antes de invalidar o código.
        'max_attempts' => (int) env('ASSINAVELOX_OTP_MAX_ATTEMPTS', 5),
        // Reenvios permitidos por link de convite por hora.
        'resend_limit' => (int) env('ASSINAVELOX_OTP_RESEND_LIMIT', 5),
    ],

    'signing_session' => [
        // Duração da sessão do signatário após confirmar o código (minutos).
        'ttl_minutes' => (int) env('ASSINAVELOX_SIGNING_SESSION_TTL_MINUTES', 30),
        // Validade do token de autorização final do aceite (minutos).
        'authorization_ttl_minutes' => (int) env('ASSINAVELOX_AUTHORIZATION_TTL_MINUTES', 10),
    ],

    // Reenvio manual de convite: intervalo mínimo por destinatário (minutos) e máximo padrão.
    'resend' => [
        'throttle_minutes' => (int) env('ASSINAVELOX_RESEND_THROTTLE_MINUTES', 10),
        'max_per_recipient' => (int) env('ASSINAVELOX_MAX_RESENDS', 5),
    ],

    // Unidade de consumo do plano: envelope_sent (Fase 1). Reservado para outras unidades.
    'plan_consumption_unit' => env('ASSINAVELOX_PLAN_CONSUMPTION_UNIT', 'envelope_sent'),

    // Exibição de IP na página de evidências: masked | full | none.
    'evidence_show_ip' => env('ASSINAVELOX_EVIDENCE_SHOW_IP', 'masked'),

    // Upload: tamanho máximo (MB) e MIME types aceitos na Fase 1.
    'upload' => [
        'max_mb' => (int) env('ASSINAVELOX_MAX_UPLOAD_MB', 25),
        'accepted_mimes' => [
            'application/pdf',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'image/png',
            'image/jpeg',
        ],
    ],

    // Convites de membros: validade em dias e tamanho do token (bytes aleatórios).
    'invitations' => [
        'expires_in_days' => (int) env('ASSINAVELOX_INVITATION_EXPIRES_DAYS', 7),
        'token_bytes' => 32,
    ],

    // Contadores da sidebar (envelopes pendentes, notificações): TTL e store de cache.
    'counts_cache' => [
        'ttl_seconds' => (int) env('ASSINAVELOX_COUNTS_CACHE_TTL', 60),
        // null = store padrão (CACHE_STORE).
        'store' => env('ASSINAVELOX_COUNTS_CACHE_STORE'),
    ],

    // Exclusão de organização: dias entre a solicitação e a exclusão efetiva (job futuro).
    'organization_deletion_grace_days' => (int) env('ASSINAVELOX_ORG_DELETION_GRACE_DAYS', 30),

    /*
    | Proxies confiáveis (X-Forwarded-*). Use "*" atrás de balanceador próprio,
    | ou lista separada por vírgula de IPs/CIDRs. Vazio = nenhum proxy confiável.
    | O IP registrado nos aceites depende desta configuração.
    */
    'trusted_proxies' => env('TRUSTED_PROXIES'),

    /*
    | Cabeçalhos de segurança / CSP (middleware SecurityHeaders).
    | report_only=true emite Content-Security-Policy-Report-Only (útil para validar
    | em produção antes de bloquear).
    */
    'security_headers' => [
        'csp_enabled' => (bool) env('ASSINAVELOX_CSP_ENABLED', true),
        'csp_report_only' => (bool) env('ASSINAVELOX_CSP_REPORT_ONLY', false),
        // Origens adicionais permitidas (separadas por vírgula), ex.: CDN de assets.
        'csp_extra_sources' => env('ASSINAVELOX_CSP_EXTRA_SOURCES'),
    ],

    // Filas usadas pelos jobs (Horizon em produção; driver database em dev).
    'queues' => [
        'default' => 'default',
        'conversions' => 'conversions',
        'notifications' => 'notifications',
        'finalization' => 'finalization',
        'billing' => 'billing',
    ],

    /*
    | Ferramentas externas — apenas caminhos/binários (placeholders).
    | pdftool: Python com pypdf/reportlab/pyHanko (tools/pdftool).
    | LibreOffice: conversão DOCX→PDF em processo isolado; ausente em dev = fake.
    */
    'pdftool_python' => env('PDFTOOL_PYTHON'),
    'libreoffice_bin' => env('LIBREOFFICE_BIN'),

    /*
    | Certificado A1 da empresa operadora (PAdES B-B). Somente REFERÊNCIAS:
    | caminho do arquivo PFX e NOME da variável que contém a senha — a senha em si
    | é lida apenas pelo processo de assinatura, nunca gravada em banco/log.
    */
    'company_certificate' => [
        'enabled' => (bool) env('COMPANY_CERT_ENABLED', false),
        'environment' => env('COMPANY_CERT_ENVIRONMENT', 'test'), // test | production
        'pfx_path' => env('COMPANY_CERT_PFX_PATH'),
        'password_env' => env('COMPANY_CERT_PASSWORD_ENV', 'COMPANY_CERT_PASSWORD'),
        'name' => env('COMPANY_CERT_NAME', 'Certificado da operadora'),
    ],

    /*
    | Mercado Pago (Checkout Pro). Placeholders vazios em dev; nunca commitar valores.
    | environment: sandbox | production.
    */
    'mercadopago' => [
        'environment' => env('MERCADOPAGO_ENVIRONMENT', 'sandbox'),
        'access_token' => env('MERCADOPAGO_ACCESS_TOKEN'),
        'public_key' => env('MERCADOPAGO_PUBLIC_KEY'),
        'webhook_secret' => env('MERCADOPAGO_WEBHOOK_SECRET'),
        'notification_url' => env('MERCADOPAGO_NOTIFICATION_URL'),
    ],

    // Suporte/ajuda exibidos na UI.
    'help_url' => env('ASSINAVELOX_HELP_URL', 'https://ajuda.assinavelox.com.br'),
    'support_email' => env('ASSINAVELOX_SUPPORT_EMAIL', 'suporte@assinavelox.com.br'),
];
