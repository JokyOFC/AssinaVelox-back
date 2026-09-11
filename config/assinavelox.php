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

    /*
    |--------------------------------------------------------------------------
    | Flags da Fase 2 (docs/roadmap.md §1 T8) — interruptor GLOBAL da instalação
    |--------------------------------------------------------------------------
    |
    | Todas nascem DESLIGADAS. Para as flags da organização, o recurso só existe
    | quando este interruptor E `plans.features.{flag}` do plano vigente dizem sim
    | (App\Services\Envelopes\DomainFeatures, RemindersFeature, TemplatesFeature,
    | ToolFlags, Permissions::customRolesEnabled). As três flags da plataforma
    | (`admin_users`, `admin_audit`, `impersonation`) só dependem daqui: o painel
    | interno não tem organização corrente.
    |
    | A flag liga a INTERFACE e as rotas novas; a autorização continua nas Policies.
    | Com tudo desligado o comportamento é exatamente o da Fase 1. As chaves são
    | expostas ao front na prop compartilhada `features` (HandleInertiaRequests).
    |
    */
    'features' => [
        // Organização (config global E plano).
        'templates' => filter_var(env('ASSINAVELOX_FEATURE_TEMPLATES', false), FILTER_VALIDATE_BOOLEAN),
        'multi_document' => filter_var(env('ASSINAVELOX_FEATURE_MULTI_DOCUMENT', false), FILTER_VALIDATE_BOOLEAN),
        'participant_roles' => filter_var(env('ASSINAVELOX_FEATURE_PARTICIPANT_ROLES', false), FILTER_VALIDATE_BOOLEAN),
        'reminders' => filter_var(env('ASSINAVELOX_FEATURE_REMINDERS', false), FILTER_VALIDATE_BOOLEAN),
        'custom_roles' => filter_var(env('ASSINAVELOX_FEATURE_CUSTOM_ROLES', false), FILTER_VALIDATE_BOOLEAN),
        'tags' => filter_var(env('ASSINAVELOX_FEATURE_TAGS', false), FILTER_VALIDATE_BOOLEAN),
        'reports' => filter_var(env('ASSINAVELOX_FEATURE_REPORTS', false), FILTER_VALIDATE_BOOLEAN),
        'audit_log' => filter_var(env('ASSINAVELOX_FEATURE_AUDIT_LOG', false), FILTER_VALIDATE_BOOLEAN),
        // Fase 2, onda B — canais (docs/fase-2/canais-e-pin.md). `sms_whatsapp` já era a chave
        // reservada da Fase 1; `pin_auth` e `sender_domains` são novas.
        'sms_whatsapp' => filter_var(env('ASSINAVELOX_FEATURE_SMS_WHATSAPP', false), FILTER_VALIDATE_BOOLEAN),
        'pin_auth' => filter_var(env('ASSINAVELOX_FEATURE_PIN_AUTH', false), FILTER_VALIDATE_BOOLEAN),
        'sender_domains' => filter_var(env('ASSINAVELOX_FEATURE_SENDER_DOMAINS', false), FILTER_VALIDATE_BOOLEAN),
        // Fase 2, onda B — marca da organização, Reply-To e carimbo visual (C-BRAND,
        // docs/fase-2/branding.md). `branding` já era a chave reservada da Fase 1.
        'branding' => filter_var(env('ASSINAVELOX_FEATURE_BRANDING', false), FILTER_VALIDATE_BOOLEAN),
        // Fase 2, onda B — identidade (C-ID, docs/fase-2/identidade.md). `cnpj_lookup` sem
        // organização (cadastro) só depende deste interruptor.
        'cpf_field' => filter_var(env('ASSINAVELOX_FEATURE_CPF_FIELD', false), FILTER_VALIDATE_BOOLEAN),
        'cpf_lookup' => filter_var(env('ASSINAVELOX_FEATURE_CPF_LOOKUP', false), FILTER_VALIDATE_BOOLEAN),
        'cnpj_lookup' => filter_var(env('ASSINAVELOX_FEATURE_CNPJ_LOOKUP', false), FILTER_VALIDATE_BOOLEAN),
        // Desligada até a decisão jurídica da viabilidade §4.4 item 20 (LGPD art. 11, RIPD).
        'identity_capture' => filter_var(env('ASSINAVELOX_FEATURE_IDENTITY_CAPTURE', false), FILTER_VALIDATE_BOOLEAN),
        // Fase 2, onda B — presencial em tablet e assinatura em lote (C-PRES,
        // docs/fase-2/presencial-e-lote.md).
        'in_person' => filter_var(env('ASSINAVELOX_FEATURE_IN_PERSON', false), FILTER_VALIDATE_BOOLEAN),
        'batch_signing' => filter_var(env('ASSINAVELOX_FEATURE_BATCH_SIGNING', false), FILTER_VALIDATE_BOOLEAN),
        // Fase 2, onda B — formulário público que gera envelope (C-FORM,
        // docs/fase-2/formulario-publico.md). Também exige `templates` ligada.
        'public_forms' => filter_var(env('ASSINAVELOX_FEATURE_PUBLIC_FORMS', false), FILTER_VALIDATE_BOOLEAN),
        // Fase 2, onda C — K-TSA (docs/fase-2/carimbo-e-dossie.md). `operator_tsa` e `pades_bt`
        // são da PLATAFORMA (só esta chave); `dossier_export` é da organização (esta chave E plano).
        // `pades_bt` exige `operator_tsa` e NUNCA muda o perfil anunciado (continua PAdES-B-B, T2).
        'operator_tsa' => filter_var(env('ASSINAVELOX_FEATURE_OPERATOR_TSA', false), FILTER_VALIDATE_BOOLEAN),
        'pades_bt' => filter_var(env('ASSINAVELOX_FEATURE_PADES_BT', false), FILTER_VALIDATE_BOOLEAN),
        'dossier_export' => filter_var(env('ASSINAVELOX_FEATURE_DOSSIER_EXPORT', false), FILTER_VALIDATE_BOOLEAN),
        // Fase 2, onda C — K-RET (docs/fase-2/retencao-e-preservacao.md). Organização (esta
        // chave E plano). Uma preservação já criada continua valendo com a flag desligada.
        'retention_policies' => filter_var(env('ASSINAVELOX_FEATURE_RETENTION_POLICIES', false), FILTER_VALIDATE_BOOLEAN),
        // Plataforma (só a config global).
        'admin_users' => filter_var(env('ASSINAVELOX_FEATURE_ADMIN_USERS', false), FILTER_VALIDATE_BOOLEAN),
        'admin_audit' => filter_var(env('ASSINAVELOX_FEATURE_ADMIN_AUDIT', false), FILTER_VALIDATE_BOOLEAN),
        'impersonation' => filter_var(env('ASSINAVELOX_FEATURE_IMPERSONATION', false), FILTER_VALIDATE_BOOLEAN),
    ],

    // Fase 2 §2.3 — teto de arquivos por envelope com `features.multi_document` ligada
    // (desligada vale 1, a regra da Fase 1).
    'multi_document' => [
        'max_documents' => (int) env('ASSINAVELOX_MAX_DOCUMENTS_PER_ENVELOPE', 10),
    ],

    // Fase 2 §2.6 — sessão presencial em tablet (C-PRES, docs/fase-2/presencial-e-lote.md §2).
    'in_person' => [
        // Sem nenhuma ação no dispositivo por este tempo, a sessão expira e a tela bloqueia.
        'idle_minutes' => (int) env('ASSINAVELOX_IN_PERSON_IDLE_MINUTES', 15),
        // Teto absoluto de uma sessão presencial, mesmo com atividade.
        'max_hours' => (int) env('ASSINAVELOX_IN_PERSON_MAX_HOURS', 8),
    ],

    // Fase 2 §2.7 — assinatura em lote (C-PRES, docs/fase-2/presencial-e-lote.md §3).
    'batch_signing' => [
        // Validade do link de lote enviado por e-mail.
        'link_ttl_days' => (int) env('ASSINAVELOX_BATCH_LINK_TTL_DAYS', 7),
        // Validade da autenticação do lote neste navegador (depois do código).
        'session_ttl_minutes' => (int) env('ASSINAVELOX_BATCH_SESSION_TTL_MINUTES', 30),
        // Mínimo de documentos pendentes para oferecer o lote e teto de itens por link.
        'min_items' => (int) env('ASSINAVELOX_BATCH_MIN_ITEMS', 2),
        'max_items' => (int) env('ASSINAVELOX_BATCH_MAX_ITEMS', 50),
    ],

    // Fase 2 §2.5 — lembretes automáticos (docs/fase-2/lembretes-e-agendamento.md).
    'reminders' => [
        // Janela de envio no fuso da organização (padrão da organização; a tela pode mudar).
        'window_start_hour' => (int) env('ASSINAVELOX_REMINDERS_WINDOW_START', 8),
        'window_end_hour' => (int) env('ASSINAVELOX_REMINDERS_WINDOW_END', 20),
        // Destinatários selecionados por execução do comando.
        'batch_size' => (int) env('ASSINAVELOX_REMINDERS_BATCH_SIZE', 500),
        // Link usado há menos disto: o lembrete é adiado para não derrubar quem assina.
        'active_grace_minutes' => (int) env('ASSINAVELOX_REMINDERS_ACTIVE_GRACE_MINUTES', 60),
    ],

    // Fase 2 §2.5 — envio agendado.
    'scheduled_send' => [
        'min_lead_minutes' => (int) env('ASSINAVELOX_SCHEDULED_SEND_MIN_LEAD_MINUTES', 5),
        'max_days' => (int) env('ASSINAVELOX_SCHEDULED_SEND_MAX_DAYS', 60),
        'batch_size' => (int) env('ASSINAVELOX_SCHEDULED_SEND_BATCH_SIZE', 200),
    ],

    /*
    | Fase 2 §2.11 — consulta pública de CNPJ (docs/fase-2/identidade.md §3,
    | docs/integracoes/cnpj-cpf.md §2.2). Instância pública do Minha Receita, SEM SLA: a
    | consulta só AUTOPREENCHE; falhou, o formulário continua manual. A BrasilAPI NÃO é
    | fallback (é proxy da mesma fonte). `driver`: `minha_receita` | `fake` (simulado).
    */
    'cnpj' => [
        'driver' => env('ASSINAVELOX_CNPJ_DRIVER', 'minha_receita'),
        'base_url' => env('ASSINAVELOX_CNPJ_BASE_URL', 'https://minhareceita.org'),
        'timeout_seconds' => (float) env('ASSINAVELOX_CNPJ_TIMEOUT', 4),
        'connect_timeout_seconds' => (float) env('ASSINAVELOX_CNPJ_CONNECT_TIMEOUT', 2),
        'max_response_kb' => (int) env('ASSINAVELOX_CNPJ_MAX_RESPONSE_KB', 512),
        // A base da Receita muda por mês: 30 dias para o encontrado, 1 dia para o "não existe".
        'cache_ttl_days' => (int) env('ASSINAVELOX_CNPJ_CACHE_TTL_DAYS', 30),
        'not_found_ttl_hours' => (int) env('ASSINAVELOX_CNPJ_NOT_FOUND_TTL_HOURS', 24),
        // Consultas por minuto: por usuário autenticado e, no cadastro, por IP.
        'rate_limit' => [
            'per_minute_user' => (int) env('ASSINAVELOX_CNPJ_RATE_USER', 10),
            'per_minute_guest' => (int) env('ASSINAVELOX_CNPJ_RATE_GUEST', 5),
        ],
        'attribution' => 'Fonte: Receita Federal — dados abertos do CNPJ, via Minha Receita',
    ],

    /*
    | Fase 2 §2.11 — consulta CADASTRAL de CPF (classe B). O serviço próprio do proprietário
    | não tem documentação: `disabled` (padrão) responde "inconclusivo — não configurado" sem
    | chamada nenhuma; `fake` é o simulador identificado (recusado em produção). Base legal e
    | finalidade: decisão jurídica pendente (viabilidade §4.4 item 21).
    */
    'cpf_lookup' => [
        'driver' => env('ASSINAVELOX_CPF_LOOKUP_DRIVER', 'disabled'),
        'purpose' => 'Conferência cadastral do CPF informado no documento (finalidade pendente de validação jurídica).',
    ],

    /*
    | Fase 2 §2.10 — captura SIMPLES de foto do rosto e do documento (docs/fase-2/identidade.md
    | §5). Não é biometria. Flag `identity_capture` desligada até a decisão jurídica.
    */
    'capture' => [
        'max_upload_kb' => (int) env('ASSINAVELOX_CAPTURE_MAX_UPLOAD_KB', 8192),
        // Lido do cabeçalho antes de decodificar: a "PNG gigante" morre aqui.
        'max_source_pixels' => (int) env('ASSINAVELOX_CAPTURE_MAX_SOURCE_PIXELS', 30_000_000),
        'output_max_side' => (int) env('ASSINAVELOX_CAPTURE_OUTPUT_MAX_SIDE', 1600),
        'jpeg_quality' => (int) env('ASSINAVELOX_CAPTURE_JPEG_QUALITY', 85),
        'thumbnail_max_side' => (int) env('ASSINAVELOX_CAPTURE_THUMBNAIL_MAX_SIDE', 320),
        // Retenção: a imagem é apagada N dias depois da captura (a linha fica, sem arquivo).
        'retention_days' => (int) env('ASSINAVELOX_CAPTURE_RETENTION_DAYS', 180),
        // Captura que nunca virou aceite (sessão abandonada) sai antes.
        'orphan_retention_hours' => (int) env('ASSINAVELOX_CAPTURE_ORPHAN_RETENTION_HOURS', 48),
        // Envios por participante por hora (refazer a foto conta).
        'max_uploads_per_hour' => (int) env('ASSINAVELOX_CAPTURE_MAX_UPLOADS_PER_HOUR', 30),
    ],

    /*
    | Arquivo "hot" do Vite. Vazio = `public/hot` (padrão do Laravel). Os testes apontam
    | para um caminho inexistente (phpunit.xml) para sempre usarem o build, mesmo com um
    | `npm run dev` rodando — ou com um `public/hot` obsoleto deixado por um dev server
    | encerrado à força (docs/testes.md §2).
    */
    'vite_hot_file' => env('VITE_HOT_FILE'),

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

    /*
    |--------------------------------------------------------------------------
    | Fase 2 §2.9 — canais SMS e WhatsApp (C-CAN, docs/fase-2/canais-e-pin.md)
    |--------------------------------------------------------------------------
    |
    | SMS e WhatsApp são SERVIÇOS PRÓPRIOS do proprietário, sem documentação disponível
    | (viabilidade, regra fixa 1). Por isso existem só dois drivers por canal:
    |
    |  - `fake` (padrão): simulador IDENTIFICADO — grava a mensagem num registro consultável
    |    (App\Integrations\Sms\SimulatedOutbox), loga "[SIMULADO]" e nunca transmite nada.
    |    Só funciona com `allow_simulated` ligado, que por padrão é "fora de produção".
    |  - `http`: adaptador do serviço próprio, DESABILITADO até a documentação chegar
    |    (`isConfigured()` é sempre false e a mensagem lista exatamente o que falta).
    |
    | Nunca Evolution API, WPPConnect, Baileys, Gammu nem provedor de terceiro.
    |
    | Com o canal indisponível (produção sem serviço integrado), o remetente não consegue
    | escolher SMS/WhatsApp e a interface recebe o motivo (ChannelAvailability::wizardProps()).
    */
    'channels' => [
        'allow_simulated' => filter_var(
            env('ASSINAVELOX_CHANNELS_ALLOW_SIMULATED', env('APP_ENV', 'production') !== 'production'),
            FILTER_VALIDATE_BOOLEAN,
        ),
        // Região padrão para interpretar números sem +DDI (libphonenumber).
        'default_region' => env('ASSINAVELOX_PHONE_DEFAULT_REGION', 'BR'),
        // Teto por chamada ao provedor. Tempo esgotado = resultado DESCONHECIDO (T5), nunca sucesso.
        'timeout_seconds' => (int) env('ASSINAVELOX_CHANNELS_TIMEOUT_SECONDS', 10),
        // Mensagens por SMS + WhatsApp por organização por dia (custo por mensagem, roadmap §2.9).
        'org_daily_limit' => (int) env('ASSINAVELOX_CHANNELS_ORG_DAILY_LIMIT', 500),
        'sms' => [
            'driver' => env('ASSINAVELOX_SMS_DRIVER', 'fake'),
        ],
        'whatsapp' => [
            'driver' => env('ASSINAVELOX_WHATSAPP_DRIVER', 'fake'),
            // Nomes dos templates por finalidade. Os templates reais precisam ser aprovados no
            // serviço próprio — até lá são só os nomes que o simulador registra.
            'templates' => [
                'otp' => env('ASSINAVELOX_WHATSAPP_TEMPLATE_OTP', 'assinavelox_codigo'),
                'invitation' => env('ASSINAVELOX_WHATSAPP_TEMPLATE_INVITATION', 'assinavelox_convite'),
                'resend' => env('ASSINAVELOX_WHATSAPP_TEMPLATE_RESEND', 'assinavelox_convite'),
            ],
        ],
        /*
        | Webhook de status (POST /webhooks/sms/status e /webhooks/whatsapp/status). Contrato
        | proposto por nós: HMAC-SHA256 sobre "{timestamp}.{corpo bruto}" com janela de
        | tolerância (App\Integrations\Sms\StatusCallbackSignature). Com o provedor desabilitado
        | a rota responde 503. `simulated_secret` só vale para o simulador (testes/local).
        */
        'status_webhook' => [
            'tolerance_seconds' => (int) env('ASSINAVELOX_CHANNELS_WEBHOOK_TOLERANCE_SECONDS', 300),
            'simulated_secret' => env('ASSINAVELOX_CHANNELS_SIMULATED_WEBHOOK_SECRET'),
        ],
        // Registro do simulador: em memória sempre; também no cache no ambiente `local`, para
        // o desenvolvedor ler o código "enviado" (nunca em log).
        'simulated_outbox' => [
            'persist' => filter_var(env('ASSINAVELOX_CHANNELS_SIMULATED_PERSIST', env('APP_ENV') === 'local'), FILTER_VALIDATE_BOOLEAN),
            'ttl_minutes' => (int) env('ASSINAVELOX_CHANNELS_SIMULATED_TTL_MINUTES', 30),
            'max_messages' => 50,
        ],
    ],

    /*
    | Fase 2 §2.9 — PIN do remetente (flag `pin_auth`). O remetente define o PIN no wizard e o
    | comunica POR FORA; o sistema nunca o envia. Guardado só como password_hash de um HMAC
    | com segredo do servidor (App\Services\Signing\Channels\SenderPins). É autenticação
    | ADICIONAL: vem depois do código do canal, nunca o substitui.
    */
    'pin' => [
        'min_length' => 4,
        'max_length' => 8,
        // Erros antes do bloqueio temporário.
        'max_attempts' => (int) env('ASSINAVELOX_PIN_MAX_ATTEMPTS', 5),
        // Duração do bloqueio temporário (minutos). Depois dele é preciso pedir outro código.
        'lockout_minutes' => (int) env('ASSINAVELOX_PIN_LOCKOUT_MINUTES', 15),
        // Bloqueios temporários seguidos até o PIN ficar bloqueado de vez (remetente redefine).
        'max_lockouts' => (int) env('ASSINAVELOX_PIN_MAX_LOCKOUTS', 3),
    ],

    /*
    | Fase 2 §2.8 — domínios de envio (flag `sender_domains`, classe B). A verificação real
    | depende da API do serviço de e-mail do proprietário (sem documentação): `http` fica
    | desabilitado; `fake` é o simulador. Domínio verificado PELO SIMULADOR nunca vira
    | remetente: sem verificação real o e-mail sai pelo remetente padrão com Reply-To do cliente.
    */
    'sender_domains' => [
        'verifier' => env('ASSINAVELOX_SENDER_DOMAIN_VERIFIER', 'fake'),
        'max_per_organization' => (int) env('ASSINAVELOX_SENDER_DOMAINS_MAX', 5),
        // Parte local do remetente próprio: {from_local_part}@{domínio verificado}.
        'from_local_part' => env('ASSINAVELOX_SENDER_DOMAIN_LOCAL_PART', 'assinaturas'),
    ],

    /*
    | Contratos reservados com simulador identificado (C-CAN). Só existe o driver `fake`;
    | a implementação real de cada um é de outra onda (roadmap §2.13, §2.21, §3.6).
    */
    'integrations' => [
        'timestamp' => ['driver' => env('ASSINAVELOX_TIMESTAMP_DRIVER', 'fake')],
        'fiscal_invoice' => ['driver' => env('ASSINAVELOX_FISCAL_INVOICE_DRIVER', 'fake')],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fase 2, onda C §2.13 — TSA da OPERADORA, RFC 3161 (K-TSA)
    |--------------------------------------------------------------------------
    |
    | docs/fase-2/carimbo-e-dossie.md. Carimbo emitido com a chave da própria AssinaVelox
    | (`tsa_kind = operator`): prova apenas que "a operadora atesta que este resumo existia
    | em genTime". NÃO é carimbo ICP-Brasil (roadmap T3; DOC-ICP-11 §2.7.2) e o rótulo é
    | sempre "carimbo do tempo da operadora — não é carimbo ICP-Brasil".
    |
    | Chave e certificado: PKCS#12 por REFERÊNCIA de arquivo; a senha só existe na variável de
    | ambiente cujo NOME está em `password_env` (injetada no processo filho do pdftool, nunca
    | em argv, log, fila ou banco). `php artisan tsa:generate-test` gera uma TSA de TESTE
    | (autoassinada, CN com "TESTE") — nunca para produção. Checklist de produção (HSM/KMS,
    | NTP monitorado, OID próprio, AC interna): `php artisan tsa:status`.
    |
    */
    'tsa' => [
        'pfx_path' => env('ASSINAVELOX_TSA_PFX_PATH'),
        'password_env' => env('ASSINAVELOX_TSA_PASSWORD_ENV', 'ASSINAVELOX_TSA_PASSWORD'),
        // Cadeia PÚBLICA da TSA (certificado + AC interna, PEM), distribuída junto do dossiê.
        'chain_pem' => env('ASSINAVELOX_TSA_CHAIN_PEM'),
        // Raízes para `tsa-verify` (PEM/DER separados por ";"). Sem raiz: confere integridade,
        // nunca afirma confiança.
        'trust_roots' => array_values(array_filter(array_map('trim', explode(';', (string) env('ASSINAVELOX_TSA_TRUST_ROOTS', ''))))),
        // test | production. Qualquer outro valor vale `test`.
        'environment' => env('ASSINAVELOX_TSA_ENVIRONMENT', 'test'),
        // OID da política no TSTInfo. O padrão é o OID de EXEMPLO da ITU-T X.667 (arco 2.25,
        // UUID f81d4fae-…): serve para teste e é recusado pelo `tsa:status` em produção.
        'policy_oid' => env('ASSINAVELOX_TSA_POLICY_OID', '2.25.329800735698586629295641978511506172918'),
        // Precisão declarada no carimbo. Só é honesta com o NTP monitorado dentro dela.
        'accuracy_ms' => (int) env('ASSINAVELOX_TSA_ACCURACY_MS', 1000),
        // Soma ao número sequencial do banco (a sequência nunca reutiliza um valor).
        'serial_offset' => (int) env('ASSINAVELOX_TSA_SERIAL_OFFSET', 0),
        'timeout_seconds' => (int) env('ASSINAVELOX_TSA_TIMEOUT_SECONDS', 30),
        // Endpoint INTERNO `POST /tsa` (application/timestamp-query → timestamp-reply), para o
        // próprio sistema. Bearer = valor da variável nomeada em `token_env`; IPs permitidos.
        'http' => [
            'token_env' => env('ASSINAVELOX_TSA_HTTP_TOKEN_ENV', 'ASSINAVELOX_TSA_HTTP_TOKEN'),
            'allowed_ips' => array_values(array_filter(array_map('trim', explode(',', (string) env('ASSINAVELOX_TSA_HTTP_ALLOWED_IPS', '127.0.0.1,::1'))))),
            'max_request_bytes' => (int) env('ASSINAVELOX_TSA_HTTP_MAX_BYTES', 8192),
            'rate_per_minute' => (int) env('ASSINAVELOX_TSA_HTTP_RATE', 120),
        ],
        // Carimbo ICP-Brasil (roadmap §3.6): `disabled` (padrão — produção bloqueada até o
        // contrato com uma ACT, viabilidade §4.2 item 7) ou `fake` (simulador que NUNCA grava
        // `icp_brasil`; só fora de produção, `channels.allow_simulated`).
        'icp_brasil' => [
            'driver' => env('ASSINAVELOX_TSA_ICP_BRASIL_DRIVER', 'disabled'),
        ],
    ],

    /*
    | Fase 2, onda C §2.13 — dossiê ZIP do envelope (K-TSA). Montado em fila, guardado no
    | disco privado e servido só por link autorizado com expiração; o arquivo é apagado
    | depois de `ttl_hours`. Sem segredos, com o IP/e-mail conforme `evidence_show_ip`.
    */
    'dossier' => [
        'disk' => env('ASSINAVELOX_DOSSIER_DISK', 'documents'),
        'path' => 'dossiers',
        'ttl_hours' => (int) env('ASSINAVELOX_DOSSIER_TTL_HOURS', 24),
        // "Baixar" em lote (Q12): teto de envelopes por pedido.
        'max_bulk_envelopes' => (int) env('ASSINAVELOX_DOSSIER_MAX_BULK', 50),
        // Teto do ZIP gerado (MB).
        'max_mb' => (int) env('ASSINAVELOX_DOSSIER_MAX_MB', 500),
        'queue' => env('ASSINAVELOX_DOSSIER_QUEUE', 'default'),
        // Revalida no pdftool, na montagem, o PDF final assinado (além do resultado gravado).
        'revalidate_signatures' => filter_var(env('ASSINAVELOX_DOSSIER_REVALIDATE', true), FILTER_VALIDATE_BOOLEAN),
    ],

    /*
    | Fase 2, onda C §2.19 — retenção configurável e preservação (K-RET,
    | docs/fase-2/retencao-e-preservacao.md §8). Os padrões abaixo são os mesmos do código
    | (App\Services\Retention\RetentionConfig): declarar o bloco não muda comportamento.
    | Os mínimos são da OPERADORA e AGUARDAM REVISÃO JURÍDICA (§4 da doc).
    */
    'retention' => [
        // hidden | notice | notice_with_final_hash (recomendado; decisão do proprietário, §6).
        'verification_after_purge' => env('ASSINAVELOX_RETENTION_VERIFICATION', 'notice_with_final_hash'),
        // A trilha é append-only (T7). Ligar exige DELETE em `audit_events` para o job.
        'allow_audit_trail_deletion' => filter_var(env('ASSINAVELOX_RETENTION_ALLOW_AUDIT_TRAIL_DELETION', false), FILTER_VALIDATE_BOOLEAN),
        'batch_size' => (int) env('ASSINAVELOX_RETENTION_BATCH_SIZE', 100),
        // Janela de rotação dos backups declarada na Política de Privacidade (§7.1). PENDENTE:
        // alinhar com docs/implantacao.md §14.1 (arquivos hoje ficam 90 dias).
        'backup_window_days' => (int) env('ASSINAVELOX_RETENTION_BACKUP_WINDOW_DAYS', 35),
        'minimum_days' => [
            'completed' => (int) env('ASSINAVELOX_RETENTION_MIN_COMPLETED', 1825),
            'terminal_other' => (int) env('ASSINAVELOX_RETENTION_MIN_TERMINAL', 180),
            'draft' => (int) env('ASSINAVELOX_RETENTION_MIN_DRAFT', 30),
            'identity_capture' => (int) env('ASSINAVELOX_RETENTION_MIN_CAPTURE', 7),
            'dossier' => (int) env('ASSINAVELOX_RETENTION_MIN_DOSSIER', 1),
            'audit_trail' => (int) env('ASSINAVELOX_RETENTION_MIN_AUDIT_TRAIL', 1825),
        ],
        // 'derived_artifacts' => [...] — só para sobrescrever o padrão de RetentionConfig.
    ],

    // Unidade de consumo do plano: envelope_sent (Fase 1). Reservado para outras unidades.
    'plan_consumption_unit' => env('ASSINAVELOX_PLAN_CONSUMPTION_UNIT', 'envelope_sent'),

    // Exibição de IP na página de evidências: masked | full | none.
    'evidence_show_ip' => env('ASSINAVELOX_EVIDENCE_SHOW_IP', 'masked'),

    /*
    |--------------------------------------------------------------------------
    | Finalização e página de evidências (docs/finalizacao-e-evidencias.md)
    |--------------------------------------------------------------------------
    |
    | A página de evidências é gerada em Blade → DOMPDF ANTES da assinatura e
    | anexada ao final do documento consolidado. Ela nunca imprime o hash final
    | (que só existe depois de o arquivo estar pronto) nem se apresenta como
    | certificado digital.
    |
    */
    'evidence' => [
        // View Blade renderizada pelo DOMPDF.
        'view' => 'evidence.page',

        // Papel e margens (mm) da página de evidências.
        'paper' => env('ASSINAVELOX_EVIDENCE_PAPER', 'a4'),

        /*
        | Fonte padrão do DOMPDF. "DejaVu Sans" acompanha o dompdf e tem os
        | acentos do português; trocar por uma fonte sem cobertura Unicode faz
        | "ação" virar "a??o" no PDF entregue.
        */
        'font' => env('ASSINAVELOX_EVIDENCE_FONT', 'DejaVu Sans'),

        // Eventos da trilha impressos na linha do tempo (os demais são internos).
        'timeline_events' => [
            'envelope.sent',
            'invitation.sent',
            'invitation.resent',
            'invitation.opened',
            'challenge.verified',
            'acceptance.recorded',
            // Fase 2 §2.4: aprovação registrada (aprovador).
            'approval.recorded',
            'recipient.refused',
            'envelope.refused',
            'envelope.finalizing',
            'envelope.consolidated',
            'envelope.evidence_generated',
            'envelope.signed_company_a1',
            'envelope.completed',
        ],

        // Limite de eventos impressos (uma trilha longa não pode virar 40 páginas).
        'timeline_limit' => (int) env('ASSINAVELOX_EVIDENCE_TIMELINE_LIMIT', 200),

        /*
        | QR code impresso na página de evidências, apontando para
        | {app.url}/verificar/{code}. Módulo em pixels e margem em módulos.
        */
        'qr' => [
            'module_px' => 4,
            'quiet_zone' => 2,
        ],

        /*
        | Rodapé carimbado em TODAS as páginas do documento consolidado
        | (docs/juridico/declaracao-de-aceite.md §6). É desenhado na etapa de
        | composição, antes da assinatura, e nunca contém o hash final, nomes,
        | e-mails ou IPs — apenas a URL de verificação e o código.
        |
        | Geometria normalizada [0,1] sobre a página exibida (mesma convenção de
        | signing_fields e do `pdftool compose`).
        */
        'footer' => [
            'enabled' => (bool) env('ASSINAVELOX_EVIDENCE_FOOTER', true),
            'x' => 0.06,
            'y' => 0.962,
            'width' => 0.88,
            'height' => 0.022,
            'font_size' => 7.0,
            'align' => 'center',
        ],
    ],

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

        /*
        | Permissions-Policy: lista de negação explícita. Nenhuma tela da Fase 1 usa
        | câmera, microfone, geolocalização, sensores ou a Payment Request API — o
        | Checkout Pro acontece no site do Mercado Pago, não aqui. `fullscreen=(self)`
        | fica liberado para o visualizador de PDF; `publickey-credentials-get=(self)`
        | fica liberado porque o Fortify pode oferecer passkeys em fase futura.
        */
        'permissions_policy' => env('ASSINAVELOX_PERMISSIONS_POLICY', implode(', ', [
            'accelerometer=()',
            'ambient-light-sensor=()',
            'autoplay=()',
            'battery=()',
            'bluetooth=()',
            'camera=()',
            'display-capture=()',
            'encrypted-media=()',
            'fullscreen=(self)',
            'geolocation=()',
            'gyroscope=()',
            'hid=()',
            'idle-detection=()',
            'local-fonts=()',
            'magnetometer=()',
            'microphone=()',
            'midi=()',
            'payment=()',
            'picture-in-picture=()',
            'publickey-credentials-get=(self)',
            'screen-wake-lock=()',
            'serial=()',
            'usb=()',
            'xr-spatial-tracking=()',
        ])),

        /*
        | Cross-Origin-Opener-Policy. `same-origin-allow-popups` isola o grupo de
        | navegação (uma janela aberta pela nossa página não consegue tocar na nossa
        | `window`), mas preserva `window.opener` para popups que NÓS abrimos — o
        | retorno do Checkout Pro depende disso quando o cliente abre o pagamento em
        | outra aba. `same-origin` é mais estrito e pode ser usado quando o checkout
        | for sempre por redirecionamento na mesma aba.
        */
        'coop' => env('ASSINAVELOX_COOP', 'same-origin-allow-popups'),

        /*
        | Cross-Origin-Resource-Policy: nenhuma resposta nossa (PDF, PNG de página,
        | JSON do Inertia) deve poder ser embutida por outro site.
        |
        | NÃO emitimos Cross-Origin-Embedder-Policy: `require-corp` exigiria CORP/CORS
        | em todo recurso de terceiro e não compra nada aqui (não usamos
        | SharedArrayBuffer nem medição de memória isolada).
        */
        'corp' => env('ASSINAVELOX_CORP', 'same-origin'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Limites de requisição por nome de rota (docs/seguranca-operacional.md §2)
    |--------------------------------------------------------------------------
    |
    | Mapa `nome da rota => limitador nomeado` aplicado pelo middleware
    | App\Http\Middleware\ThrottleSensitiveRoutes (grupo `web`). Ele existe porque
    | várias rotas sensíveis são registradas pelo pacote Fortify — cadastro,
    | recuperação de senha, redefinição — e lá não há como pendurar `throttle:` sem
    | editar o pacote. As rotas declaradas em routes/web.php já trazem o seu
    | `throttle:` no próprio arquivo; as que aparecem aqui e lá recebem os dois
    | limites (baldes independentes).
    |
    | Todos os limitadores estão definidos em App\Providers\AppServiceProvider e
    | usam CHAVE COMPOSTA (identidade + origem): um atacante que martela o endereço
    | de outra pessoa a partir do seu próprio IP não tranca a vítima.
    |
    */
    'rate_limit_routes' => [
        /*
        | Fortify — sem limitador próprio no pacote. Só os POSTs entram: pendurar limite
        | no GET do formulário limitaria VER a página, o que não é o ataque.
        */
        'register.store' => 'register',
        'password.email' => 'password-email',
        'password.update' => 'password-reset',
        'password.confirm.store' => 'password-confirm',

        // Convite de membro: o token não pode ser a única chave.
        'invitations.accept' => 'invitation',
        'invitations.accept.store' => 'invitation',

        // Downloads autorizados e exportações (arquivo inteiro por requisição).
        'envelopes.download' => 'download',
        'envelopes.evidence' => 'download',
        // Transmite o PDF completo da versão exibível pelo mesmo DocumentStorage::stream()
        // dos demais: é leitura de arquivo inteiro e entra na mesma regra.
        'envelopes.document.preview' => 'download',
        'sign.download' => 'download',
        'billing.payments.receipt' => 'download',
        'dashboard.export' => 'export',
        'recipients.export' => 'export',
        'admin.organizations.export' => 'export',
    ],

    /*
    |--------------------------------------------------------------------------
    | Trilha de auditoria: checkpoint encadeado (docs/seguranca-operacional.md §3)
    |--------------------------------------------------------------------------
    |
    | `audit:checkpoint` exporta os eventos de um período para um arquivo JSON no
    | disco privado e grava um resumo encadeado (o hash do lote inclui o hash do
    | lote anterior). Isso torna DETECTÁVEL uma alteração posterior nas linhas já
    | exportadas — não a impede. Ver as limitações honestas na documentação.
    |
    */
    'audit' => [
        'checkpoint' => [
            // Disco do Flysystem onde o lote é gravado (privado, nunca público).
            'disk' => env('ASSINAVELOX_AUDIT_CHECKPOINT_DISK', 'documents'),
            // Prefixo dos caminhos dentro do disco.
            'path' => env('ASSINAVELOX_AUDIT_CHECKPOINT_PATH', 'audit-checkpoints'),
            // Eventos lidos por página ao montar o lote (memória previsível).
            'chunk' => (int) env('ASSINAVELOX_AUDIT_CHECKPOINT_CHUNK', 1000),
        ],

        /*
        | Tabelas de evidência e o que a APLICAÇÃO precisa poder fazer nelas. O mapa é o
        | contrato do GRANT recomendado em docs/seguranca-operacional.md §3 e é conferido
        | por tests/Feature/Hardening/AppendOnlyEvidenceTest.php, que espiona o SQL
        | realmente emitido durante um aceite.
        |
        |  - `no_update_no_delete`: a aplicação só INSERE e LÊ.
        |  - `no_delete`: a aplicação também ATUALIZA, num caminho específico e
        |    documentado. `verification_records` é o único caso: a retentativa da
        |    finalização reescreve o registro quando o arquivo final foi reconstruído
        |    (EnvelopeFinalizer, "verification_record: rewritten"). Publicar o registro de
        |    uma execução descartada sobre um PDF diferente seria pior do que reescrever,
        |    então a tabela recebe UPDATE — e nunca DELETE.
        |
        | Atenção ao aplicar o REVOKE: `document_versions` some por ON DELETE CASCADE
        | quando um documento em rascunho é substituído. A aplicação não emite o DELETE
        | (quem apaga é o InnoDB, ao remover a linha de `documents`), mas confirme o
        | comportamento na sua versão do MySQL antes de restringir — o procedimento de
        | conferência está na documentação.
        */
        'evidence_tables' => [
            'audit_events' => 'no_update_no_delete',
            'signature_acceptances' => 'no_update_no_delete',
            'document_versions' => 'no_update_no_delete',
            'verification_records' => 'no_delete',
            // Fase 2 §2.3 (docs/fase-2/multi-documento-e-papeis.md): o que cada aceite cobriu e
            // o que cada sessão recebeu — só INSERT. `verification_record_documents` NÃO entra:
            // é o resumo por arquivo do registro público, reescrito pela retentativa da
            // finalização (tem `updated_at`), e sai em cascata com `verification_records`.
            'acceptance_documents' => 'no_update_no_delete',
            'signing_session_documents' => 'no_update_no_delete',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Observabilidade (docs/seguranca-operacional.md §6)
    |--------------------------------------------------------------------------
    */
    'observability' => [
        /*
        | Identificador de correlação: gerado por requisição (middleware
        | AssignCorrelationId) e propagado a jobs pelo Context do Laravel.
        | `echo_header` devolve o identificador no cabeçalho X-Correlation-Id — é um
        | ULID opaco, não um segredo, e encurta muito o suporte.
        */
        'correlation' => [
            'echo_header' => (bool) env('ASSINAVELOX_CORRELATION_HEADER', true),
            'header' => 'X-Correlation-Id',
        ],

        /*
        | Mascaramento nos registros (App\Logging\RedactSensitiveData). Ligado por
        | padrão: o processador redige ANTES de o registro chegar ao handler.
        */
        'redaction' => [
            'enabled' => (bool) env('ASSINAVELOX_LOG_REDACTION', true),
            'placeholder' => '[REDIGIDO]',
            // Chaves de contexto redigidas inteiras (comparação sem diferenciar caixa).
            'keys' => [
                'password', 'password_confirmation', 'senha', 'current_password',
                'token', 'access_token', 'refresh_token', 'api_key', 'apikey',
                'secret', 'webhook_secret', 'client_secret', 'signature', 'x-signature',
                'authorization', 'cookie', 'set-cookie', 'bearer',
                'code', 'otp', 'code_hash', 'token_digest', 'authorization_token',
                'two_factor_secret', 'two_factor_recovery_codes', 'recovery_code',
                'pfx', 'pfx_password', 'passphrase', 'private_key',
                'tax_id', 'cpf', 'cnpj', 'document',
                // Fase 2, onda B: PIN do remetente (C-CAN) e imagem da captura simples (C-ID).
                'pin', 'image',
            ],
            /*
            | Exceções à lista acima, conferidas ANTES dela e por nome exato.
            |
            | A regra de chaves casa por sufixo (`_code` casa `exit_code`), o que
            | é o comportamento certo para `otp_code` e errado para um código de
            | diagnóstico. Sem estas exceções o log estruturado sai com
            | `"exit_code":"[REDIGIDO]"` justamente quando alguém está tentando
            | descobrir por que a conversão falhou — o campo mais útil vira o
            | único ilegível. Todos os nomes abaixo carregam número ou rótulo de
            | erro, nunca segredo nem dado pessoal.
            */
            'allow_keys' => [
                'exit_code', 'status_code', 'http_code', 'response_code',
                'failure_code', 'error_code', 'reason_code',
            ],
        ],

        /*
        | Limiares dos indicadores de saúde (`assinavelox:health`). São avisos
        | operacionais, não SLA contratual.
        */
        'health' => [
            // Jobs esperando na fila (driver database) acima disto = degradado.
            'queue_backlog_warning' => (int) env('ASSINAVELOX_HEALTH_QUEUE_BACKLOG', 100),
            // Jobs que falharam nas últimas N horas.
            'failed_jobs_window_hours' => (int) env('ASSINAVELOX_HEALTH_FAILED_WINDOW_HOURS', 24),
            // Documento parado em `converting` por mais que isto = conversão travada.
            'conversion_stuck_minutes' => (int) env('ASSINAVELOX_HEALTH_CONVERSION_STUCK_MINUTES', 30),
            // Envelope parado em `finalizing` por mais que isto = finalização travada.
            'finalization_stuck_minutes' => (int) env('ASSINAVELOX_HEALTH_FINALIZATION_STUCK_MINUTES', 30),
            // Entregas de e-mail em `queued`/`unknown` por mais que isto.
            'delivery_stuck_minutes' => (int) env('ASSINAVELOX_HEALTH_DELIVERY_STUCK_MINUTES', 60),
            // Recibos de webhook em `received` por mais que isto = conciliação parada.
            'webhook_stuck_minutes' => (int) env('ASSINAVELOX_HEALTH_WEBHOOK_STUCK_MINUTES', 30),
            // Aviso de vencimento do certificado A1.
            'certificate_warning_days' => (int) env('ASSINAVELOX_HEALTH_CERT_WARNING_DAYS', 30),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Criptografia em repouso do armazenamento (docs/seguranca-operacional.md §5)
    |--------------------------------------------------------------------------
    |
    | O Flysystem NÃO cifra nada sozinho. Em S3 a cifra é do lado do servidor e é
    | CONSULTADA ao backend por `storage:verify` (GetBucketEncryption + o cabeçalho
    | de um objeto de prova). Em disco local não existe consulta possível a partir
    | do PHP: a cifra é do volume (LUKS/BitLocker) e só pode ser ATESTADA pelo
    | operador — a atestação abaixo é registrada como tal, nunca como verificação.
    |
    */
    'storage_encryption' => [
        // Atestação do operador para disco local: "o volume está cifrado".
        'local_attested' => (bool) env('ASSINAVELOX_STORAGE_ENCRYPTION_ATTESTED', false),
        // Referência humana da atestação (ex.: "LUKS2 /dev/vg0/documents, ticket OPS-431").
        'local_attestation_note' => env('ASSINAVELOX_STORAGE_ENCRYPTION_NOTE'),
        // SSE esperada no S3: AES256 (SSE-S3) ou aws:kms (SSE-KMS).
        's3_expected_algorithm' => env('ASSINAVELOX_STORAGE_S3_SSE', 'AES256'),
        // Recibo da última execução de `storage:verify` (disco `local`, fora do disco de documentos).
        'receipt_path' => 'hardening/storage-verify.json',
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
    | environment: sandbox | production. Sem `access_token` o adaptador real fica
    | DESABILITADO (isConfigured() = false) e o checkout responde dizendo isso —
    | nada de endpoint inventado. Ver docs/cobranca.md e docs/integracoes/mercado-pago.md.
    |
    | `driver`: `auto` usa o Mercado Pago quando há credencial e o gateway FAKE quando
    | não há; `fake` força o gateway fake (desenvolvimento/testes); `mercadopago` força o
    | real (e falha claramente sem credencial).
    */
    'mercadopago' => [
        'driver' => env('MERCADOPAGO_DRIVER', 'auto'), // auto | fake | mercadopago
        'environment' => env('MERCADOPAGO_ENVIRONMENT', 'sandbox'),
        'access_token' => env('MERCADOPAGO_ACCESS_TOKEN'),
        'public_key' => env('MERCADOPAGO_PUBLIC_KEY'),
        'webhook_secret' => env('MERCADOPAGO_WEBHOOK_SECRET'),
        'notification_url' => env('MERCADOPAGO_NOTIFICATION_URL'),

        // Janela aceita entre o `ts` do cabeçalho x-signature e o relógio do servidor.
        'webhook_tolerance_seconds' => (int) env('MERCADOPAGO_WEBHOOK_TOLERANCE_SECONDS', 300),

        // HTTP: timeout total e tentativas. Só repete em erro de conexão, 429 e 5xx,
        // sempre com a MESMA X-Idempotency-Key (repetição idempotente).
        'timeout_seconds' => (int) env('MERCADOPAGO_TIMEOUT_SECONDS', 20),
        'connect_timeout_seconds' => (int) env('MERCADOPAGO_CONNECT_TIMEOUT_SECONDS', 10),
        'retries' => (int) env('MERCADOPAGO_RETRIES', 2),
        'retry_delay_ms' => (int) env('MERCADOPAGO_RETRY_DELAY_MS', 500),

        // Preferência do Checkout Pro. `statement_descriptor` tem no máximo 13 caracteres.
        'statement_descriptor' => env('MERCADOPAGO_STATEMENT_DESCRIPTOR', 'ASSINAVELOX'),
        'binary_mode' => (bool) env('MERCADOPAGO_BINARY_MODE', false),
        'preference_ttl_hours' => (int) env('MERCADOPAGO_PREFERENCE_TTL_HOURS', 48),
        'installments' => (int) env('MERCADOPAGO_INSTALLMENTS', 1),
        // Tipos de pagamento excluídos da preferência (lista separada por vírgula).
        // `account_money` NÃO pode ser excluído (regra do provedor).
        'excluded_payment_types' => env('MERCADOPAGO_EXCLUDED_PAYMENT_TYPES', ''),
    ],

    /*
    | Ciclo de cobrança e inadimplência (RECONCILIACAO Q20: pagamento avulso por ciclo
    | via Checkout Pro; sem recorrência automática). `grace_days` dias após o fim do
    | período a assinatura vai para `past_due` (bloqueia envio, mantém leitura e
    | download); `expired_days` depois ela expira e a organização volta ao plano free.
    */
    'billing' => [
        'grace_days' => (int) env('ASSINAVELOX_BILLING_GRACE_DAYS', 3),
        'expired_days' => (int) env('ASSINAVELOX_BILLING_EXPIRED_DAYS', 15),
        // Operadora impressa no recibo interno (que NÃO é documento fiscal).
        'operator' => [
            'name' => env('ASSINAVELOX_OPERATOR_NAME', 'AssinaVelox'),
            'legal_name' => env('ASSINAVELOX_OPERATOR_LEGAL_NAME'),
            'tax_id' => env('ASSINAVELOX_OPERATOR_TAX_ID'),
        ],
    ],

    // Suporte/ajuda exibidos na UI.
    'help_url' => env('ASSINAVELOX_HELP_URL', 'https://ajuda.assinavelox.com.br'),
    'support_email' => env('ASSINAVELOX_SUPPORT_EMAIL', 'suporte@assinavelox.com.br'),

    /*
    |--------------------------------------------------------------------------
    | Fase 2, onda C §2.12 — assinatura com o certificado A1 do PRÓPRIO participante (K-A1)
    |--------------------------------------------------------------------------
    |
    | docs/fase-2/a1-do-participante.md. `enabled` é o interruptor GLOBAL; a organização
    | também precisa de `plans.features.participant_a1` (App\Services\Signing\Certificates\
    | ParticipantA1Feature). Nasce DESLIGADA: com ela desligada, nada muda.
    |
    | Segredos: o PFX e a senha do participante nunca vão para banco, fila, log ou argv. Entre
    | a requisição e o worker, o conjunto fica CIFRADO (AES-256-GCM, chave derivada da
    | APP_KEY) em `sealed_path`, por no máximo `sealed_ttl_minutes`, e é apagado ao ser
    | consumido. Nada é guardado depois do uso (custódia persistente NÃO é implementada).
    |
    */
    'participant_a1' => [
        'enabled' => filter_var(env('ASSINAVELOX_FEATURE_PARTICIPANT_A1', false), FILTER_VALIDATE_BOOLEAN),
        // Um A1 tem poucos KB; o teto barra arquivo que não é certificado.
        'max_upload_kb' => (int) env('ASSINAVELOX_PARTICIPANT_A1_MAX_UPLOAD_KB', 64),
        // Material cifrado temporário (fora de public/, 0700) e o prazo dele.
        'sealed_path' => env('ASSINAVELOX_PARTICIPANT_A1_SEALED_PATH') ?: storage_path('app/private/participant-a1'),
        'sealed_ttl_minutes' => (int) env('ASSINAVELOX_PARTICIPANT_A1_SEALED_TTL_MINUTES', 15),
        // Prazo para os participantes que optaram aplicarem o certificado depois que todos
        // aceitaram. Vencido, o envelope conclui sem aquela assinatura (o aceite continua valendo).
        'application_window_minutes' => (int) env('ASSINAVELOX_PARTICIPANT_A1_WINDOW_MINUTES', 4320),
        // Lock por envelope (um gravador criptográfico por vez) e a espera por ele.
        'lock_seconds' => (int) env('ASSINAVELOX_PARTICIPANT_A1_LOCK_SECONDS', 900),
        'lock_wait_seconds' => (int) env('ASSINAVELOX_PARTICIPANT_A1_LOCK_WAIT_SECONDS', 120),
        // Raízes para validar os certificados dos participantes (PEM/DER separados por ";"). Sem
        // raiz, a validação afirma integridade e diz "cadeia não verificada" — nunca ICP-Brasil.
        'trust_roots' => array_values(array_filter(array_map('trim', explode(';', (string) env('ASSINAVELOX_PARTICIPANT_A1_TRUST_ROOTS', ''))))),
        // Certificados de TESTE (CN com "TESTE"): aceitos fora de produção, recusados em produção
        // por padrão. Mesmo aceitos, são sempre rotulados como teste.
        'accept_test_certificates' => filter_var(
            env('ASSINAVELOX_PARTICIPANT_A1_ACCEPT_TEST_CERTIFICATES', env('APP_ENV', 'production') !== 'production'),
            FILTER_VALIDATE_BOOLEAN,
        ),
        'reason' => env('ASSINAVELOX_PARTICIPANT_A1_REASON', 'Assinatura com o certificado do participante'),
    ],
];
