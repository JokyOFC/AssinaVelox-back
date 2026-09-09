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

    /*
    | Código de uso único enviado por e-mail ao signatário (docs/fluxo-do-signatario.md).
    |
    | O código NUNCA é gravado em claro: auth_challenges.code_hash guarda um
    | HMAC-SHA256 sobre "{ulid}|{codigo}" com segredo derivado da APP_KEY
    | (App\Services\Signing\Challenges). Também não aparece em log, exceção ou
    | evento de auditoria.
    */
    'otp' => [
        // Validade do código enviado por e-mail (minutos).
        'ttl_minutes' => (int) env('ASSINAVELOX_OTP_TTL_MINUTES', 10),
        // Tentativas de verificação por desafio antes de invalidar o código.
        'max_attempts' => (int) env('ASSINAVELOX_OTP_MAX_ATTEMPTS', 5),
        // Reenvios permitidos por link de convite por hora.
        'resend_limit' => (int) env('ASSINAVELOX_OTP_RESEND_LIMIT', 5),
        // Intervalo mínimo entre dois envios do código, em segundos.
        'resend_interval_seconds' => (int) env('ASSINAVELOX_OTP_RESEND_INTERVAL_SECONDS', 60),
        // Envios por endereço IP por hora (freio adicional ao limite por link).
        'ip_hourly_limit' => (int) env('ASSINAVELOX_OTP_IP_HOURLY_LIMIT', 30),
        // Dígitos do código (random_int, sem zero à esquerda perdido).
        'code_length' => 6,
    ],

    'signing_session' => [
        // Duração da sessão do signatário após confirmar o código (minutos).
        'ttl_minutes' => (int) env('ASSINAVELOX_SIGNING_SESSION_TTL_MINUTES', 30),
        // Validade do token de autorização final do aceite (minutos).
        'authorization_ttl_minutes' => (int) env('ASSINAVELOX_AUTHORIZATION_TTL_MINUTES', 10),
        /*
        | Janela de download do signatário (minutos), emitida no instante do aceite e
        | enquanto houver sessão viva. Fora dela, `sign.download` responde 404: possuir a
        | URL do convite não é o mesmo que ter confirmado o código por e-mail.
        */
        'download_grant_minutes' => (int) env('ASSINAVELOX_DOWNLOAD_GRANT_MINUTES', 30),

        /*
        | Representação visual da assinatura (desenho, imagem enviada). Toda imagem é
        | REPROCESSADA com GD e reescrita como PNG sem metadados; SVG e qualquer outro
        | formato são recusados. Os limites de entrada são conferidos ANTES de decodificar.
        */
        'signature_image' => [
            // Tamanho máximo dos bytes já decodificados do base64 recebido (KB).
            'max_decoded_kb' => (int) env('ASSINAVELOX_SIGNATURE_MAX_DECODED_KB', 3072),
            // Pixels máximos da imagem recebida (lidos do cabeçalho).
            'max_source_pixels' => (int) env('ASSINAVELOX_SIGNATURE_MAX_SOURCE_PIXELS', 8_000_000),
            // Caixa de saída do PNG normalizado (arquitetura §4.5).
            'output_max_width' => 1200,
            'output_max_height' => 400,
        ],

        // Texto livre de campo `text` (ROUTES §2.18) e motivo da recusa.
        'max_text_field_length' => 500,
    ],

    // Reenvio manual de convite: intervalo mínimo por destinatário (minutos) e máximo padrão.
    'resend' => [
        'throttle_minutes' => (int) env('ASSINAVELOX_RESEND_THROTTLE_MINUTES', 10),
        'max_per_recipient' => (int) env('ASSINAVELOX_MAX_RESENDS', 5),
        // "Lembrar todos os pendentes" da tela Assinaturas: 1× por hora por organização.
        'bulk_throttle_minutes' => (int) env('ASSINAVELOX_BULK_RESEND_THROTTLE_MINUTES', 60),
    ],

    /*
    | Expiração do envelope (RECONCILIACAO Q22). O prazo termina às 23:59:59 do fuso da
    | ORGANIZAÇÃO; o comando `envelopes:expire` roda a cada 15 min e a expiração também é
    | revalidada a cada acesso do signatário (App\Services\Envelopes\Sending\ExpireEnvelopes).
    */
    'expiration' => [
        // Antecedência do aviso "seu prazo está acabando", em horas.
        'warning_hours' => (int) env('ASSINAVELOX_EXPIRATION_WARNING_HOURS', 48),
        // Envelopes processados por execução dos comandos agendados.
        'batch_size' => (int) env('ASSINAVELOX_EXPIRATION_BATCH_SIZE', 200),
    ],

    /*
    | Provedor de e-mail transacional (App\Integrations\Contracts\EmailProvider).
    |
    |  - `laravel`: usa o mailer configurado em config/mail.php. Em produção o serviço
    |    próprio do proprietário entra por SMTP (ou HTTP) apenas por configuração — nenhum
    |    endpoint é inventado no código.
    |  - `log`: fake de DESENVOLVIMENTO. Nada é transmitido; cada mensagem vira uma linha de
    |    log marcada [FAKE] e o recibo é `unknown` (nunca `sent`).
    |
    | `inconclusive_mailers` lista os mailers do Laravel que não transmitem nada de verdade:
    | com eles o recibo também é `unknown`, porque "escrevi no log" não é "enviei".
    */
    'email' => [
        'provider' => env('ASSINAVELOX_EMAIL_PROVIDER', 'laravel'),
        // null = o mailer padrão de config/mail.php.
        'mailer' => env('ASSINAVELOX_EMAIL_MAILER'),
        'inconclusive_mailers' => ['log'],
        // Canal de log do LogEmailProvider (null = canal padrão).
        'log_channel' => env('ASSINAVELOX_EMAIL_LOG_CHANNEL'),
    ],

    // Unidade de consumo do plano: envelope_sent (Fase 1). Reservado para outras unidades.
    'plan_consumption_unit' => env('ASSINAVELOX_PLAN_CONSUMPTION_UNIT', 'envelope_sent'),

    // Exibição de IP na página de evidências: masked | full | none.
    'evidence_show_ip' => env('ASSINAVELOX_EVIDENCE_SHOW_IP', 'masked'),

    /*
    | Upload de documentos (docs/preparacao-documental.md).
    |
    | A validação real é feita por App\Services\Documents\UploadInspector sobre o
    | CONTEÚDO do arquivo (finfo + assinatura de bytes + inspeção do ZIP do DOCX +
    | cabeçalho da imagem), nunca pela extensão informada pelo navegador. As listas
    | abaixo são o contrato: qualquer coisa fora delas (SVG incluído) é recusada.
    */
    'upload' => [
        // Tamanho máximo do arquivo enviado, em MB.
        'max_mb' => (int) env('ASSINAVELOX_MAX_UPLOAD_MB', 25),

        // MIME types aceitos (exibidos no `accept` do dropzone e verificados por finfo).
        'accepted_mimes' => [
            'application/pdf',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'image/png',
            'image/jpeg',
            'image/webp',
        ],

        // Extensões aceitas por tipo de origem; a extensão precisa combinar com o conteúdo.
        'extensions' => [
            'pdf' => ['pdf'],
            'docx' => ['docx'],
            'image' => ['png', 'jpg', 'jpeg', 'webp'],
        ],

        /*
        | DOCX é um ZIP: antes de aceitar, o pacote é inspecionado para evitar "zip bomb"
        | (poucos bytes que descompactam para gigabytes) e para confirmar que é mesmo um
        | documento do Word (`word/document.xml`).
        */
        'docx' => [
            // Soma máxima dos tamanhos DESCOMPACTADOS de todas as entradas.
            'max_uncompressed_mb' => (int) env('ASSINAVELOX_DOCX_MAX_UNCOMPRESSED_MB', 300),
            // Razão máxima entre o total descompactado e o tamanho do arquivo enviado.
            'max_compression_ratio' => (int) env('ASSINAVELOX_DOCX_MAX_COMPRESSION_RATIO', 150),
            // Quantidade máxima de entradas no pacote.
            'max_entries' => (int) env('ASSINAVELOX_DOCX_MAX_ENTRIES', 2000),
            // Entrada obrigatória: sem ela não é um documento do Word.
            'required_entry' => 'word/document.xml',
        ],

        /*
        | Imagens: os limites são lidos do CABEÇALHO (getimagesize), antes de qualquer
        | decodificação, e coincidem com os do pdftool/ImageNormalizer (40 MP).
        */
        'image' => [
            'max_megapixels' => (int) env('ASSINAVELOX_IMAGE_MAX_MEGAPIXELS', 40),
            'max_side_px' => (int) env('ASSINAVELOX_IMAGE_MAX_SIDE_PX', 20000),
        ],

        // Prefixo dos caminhos no disco privado `documents`.
        'path_prefix' => 'orgs',
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
