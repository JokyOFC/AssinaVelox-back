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
        // Fase 3 §3.3 — F-VIDEO (docs/fase-3/captura-de-video.md). Organização (esta chave E
        // plano). Aceite complementado por vídeo curto, sem som. Desligada até a decisão
        // jurídica da viabilidade §4.4 item 20 (LGPD art. 11, RIPD). Parâmetros em `capture_video`.
        'identity_video' => filter_var(env('ASSINAVELOX_FEATURE_IDENTITY_VIDEO', false), FILTER_VALIDATE_BOOLEAN),
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
        // Fase 3, onda E — P3-LTV (docs/fase-3/longo-prazo.md). PLATAFORMA (só esta chave).
        // `pades_ltv` exige `operator_tsa` e liga a assinatura B-T/B-LT/B-LTA no pdftool, o estado
        // técnico `verification_records.ltv_status` e o re-carimbo de arquivamento. NUNCA muda o
        // perfil anunciado. `pades_ltv_advertise` (separada) é a ÚNICA que permite exibir um perfil
        // além de PAdES-B-B, e só pode ser ligada depois do checklist de LtvProfilePolicy (T2).
        'pades_ltv' => filter_var(env('ASSINAVELOX_FEATURE_PADES_LTV', false), FILTER_VALIDATE_BOOLEAN),
        'pades_ltv_advertise' => filter_var(env('ASSINAVELOX_FEATURE_PADES_LTV_ADVERTISE', false), FILTER_VALIDATE_BOOLEAN),
        // Fase 2, onda C — K-RET (docs/fase-2/retencao-e-preservacao.md). Organização (esta
        // chave E plano). Uma preservação já criada continua valendo com a flag desligada.
        'retention_policies' => filter_var(env('ASSINAVELOX_FEATURE_RETENTION_POLICIES', false), FILTER_VALIDATE_BOOLEAN),
        // Fase 2, onda D — D-API (docs/fase-2/api-v1.md). Organização (esta chave E plano). Era
        // a chave reservada da Fase 1. Desligada: `/api/v1/*` responde 404 e nenhum token autentica.
        'api_integrations' => filter_var(env('ASSINAVELOX_FEATURE_API_INTEGRATIONS', false), FILTER_VALIDATE_BOOLEAN),
        // Fase 2, onda D — D-PAY (docs/fase-2/pagamentos-e-fiscal.md). PLATAFORMA (só esta chave):
        // a cobrança é da operadora, não de um plano. `extended_payments` liga meios configuráveis,
        // estorno, cancelamento, chargeback, conciliação e o painel admin.billing.index;
        // `fiscal_invoices` liga o status de NFS-e por pagamento (a emissão real segue bloqueada).
        'extended_payments' => filter_var(env('ASSINAVELOX_FEATURE_EXTENDED_PAYMENTS', false), FILTER_VALIDATE_BOOLEAN),
        'fiscal_invoices' => filter_var(env('ASSINAVELOX_FEATURE_FISCAL_INVOICES', false), FILTER_VALIDATE_BOOLEAN),
        // Fase 2, onda D — D-HOOK (docs/fase-2/webhooks.md). Organização (esta chave E plano).
        // Desligada: nenhuma entrega é criada, nenhuma chamada sai e as rotas de gestão dão 404.
        // Condição de ativação (viabilidade R6): o teste do pino de IP precisa estar verde.
        'outbound_webhooks' => filter_var(env('ASSINAVELOX_FEATURE_OUTBOUND_WEBHOOKS', false), FILTER_VALIDATE_BOOLEAN),
        // Fase 2, onda D — D-PLAT (docs/fase-2/integracoes-no-code.md). Organização (esta chave E
        // plano). REST Hooks (assinatura dinâmica de webhooks pela API, padrão de n8n/Zapier/Make).
        // Só valem com `api_integrations` E `outbound_webhooks` também ligadas: sem a API não há
        // token; sem o motor de webhooks nada seria entregue. Desligada: as rotas dão 404.
        'rest_hooks' => filter_var(env('ASSINAVELOX_FEATURE_REST_HOOKS', false), FILTER_VALIDATE_BOOLEAN),
        // Plataforma (só a config global).
        'admin_users' => filter_var(env('ASSINAVELOX_FEATURE_ADMIN_USERS', false), FILTER_VALIDATE_BOOLEAN),
        'admin_audit' => filter_var(env('ASSINAVELOX_FEATURE_ADMIN_AUDIT', false), FILTER_VALIDATE_BOOLEAN),
        'impersonation' => filter_var(env('ASSINAVELOX_FEATURE_IMPERSONATION', false), FILTER_VALIDATE_BOOLEAN),
        // Fase 3 §3.7 — P3-RISK (docs/fase-3/antifraude.md). PLATAFORMA (só esta chave).
        // Desligada: nenhum sinal, nenhuma restrição de envio, painel e pedido de revisão 404.
        'antifraud' => filter_var(env('ASSINAVELOX_FEATURE_ANTIFRAUD', false), FILTER_VALIDATE_BOOLEAN),
        // Fase 3 §3.10 — P3-AFF (docs/fase-3/afiliados.md). PLATAFORMA (só esta chave): o programa
        // é da operadora, não de um plano. Desligada: link, portal e painel dão 404, o cadastro não
        // lê o cookie e nenhuma comissão é calculada. Parâmetros na seção `affiliates` abaixo.
        'affiliates' => filter_var(env('ASSINAVELOX_FEATURE_AFFILIATES', false), FILTER_VALIDATE_BOOLEAN),
        // Fase 3 §3.2 — F-ANCHOR (docs/fase-3/ancoras-e-ocr.md). Organização (esta chave E plano).
        // Âncoras em PDF nativo e regras por modelo: só SUGEREM campos, que o remetente revisa no
        // editor antes de o envelope ficar pronto. Desligada: rotas 404 e nada muda no preparo.
        'field_anchors' => filter_var(env('ASSINAVELOX_FEATURE_FIELD_ANCHORS', false), FILTER_VALIDATE_BOOLEAN),
        // OCR de páginas escaneadas (classe B): exige também `field_anchors` e o Tesseract no
        // servidor (seção `ocr`). Sem o binário a tela diz "OCR indisponível neste servidor".
        'ocr' => filter_var(env('ASSINAVELOX_FEATURE_OCR', false), FILTER_VALIDATE_BOOLEAN),
        // Fase 3 §3.1 — F-BULK (docs/fase-3/geracao-em-lote.md). Organização (esta chave E plano;
        // exige `templates`). Desligada: rotas do lote 404 e nenhum job gera nada. Limites na
        // seção `bulk_generation` abaixo.
        'bulk_generation' => filter_var(env('ASSINAVELOX_FEATURE_BULK_GENERATION', false), FILTER_VALIDATE_BOOLEAN),
        // Fase 3 §3.3 — F-FLOW (docs/fase-3/etapas-e-delegacao.md). Organização (esta chave E
        // plano). Desligadas: `current_order` funciona exatamente como antes, as rotas novas dão
        // 404 e o participante não vê "Delegar". Limites nas seções `flow` e `delegation` abaixo.
        'conditional_steps' => filter_var(env('ASSINAVELOX_FEATURE_CONDITIONAL_STEPS', false), FILTER_VALIDATE_BOOLEAN),
        'delegation' => filter_var(env('ASSINAVELOX_FEATURE_DELEGATION', false), FILTER_VALIDATE_BOOLEAN),
        // Fase 3 §3.3 — F-I18N (docs/fase-3/multilingue.md). Organização (esta chave E plano).
        // Página pública e e-mails ao participante em pt_BR, en e es. Desligada: tudo continua em
        // PT-BR, idêntico ao de hoje, e as rotas novas dão 404. Parâmetros em `multilingual`.
        'multilingual' => filter_var(env('ASSINAVELOX_FEATURE_MULTILINGUAL', false), FILTER_VALIDATE_BOOLEAN),
        // Fase 3 §3.9 — G-SSO (docs/fase-3/sso.md). Organização (esta chave E plano; plano
        // Empresarial). Login corporativo do USUÁRIO do painel — nunca do signatário (T1). Classe
        // B: código real testado contra provedores simulados; sem IdP registrado pelo proprietário.
        // Desligadas: rotas 404, login inalterado e nenhuma exigência de SSO vale. Parâmetros em `sso`.
        'sso_oidc' => filter_var(env('ASSINAVELOX_FEATURE_SSO_OIDC', false), FILTER_VALIDATE_BOOLEAN),
        'sso_saml' => filter_var(env('ASSINAVELOX_FEATURE_SSO_SAML', false), FILTER_VALIDATE_BOOLEAN),
        // Fase 3 §3.9 — G-EMBED (docs/fase-3/widget-embutido.md). Organização (esta chave E plano;
        // a sessão é criada pela API v1, que exige `api_integrations`). Assinatura embutida por
        // iframe + embed.js. Desligada: a rota da API, `/embed/*`, o embed.js e a tela de origens
        // respondem 404, e todo o app continua com `X-Frame-Options: DENY`. Parâmetros em
        // `embedded_signing`.
        'embedded_signing' => filter_var(env('ASSINAVELOX_FEATURE_EMBEDDED_SIGNING', false), FILTER_VALIDATE_BOOLEAN),
        // Fase 3 §3.9 — G-CONN (docs/fase-3/conectores.md). Organização (esta chave E plano).
        // `cloud_import`: importar arquivo do Google Drive/Dropbox no passo Documento do wizard.
        // `hubspot`: app HubSpot (OAuth por organização + ação de workflow "Enviar para
        // assinatura"). Classe B: sem o app registrado pelo proprietário (config/services.php) a
        // tela diz "aguardando app registrado pelo proprietário" e nenhuma chamada sai.
        // Desligadas: todas as rotas novas dão 404 e nada muda no wizard nem nas integrações.
        'cloud_import' => filter_var(env('ASSINAVELOX_FEATURE_CLOUD_IMPORT', false), FILTER_VALIDATE_BOOLEAN),
        'hubspot' => filter_var(env('ASSINAVELOX_FEATURE_HUBSPOT', false), FILTER_VALIDATE_BOOLEAN),
    ],

    /*
    | Fase 3 §3.9 — G-CONN: importação do Google Drive e do Dropbox (docs/fase-3/conectores.md
    | §4). Credenciais em config/services.php (`google_drive`, `dropbox`). O arquivo entra pelo
    | mesmo caminho de um upload (limite de `upload.max_mb`). Nenhum token persiste além da
    | importação: o do Google fica cifrado na sessão por no máximo `google_session_ttl_minutes`
    | e é revogado ao terminar; o Dropbox Chooser não usa token nenhum.
    */
    'cloud_import' => [
        // Arquivos por importação (o teto de documentos do envelope continua valendo).
        'max_files_per_import' => (int) env('ASSINAVELOX_CLOUD_IMPORT_MAX_FILES', 10),
        'connect_timeout_seconds' => (float) env('ASSINAVELOX_CLOUD_IMPORT_CONNECT_TIMEOUT', 5),
        'timeout_seconds' => (float) env('ASSINAVELOX_CLOUD_IMPORT_TIMEOUT', 60),
        // Validade do `state` + PKCE na sessão e do token do Google na sessão (minutos).
        'state_ttl_minutes' => (int) env('ASSINAVELOX_CLOUD_IMPORT_STATE_TTL', 10),
        'google_session_ttl_minutes' => (int) env('ASSINAVELOX_CLOUD_IMPORT_GOOGLE_TTL', 10),
        // Hosts aceitos no link direto do Chooser. `.dominio` = o domínio e seus subdomínios.
        // O domínio exato do link direto está NÃO CONFIRMADO: conferir em sandbox antes de ligar.
        'dropbox_download_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('ASSINAVELOX_DROPBOX_DOWNLOAD_HOSTS', 'dl.dropboxusercontent.com,.dropboxusercontent.com'))))),
        // CSP SÓ da página de importação (App\Services\CloudImport\CloudImportCsp). Hosts do
        // Picker e do Chooser NÃO CONFIRMADOS na documentação: ajustar no primeiro teste real.
        'csp' => [
            'coop' => 'same-origin-allow-popups',
            'sources' => [
                'script-src' => ['https://apis.google.com', 'https://www.dropbox.com'],
                'style-src' => ['https://www.dropbox.com'],
                'frame-src' => ['https://docs.google.com', 'https://drive.google.com'],
                'connect-src' => ['https://www.googleapis.com', 'https://content.googleapis.com'],
                'img-src' => ['https://*.googleusercontent.com', 'https://ssl.gstatic.com', 'https://www.gstatic.com'],
            ],
        ],
    ],

    /*
    | Fase 3 §3.9 — G-CONN: app HubSpot (docs/fase-3/conectores.md §5). Credenciais do app de
    | desenvolvedor em config/services.php (`hubspot`). Endpoints OAuth v3 (os v1 ficam no ar
    | até 2027-02-16); o formato exato da resposta do `introspect` está NÃO CONFIRMADO.
    */
    'hubspot' => [
        'api_base' => env('ASSINAVELOX_HUBSPOT_API_BASE', 'https://api.hubapi.com'),
        'api_hosts' => ['api.hubapi.com'],
        'authorize_url' => 'https://app.hubspot.com/oauth/authorize',
        'token_path' => env('ASSINAVELOX_HUBSPOT_TOKEN_PATH', '/oauth/2026-03/token'),
        'introspect_path' => env('ASSINAVELOX_HUBSPOT_INTROSPECT_PATH', '/oauth/2026-03/token/introspect'),
        'revoke_path' => env('ASSINAVELOX_HUBSPOT_REVOKE_PATH', '/oauth/2026-03/token/revoke'),
        // Escopos mínimos (pacotes-fase-2-3 §4.3): ler/gravar contatos e negócios. Nada `*.sensitive.*`.
        'scopes' => ['oauth', 'crm.objects.contacts.read', 'crm.objects.contacts.write', 'crm.objects.deals.read', 'crm.objects.deals.write'],
        // Janela do X-HubSpot-Request-Timestamp da assinatura v3 (a documentação pede 5 minutos).
        'signature_tolerance_seconds' => (int) env('ASSINAVELOX_HUBSPOT_SIGNATURE_TOLERANCE', 300),
        // Propriedade do negócio/contato atualizada quando o envelope muda de estado. Precisa
        // existir no portal (criada na instalação do app — pendência do proprietário).
        'status_property' => env('ASSINAVELOX_HUBSPOT_STATUS_PROPERTY', 'assinavelox_status'),
        // Documento ainda processando: novas tentativas de envio (espera entre elas, segundos).
        'send_attempts' => (int) env('ASSINAVELOX_HUBSPOT_SEND_ATTEMPTS', 20),
        'send_retry_seconds' => (int) env('ASSINAVELOX_HUBSPOT_SEND_RETRY_SECONDS', 30),
        // Tentativas de atualizar o negócio/contato (backoff do job).
        'sync_attempts' => (int) env('ASSINAVELOX_HUBSPOT_SYNC_ATTEMPTS', 5),
        // Participantes por ação (campos `participant_N_name`/`participant_N_email`).
        'max_participants' => 5,
        'queue' => env('ASSINAVELOX_HUBSPOT_QUEUE', 'default'),
    ],

    // Fase 3 §3.9 — G-EMBED: widget de assinatura embutida (docs/fase-3/widget-embutido.md §8).
    'embedded_signing' => [
        // Validade da URL de uso único (segundos). A API aceita `expires_in` até o teto.
        'url_ttl_seconds' => (int) env('ASSINAVELOX_EMBED_URL_TTL_SECONDS', 300),
        'url_ttl_max_seconds' => (int) env('ASSINAVELOX_EMBED_URL_TTL_MAX_SECONDS', 900),
        // Vida do token de execução, depois da troca (minutos). Nunca passa do prazo do envelope.
        'runtime_ttl_minutes' => (int) env('ASSINAVELOX_EMBED_RUNTIME_TTL_MINUTES', 30),
        // Sessões ainda utilizáveis (não usadas no prazo, ou ativas) por participante.
        'max_live_per_recipient' => (int) env('ASSINAVELOX_EMBED_MAX_LIVE_PER_RECIPIENT', 5),
        // Origens cadastradas por organização.
        'max_allowed_origins' => (int) env('ASSINAVELOX_EMBED_MAX_ALLOWED_ORIGINS', 20),
        // Tamanho mínimo do iframe para o widget operar (px). Abaixo disso ele se recusa.
        'min_frame_width' => (int) env('ASSINAVELOX_EMBED_MIN_FRAME_WIDTH', 320),
        'min_frame_height' => (int) env('ASSINAVELOX_EMBED_MIN_FRAME_HEIGHT', 420),
        // Tempo mínimo entre a confirmação visual aparecer e o clique final valer (ms).
        'confirm_delay_ms' => (int) env('ASSINAVELOX_EMBED_CONFIRM_DELAY_MS', 800),
    ],

    // Fase 3 §3.3 — etapas condicionais (F-FLOW). Motor declarativo fechado: estes são só os tetos.
    'flow' => [
        'max_steps' => (int) env('ASSINAVELOX_FLOW_MAX_STEPS', 10),
        'max_rules_per_step' => (int) env('ASSINAVELOX_FLOW_MAX_RULES', 10),
        // Tamanho máximo do texto literal comparado numa regra de campo.
        'max_literal_length' => (int) env('ASSINAVELOX_FLOW_MAX_LITERAL', 200),
    ],

    // Fase 3 §3.3 — delegação auditada (F-FLOW). A política (permitir, exigir confirmação,
    // participantes pessoais) é do remetente, por envelope; aqui ficam os limites da instalação.
    'delegation' => [
        // 1 = quem recebeu por delegação não delega de novo.
        'max_chain_depth' => (int) env('ASSINAVELOX_DELEGATION_MAX_CHAIN_DEPTH', 1),
        // Pedidos (de qualquer desfecho) por participante, e delegações por organização em 24 h:
        // o pedido dispara e-mail a um endereço escolhido pelo público.
        'max_requests_per_recipient' => (int) env('ASSINAVELOX_DELEGATION_MAX_PER_RECIPIENT', 3),
        'max_per_organization_per_day' => (int) env('ASSINAVELOX_DELEGATION_MAX_PER_ORG_DAY', 50),
        // Tentativas RECUSADAS ("para si mesmo", "já participa") por participante em 24 h: a
        // resposta "já participa" não pode virar consulta de e-mails de coparticipantes.
        'max_refused_attempts_per_recipient' => (int) env('ASSINAVELOX_DELEGATION_MAX_REFUSED_ATTEMPTS', 6),
        'reason_min' => 10,
        'reason_max' => 500,
    ],

    // Fase 3 §3.3 — multilíngue (F-I18N, docs/fase-3/multilingue.md). A lista de idiomas é
    // FECHADA (App\Support\Locale\SignerLocale): um valor fora dela nunca vira caminho de arquivo.
    // O texto jurídico em `en`/`es` é tradução de cortesia; a versão de referência é a PT-BR até
    // a revisão profissional por idioma. `reviewed` lista os idiomas cuja tradução jurídica já
    // foi revisada (vazio até lá: o aviso "tradução de cortesia" continua na tela).
    'multilingual' => [
        'reference_locale' => 'pt_BR',
        'reviewed_legal_locales' => array_values(array_filter(explode(',', (string) env('ASSINAVELOX_MULTILINGUAL_REVIEWED', '')))),
    ],

    /*
    | Fase 3 §3.2 — âncoras (F-ANCHOR, docs/fase-3/ancoras-e-ocr.md). Valem com
    | `features.field_anchors` ligada (global E plano). Fila própria: a busca roda o pdftool.
    */
    'field_anchors' => [
        'queue' => env('ASSINAVELOX_ANCHORS_QUEUE', 'anchors'),
        // Tempo do processo do pdftool (o pdftool também tem um orçamento interno menor).
        'process_timeout_seconds' => (int) env('ASSINAVELOX_ANCHORS_TIMEOUT', 90),
        'time_budget_seconds' => (int) env('ASSINAVELOX_ANCHORS_TIME_BUDGET', 60),
        // "Testar regras" do modelo roda DENTRO da requisição HTTP: orçamento curto (o processo
        // morre em orçamento + 30 s, sem o piso de `process_timeout_seconds`).
        'test_time_budget_seconds' => (int) env('ASSINAVELOX_ANCHORS_TEST_TIME_BUDGET', 15),
        'max_pages' => (int) env('ASSINAVELOX_ANCHORS_MAX_PAGES', 200),
        'max_file_mb' => (int) env('ASSINAVELOX_ANCHORS_MAX_FILE_MB', 50),
        'max_matches' => (int) env('ASSINAVELOX_ANCHORS_MAX_MATCHES', 300),
        // Textos literais por busca manual e regras por modelo.
        'max_literals' => (int) env('ASSINAVELOX_ANCHORS_MAX_LITERALS', 10),
        'max_rules_per_template' => (int) env('ASSINAVELOX_ANCHORS_MAX_RULES', 30),
        // Busca parada há mais que isso deixa de bloquear o preparo (worker caiu, por exemplo).
        'stale_minutes' => (int) env('ASSINAVELOX_ANCHORS_STALE_MINUTES', 15),
        // Documento de modelo HTML/DOCX ainda convertendo: a busca espera por até N × S segundos.
        'wait_attempts' => (int) env('ASSINAVELOX_ANCHORS_WAIT_ATTEMPTS', 40),
        'wait_seconds' => (int) env('ASSINAVELOX_ANCHORS_WAIT_SECONDS', 15),
    ],

    /*
    | Fase 3 §3.2 — OCR de escaneados (classe B). `driver`: tesseract | disabled | fake (o fake só
    | é aceito nos ambientes local e testing; em produção vira "indisponível"). Sem
    | `tesseract_path` apontando para um binário que responde `--list-langs` com o idioma, o OCR
    | fica indisponível — a verificação é real, nunca presumida.
    */
    'ocr' => [
        'driver' => env('ASSINAVELOX_OCR_DRIVER', 'tesseract'),
        'queue' => env('ASSINAVELOX_OCR_QUEUE', 'ocr'),
        'tesseract_path' => env('ASSINAVELOX_OCR_TESSERACT_PATH'),
        // Opcional: pasta dos .traineddata (TESSDATA_PREFIX do processo filho). Nunca segredo.
        'tessdata_prefix' => env('ASSINAVELOX_OCR_TESSDATA_PREFIX'),
        'language' => env('ASSINAVELOX_OCR_LANGUAGE', 'por'),
        'dpi' => (int) env('ASSINAVELOX_OCR_DPI', 200),
        'page_timeout_seconds' => (int) env('ASSINAVELOX_OCR_PAGE_TIMEOUT', 60),
        // Limite de páginas por passagem (custo de CPU). Páginas além disso ficam "puladas".
        'max_pages' => (int) env('ASSINAVELOX_OCR_MAX_PAGES', 20),
        'probe_timeout_seconds' => (int) env('ASSINAVELOX_OCR_PROBE_TIMEOUT', 10),
        'probe_cache_minutes' => (int) env('ASSINAVELOX_OCR_PROBE_CACHE_MINUTES', 10),
    ],

    // Fase 2 §2.15 — API REST v1 (D-API, docs/fase-2/api-v1.md). Só vale com
    // `features.api_integrations` ligada (global E plano).
    'api' => [
        'rate_limit' => [
            // Balde por token e balde pela organização do token (vários tokens dividem este).
            'per_token_per_minute' => (int) env('ASSINAVELOX_API_RATE_PER_TOKEN', 120),
            'per_organization_per_minute' => (int) env('ASSINAVELOX_API_RATE_PER_ORGANIZATION', 600),
            // Tentativas com token inválido por IP antes do 429.
            'failed_auth_per_minute' => (int) env('ASSINAVELOX_API_FAILED_AUTH_PER_MINUTE', 30),
        ],
        'idempotency' => [
            // Por quanto tempo a resposta de uma `Idempotency-Key` é repetida.
            'ttl_hours' => (int) env('ASSINAVELOX_API_IDEMPOTENCY_TTL_HOURS', 24),
            // Reserva de uma chave em processamento; vencida, outra requisição pode retomá-la.
            'lock_seconds' => (int) env('ASSINAVELOX_API_IDEMPOTENCY_LOCK_SECONDS', 60),
        ],
        'request_logs' => [
            // Retenção curta do registro mínimo das requisições (aba "Logs").
            'retention_days' => (int) env('ASSINAVELOX_API_REQUEST_LOG_RETENTION_DAYS', 30),
        ],
        'tokens' => [
            'max_active_per_organization' => (int) env('ASSINAVELOX_API_MAX_ACTIVE_TOKENS', 50),
            'max_expiration_days' => (int) env('ASSINAVELOX_API_TOKEN_MAX_EXPIRATION_DAYS', 365),
        ],
        'page_size' => [
            'default' => (int) env('ASSINAVELOX_API_PAGE_SIZE', 25),
            'max' => (int) env('ASSINAVELOX_API_PAGE_SIZE_MAX', 100),
        ],
    ],

    // Fase 2 §2.17 — REST Hooks (D-PLAT, docs/fase-2/integracoes-no-code.md). Só vale com
    // `features.rest_hooks`, `api_integrations` e `outbound_webhooks` ligadas. O teto de
    // endpoints por organização (`webhooks.max_endpoints_per_organization`) continua valendo.
    'rest_hooks' => [
        // Assinaturas ativas por token (cada Zap/cenário/fluxo costuma criar uma).
        'max_subscriptions_per_token' => (int) env('ASSINAVELOX_REST_HOOKS_MAX_PER_TOKEN', 10),
    ],

    /*
    | Fase 3 §3.9 — login corporativo por OIDC e SAML 2.0 (G-SSO, docs/fase-3/sso.md). Vale com
    | `features.sso_oidc` / `features.sso_saml` (global E plano). Toda URL do provedor (discovery,
    | JWKS, token, metadata) passa pela proteção contra SSRF dos webhooks (OutboundUrlGuard +
    | pino de IP + sem redirecionamento + sem proxy). `homologated` fica false até o proprietário
    | testar com um provedor de identidade REAL: enquanto isso a tela diz que o recurso está em
    | homologação (classe B).
    */
    'sso' => [
        'homologated' => filter_var(env('ASSINAVELOX_SSO_HOMOLOGATED', false), FILTER_VALIDATE_BOOLEAN),
        // Validade do `state`/`nonce` (OIDC) e do pedido SAML pendente.
        'flow_ttl_minutes' => (int) env('ASSINAVELOX_SSO_FLOW_TTL_MINUTES', 10),
        // Fluxos OIDC pendentes guardados por sessão (o mais antigo sai).
        'max_pending_flows' => 5,
        // Tolerância de relógio para exp/iat/nbf do id_token (segundos). SAML usa a do toolkit (180 s).
        'clock_leeway_seconds' => (int) env('ASSINAVELOX_SSO_CLOCK_LEEWAY', 60),
        // Idade máxima do `iat` do id_token (segundos).
        'id_token_max_age_seconds' => (int) env('ASSINAVELOX_SSO_ID_TOKEN_MAX_AGE', 600),
        // `at_hash` é conferido sempre que vier; `true` passa a exigi-lo.
        'require_at_hash' => filter_var(env('ASSINAVELOX_SSO_REQUIRE_AT_HASH', false), FILTER_VALIDATE_BOOLEAN),
        // Algoritmos que uma conexão OIDC pode fixar. Nunca `none`, nunca HS* (chave simétrica).
        'oidc_algorithms' => ['RS256', 'RS384', 'RS512', 'PS256', 'ES256', 'ES384'],
        'discovery_cache_minutes' => (int) env('ASSINAVELOX_SSO_DISCOVERY_CACHE_MINUTES', 60),
        // Recarga forçada do JWKS (kid desconhecido = rotação) no máximo uma vez por janela.
        'jwks_refresh_cooldown_seconds' => (int) env('ASSINAVELOX_SSO_JWKS_REFRESH_COOLDOWN', 60),
        'http' => [
            'connect_timeout_seconds' => (float) env('ASSINAVELOX_SSO_CONNECT_TIMEOUT', 5),
            'timeout_seconds' => (float) env('ASSINAVELOX_SSO_TIMEOUT', 10),
            'max_response_kb' => (int) env('ASSINAVELOX_SSO_MAX_RESPONSE_KB', 512),
            'user_agent' => 'AssinaVelox-SSO/1.0',
        ],
        'saml' => [
            // Resposta SAML (base64) acima disto é recusada antes de qualquer parse.
            'max_response_kb' => (int) env('ASSINAVELOX_SSO_SAML_MAX_RESPONSE_KB', 256),
            // IDs de assertion consumidos ficam guardados até o NotOnOrAfter + esta margem.
            'replay_margin_minutes' => (int) env('ASSINAVELOX_SSO_SAML_REPLAY_MARGIN', 10),
            // Cookie que liga o pedido SAML ao navegador que o iniciou (POST do IdP é entre sites).
            'binding_cookie' => 'av_sso_saml',
        ],
        'domains' => [
            'max_per_organization' => (int) env('ASSINAVELOX_SSO_MAX_DOMAINS', 10),
            // Registro TXT: {record_prefix}.{domínio} = "{value_prefix}={token}".
            'record_prefix' => '_assinavelox-sso',
            'value_prefix' => 'assinavelox-sso',
        ],
    ],

    /*
    | Fase 3 §3.1 — geração documental em lote (F-BULK, docs/fase-3/geracao-em-lote.md). Vale
    | com `features.bulk_generation` ligada. O plano pode APERTAR os quatro primeiros limites em
    | `plans.features.bulk_generation_limits` ({max_rows, max_file_bytes, max_concurrent_batches,
    | concurrency}); vale o menor. `queue` vazio = fila padrão (os workers precisam ouvi-la).
    */
    'bulk_generation' => [
        'max_rows' => (int) env('ASSINAVELOX_BULK_MAX_ROWS', 1000),
        'max_file_bytes' => (int) env('ASSINAVELOX_BULK_MAX_FILE_BYTES', 5 * 1024 * 1024),
        'max_concurrent_batches' => (int) env('ASSINAVELOX_BULK_MAX_CONCURRENT_BATCHES', 2),
        // Linhas da mesma organização gerando ao mesmo tempo (somando todos os lotes).
        'concurrency_per_organization' => (int) env('ASSINAVELOX_BULK_CONCURRENCY', 3),
        'max_columns' => (int) env('ASSINAVELOX_BULK_MAX_COLUMNS', 100),
        'max_cell_chars' => (int) env('ASSINAVELOX_BULK_MAX_CELL_CHARS', 5000),
        // XLSX descompactado (zip bomb), medido em streaming antes de abrir a planilha.
        'max_uncompressed_bytes' => (int) env('ASSINAVELOX_BULK_MAX_UNCOMPRESSED_BYTES', 50 * 1024 * 1024),
        'queue' => env('ASSINAVELOX_BULK_QUEUE') ?: 'default',
        // Lote em rascunho ou validado e NUNCA confirmado: planilha e linhas (com dados pessoais)
        // são descartadas depois deste prazo sem alteração (`bulk-generations:prune-unconfirmed`).
        'unconfirmed_retention_days' => (int) env('ASSINAVELOX_BULK_UNCONFIRMED_RETENTION_DAYS', 7),
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
    | Fase 3 §3.3 — vídeo curto do aceite (F-VIDEO, docs/fase-3/captura-de-video.md). Não é
    | biometria nem verificação. Guardado como veio (sem transcodificar), cifrado, com a mesma
    | retenção das fotos (`capture.retention_days` / `orphan_retention_hours`).
    */
    'capture_video' => [
        // Duração máxima padrão e o teto que o remetente pode escolher (segundos).
        'max_seconds' => (int) env('ASSINAVELOX_CAPTURE_VIDEO_MAX_SECONDS', 10),
        'max_seconds_ceiling' => (int) env('ASSINAVELOX_CAPTURE_VIDEO_MAX_SECONDS_CEILING', 30),
        // Folga sobre a duração pedida (o gravador para com atraso de milissegundos).
        'duration_tolerance_ms' => (int) env('ASSINAVELOX_CAPTURE_VIDEO_DURATION_TOLERANCE_MS', 1500),
        'max_upload_kb' => (int) env('ASSINAVELOX_CAPTURE_VIDEO_MAX_UPLOAD_KB', 8192),
        // Taxa pedida ao MediaRecorder (~1 Mbps ≈ 1,3 MB em 10 s).
        'video_bits_per_second' => (int) env('ASSINAVELOX_CAPTURE_VIDEO_BITS_PER_SECOND', 1_000_000),
        'max_uploads_per_hour' => (int) env('ASSINAVELOX_CAPTURE_VIDEO_MAX_UPLOADS_PER_HOUR', 10),
        // Validade das URLs assinadas de reprodução e download no detalhe do envelope.
        'playback_url_ttl_seconds' => (int) env('ASSINAVELOX_CAPTURE_VIDEO_PLAYBACK_URL_TTL', 120),
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
    |--------------------------------------------------------------------------
    | Fase 2, onda D §2.16 — webhooks de saída (D-HOOK)
    |--------------------------------------------------------------------------
    |
    | docs/fase-2/webhooks.md. Flag `features.outbound_webhooks` (global E plano).
    |
    | Proteção contra SSRF (App\Support\Http\OutboundUrlGuard): só https; `allow_http` só vale
    | FORA de produção (em produção é ignorado, qualquer que seja o valor); IP literal nunca;
    | DNS resolvido antes e TODOS os endereços precisam ser públicos; conexão pinada no
    | endereço validado (CURLOPT_RESOLVE); sem redirecionamento; sem proxy de ambiente.
    | `testing_allowed_cidrs` existe só para o teste de integração com servidor local e também
    | é ignorado em produção.
    |
    | Retentativas: `backoff_seconds[n-1]` é a espera depois da n-ésima tentativa falha
    | (1 min, 5 min, 30 min, 2 h, 12 h, 24 h, 24 h); na `max_attempts`-ésima a entrega vira
    | `exhausted`. Tempo esgotado conta como falha (resultado DESCONHECIDO, T5): o receptor
    | deduplica pelo X-AssinaVelox-Delivery-Id.
    */
    'webhooks' => [
        'allow_http' => filter_var(
            env('ASSINAVELOX_WEBHOOKS_ALLOW_HTTP', in_array(env('APP_ENV', 'production'), ['local', 'testing'], true)),
            FILTER_VALIDATE_BOOLEAN,
        ),
        // Portas além de 443 (https) aceitas no cadastro. Lista separada por vírgula.
        'allowed_ports' => array_values(array_filter(array_map('intval', explode(',', (string) env('ASSINAVELOX_WEBHOOKS_ALLOWED_PORTS', '443'))))),
        'testing_allowed_cidrs' => [],
        'connect_timeout_seconds' => (float) env('ASSINAVELOX_WEBHOOKS_CONNECT_TIMEOUT', 5),
        'timeout_seconds' => (float) env('ASSINAVELOX_WEBHOOKS_TIMEOUT', 10),
        // Bytes da resposta lidos e guardados no histórico (o resto nunca é lido).
        'response_excerpt_bytes' => (int) env('ASSINAVELOX_WEBHOOKS_RESPONSE_EXCERPT_BYTES', 512),
        'max_attempts' => (int) env('ASSINAVELOX_WEBHOOKS_MAX_ATTEMPTS', 8),
        'backoff_seconds' => [60, 300, 1800, 7200, 43200, 86400, 86400],
        // Tentativas falhas SEGUIDAS (qualquer entrega) até o endpoint ser pausado com aviso.
        'pause_after_consecutive_failures' => (int) env('ASSINAVELOX_WEBHOOKS_PAUSE_AFTER_FAILURES', 20),
        // Janela recomendada ao receptor para aceitar o X-AssinaVelox-Timestamp.
        'signature_tolerance_seconds' => 300,
        // Convivência do segredo anterior depois da rotação (0 = encerra na hora).
        'secret_rotation_overlap_hours' => (int) env('ASSINAVELOX_WEBHOOKS_ROTATION_OVERLAP_HOURS', 24),
        'max_rotation_overlap_hours' => 168,
        'max_endpoints_per_organization' => (int) env('ASSINAVELOX_WEBHOOKS_MAX_ENDPOINTS', 10),
        // Se o job inicial se perder, a varredura `webhooks:retry` tenta depois disto.
        'initial_fallback_seconds' => 60,
        'retry_batch_size' => (int) env('ASSINAVELOX_WEBHOOKS_RETRY_BATCH_SIZE', 200),
        // Entregas encerradas (entregue, esgotada, cancelada) saem do histórico depois disto.
        'retention_days' => (int) env('ASSINAVELOX_WEBHOOKS_RETENTION_DAYS', 30),
        // `default` para funcionar com qualquer worker; recomenda-se uma fila própria.
        'queue' => env('ASSINAVELOX_WEBHOOKS_QUEUE', 'default'),
        'user_agent' => 'AssinaVelox-Webhooks/1.0',
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
    |--------------------------------------------------------------------------
    | Fase 3, onda E §3.6 — PAdES de longo prazo (P3-LTV)
    |--------------------------------------------------------------------------
    |
    | docs/fase-3/longo-prazo.md. Só vale com `features.pades_ltv` (que exige `operator_tsa`).
    | O carimbo vem da TSA da OPERADORA (não é ICP-Brasil, T3). O nível técnico alcançado fica em
    | `verification_records.ltv_status`; o perfil exibido continua PAdES-B-B (T2).
    |
    | Revogação: CRL/OCSP entregues por ARQUIVO (`crl_paths`, `ocsp_paths`) ou, em produção,
    | buscados na rede com `allow_fetching` (rede de saída até as ACs — viabilidade §4.3 item 19).
    | `revocation_mode` só aceita `hard-fail` ou `require`: nunca `soft-fail` (R5).
    |
    */
    'ltv' => [
        // Nível técnico pedido ao pdftool: B-T | B-LT | B-LTA. A falha de uma etapa degrada de
        // forma explícita e registrada (ltv_operations.degradations).
        'level' => env('ASSINAVELOX_LTV_LEVEL', 'B-LTA'),
        // Raízes para validar as cadeias antes de embutir (PEM/DER separados por ";"). Vazio:
        // usa `pdftool.trust_roots` + `tsa.trust_roots`.
        'trust_roots' => array_values(array_filter(array_map('trim', explode(';', (string) env('ASSINAVELOX_LTV_TRUST_ROOTS', ''))))),
        'crl_paths' => array_values(array_filter(array_map('trim', explode(';', (string) env('ASSINAVELOX_LTV_CRL_PATHS', ''))))),
        'ocsp_paths' => array_values(array_filter(array_map('trim', explode(';', (string) env('ASSINAVELOX_LTV_OCSP_PATHS', ''))))),
        'allow_fetching' => filter_var(env('ASSINAVELOX_LTV_ALLOW_FETCHING', false), FILTER_VALIDATE_BOOLEAN),
        'revocation_mode' => env('ASSINAVELOX_LTV_REVOCATION_MODE', 'hard-fail'),
        'timeout_seconds' => (int) env('ASSINAVELOX_LTV_TIMEOUT_SECONDS', 180),
        'refresh' => [
            // Re-carimbo agendado N dias antes do vencimento do certificado da TSA do último
            // carimbo de arquivamento.
            'margin_days' => (int) env('ASSINAVELOX_LTV_REFRESH_MARGIN_DAYS', 30),
            'batch_size' => (int) env('ASSINAVELOX_LTV_REFRESH_BATCH', 100),
            'queue' => env('ASSINAVELOX_LTV_REFRESH_QUEUE', 'default'),
            // Espera pelo lock do envelope (o mesmo das assinaturas de participante).
            'lock_wait_seconds' => (int) env('ASSINAVELOX_LTV_LOCK_WAIT_SECONDS', 120),
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

        // -- Fase 2, onda D (flag `extended_payments`; desligada, nada abaixo é lido) -----------
        // Famílias oferecidas no Checkout Pro: `pix`, `boleto`, `card` (lista por vírgula). Uma
        // família só é oferecida quando está aqui E, se já houver consulta a GET /v1/payment_methods,
        // quando a conta tem um meio dela com status `active` (nada de supor disponibilidade).
        'enabled_methods' => env('MERCADOPAGO_ENABLED_METHODS', 'pix,boleto,card'),
        // `date_of_expiration` da preferência (pagamentos offline e Pix), em horas. A doc do Checkout
        // Pro recomenda ao menos 3 dias; o valor é limitado a 1 h..30 dias.
        'offline_expiration_hours' => (int) env('MERCADOPAGO_OFFLINE_EXPIRATION_HOURS', 72),
        // `user_id` do vendedor, enviado como X-Caller-Id em GET /v1/chargebacks/{id}. A
        // obrigatoriedade para conta própria é NÃO CONFIRMADA; sem valor, o cabeçalho não vai.
        'seller_user_id' => env('MERCADOPAGO_SELLER_USER_ID'),
        // Conciliação diária por GET /v1/payments/search (janela por date_last_updated). O teto de
        // `limit`/`offset` é NÃO CONFIRMADO: páginas de 30 (padrão documentado) e no máximo N páginas.
        'reconciliation' => [
            'window_days' => (int) env('MERCADOPAGO_RECONCILIATION_WINDOW_DAYS', 2),
            'page_size' => (int) env('MERCADOPAGO_RECONCILIATION_PAGE_SIZE', 30),
            'max_pages' => (int) env('MERCADOPAGO_RECONCILIATION_MAX_PAGES', 20),
        ],
        // Assinaturas recorrentes (preapproval) — classe B. `disabled` (padrão) | `simulated`
        // (simulador identificado, só fora de produção). Não existe driver real: faltam conta
        // vendedora real, decisão sobre Q20 e confirmação dos meios aceitos.
        'preapproval' => [
            'driver' => env('MERCADOPAGO_PREAPPROVAL_DRIVER', 'disabled'),
        ],
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

        // -- Fase 2, onda D (flag `extended_payments`) ------------------------------------------
        // Política de estorno (decisão pendente §4.5 item 28 — adotada a CONSERVADORA, registrada
        // como decisão do proprietário em docs/fase-2/pagamentos-e-fiscal.md §4):
        //  - total: cancela a renovação do ciclo pago e a organização volta ao Grátis ao FIM do
        //    período, sem passar por `past_due`; a cota já consumida não é devolvida;
        //  - parcial: não altera plano nem cota.
        // `initiators`: quem pode pedir — `platform_admin` sempre; `owner` só se listado aqui (e só
        // estorno TOTAL, dentro de `owner_window_days` da aprovação).
        'refunds' => [
            'initiators' => env('ASSINAVELOX_BILLING_REFUND_INITIATORS', 'platform_admin'),
            'owner_window_days' => (int) env('ASSINAVELOX_BILLING_OWNER_REFUND_WINDOW_DAYS', 7),
            // Prazo documentado do provedor: 180 dias a partir da aprovação.
            'max_age_days' => (int) env('ASSINAVELOX_BILLING_REFUND_MAX_AGE_DAYS', 180),
        ],
        // Alertas da cobrança (chargeback, divergência de conciliação). Sempre vão para o log com
        // `alert=`; com um e-mail aqui, também para a caixa da equipe.
        'alert_email' => env('ASSINAVELOX_BILLING_ALERT_EMAIL'),
    ],

    /*
    | Fase 2, onda D §2.21 — NFS-e (flag `fiscal_invoices`, classe B). `provider`:
    | `none` (padrão: nenhum provedor; cada pagamento mostra "não emitida — integração fiscal
    | pendente"), `simulated` (simulador identificado, só fora de produção; NUNCA emite nota) ou
    | `sefin_nacional` (adaptador do Sistema Nacional NFS-e, DESABILITADO até a operadora entregar
    | CNPJ, município IBGE, regime, cadastro no CNC, certificado para mTLS/XMLDSig e parecer contábil).
    */
    'fiscal' => [
        'provider' => env('ASSINAVELOX_FISCAL_PROVIDER', 'none'),
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

    /*
    |--------------------------------------------------------------------------
    | Fase 3 §3.5 — PDF assinado no portal gov.br e DEVOLVIDO (P3-GOV)
    |--------------------------------------------------------------------------
    |
    | docs/fase-3/gov-br.md. Flag `govbr_return`: vale só com `return_enabled`, com a trava
    | `finalizer_integration` (ligue SÓ depois que o EnvelopeFinalizer consultar
    | GovBrReturnStage — §8 do documento) e com `plans.features.govbr_return`. Nasce DESLIGADA.
    |
    | A API direta do gov.br é classe C (BLOQUEADA: o AssinaVelox não é elegível) — não há
    | adaptador; `api.provider` fica `none` (App\Integrations\GovBr\GovBrSignatureProvider).
    |
    | Âncoras: SEM elas, a devolução aceita é rotulada "assinatura digital de terceiro, cadeia
    | não verificada" — nunca "gov.br". Para o rótulo gov.br: arquivo(s) da raiz em
    | `trust_roots` E o SHA-256 de CADA certificado em `trust_root_fingerprints` (origem e data
    | de verificação anotadas junto do valor, na configuração versionada da implantação).
    |
    */
    'govbr' => [
        'return_enabled' => filter_var(env('ASSINAVELOX_FEATURE_GOVBR_RETURN', false), FILTER_VALIDATE_BOOLEAN),
        'finalizer_integration' => filter_var(env('ASSINAVELOX_GOVBR_FINALIZER_INTEGRATION', false), FILTER_VALIDATE_BOOLEAN),
        'portal_url' => env('ASSINAVELOX_GOVBR_PORTAL_URL', 'https://assinador.iti.br'),
        'validator_url' => env('ASSINAVELOX_GOVBR_VALIDATOR_URL', 'https://validar.iti.gov.br'),
        // Reserva da revisão entregue (baixar → assinar no portal → devolver).
        'reservation_ttl_minutes' => (int) env('ASSINAVELOX_GOVBR_RESERVATION_TTL_MINUTES', 120),
        // Prazo total depois que o documento fica pronto; vencido, o envelope segue sem a assinatura.
        'application_window_minutes' => (int) env('ASSINAVELOX_GOVBR_WINDOW_MINUTES', 4320),
        // O portal aceita arquivos de até 100 MB (docs/integracoes/gov-br-assinatura.md §6.1).
        'max_upload_mb' => (int) env('ASSINAVELOX_GOVBR_MAX_UPLOAD_MB', 100),
        'verify_timeout_seconds' => (int) env('ASSINAVELOX_GOVBR_VERIFY_TIMEOUT', 180),
        'lock_wait_seconds' => (int) env('ASSINAVELOX_GOVBR_LOCK_WAIT_SECONDS', 20),
        'trust_roots' => array_values(array_filter(array_map('trim', explode(';', (string) env('ASSINAVELOX_GOVBR_TRUST_ROOTS', ''))))),
        'trust_root_fingerprints' => array_values(array_filter(array_map('trim', explode(';', (string) env('ASSINAVELOX_GOVBR_TRUST_ROOT_FINGERPRINTS', ''))))),
        // Com CPF informado pelo participante, o certificado PRECISA trazer um CPF que confira.
        'require_holder_cpf' => filter_var(env('ASSINAVELOX_GOVBR_REQUIRE_HOLDER_CPF', true), FILTER_VALIDATE_BOOLEAN),
        'accept_test_certificates' => filter_var(
            env('ASSINAVELOX_GOVBR_ACCEPT_TEST_CERTIFICATES', env('APP_ENV', 'production') !== 'production'),
            FILTER_VALIDATE_BOOLEAN,
        ),
        // Mudanças admitidas na revisão da assinatura. ANNOTATIONS só depois de medir o carimbo
        // visual do portal numa fixture real (NÃO CONFIRMADO).
        'permitted_modification_levels' => array_values(array_filter(array_map('trim', explode(',', (string) env('ASSINAVELOX_GOVBR_PERMITTED_LEVELS', 'NONE,FORM_FILLING'))))),
        'api' => [
            // Classe C: nenhum provedor. Não existe outro valor válido.
            'provider' => 'none',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fase 3 §3.4 — assinatura externa por componente local A3 (P3-EXT)
    |--------------------------------------------------------------------------
    |
    | docs/fase-3/assinatura-externa-a3.md. `enabled` é o interruptor GLOBAL da flag
    | `a3_signing`; a organização também precisa de `plans.features.a3_signing`. Nasce
    | DESLIGADA: com ela desligada, nada muda (rotas 404, finalização da Fase 1/2).
    |
    | O servidor NUNCA recebe a chave do token: prepara a revisão pendente, entrega só o
    | digest e recebe de volta a assinatura bruta (+ certificado) ou um CMS pronto.
    |
    */
    'external_signing' => [
        'enabled' => filter_var(env('ASSINAVELOX_FEATURE_A3_SIGNING', false), FILTER_VALIDATE_BOOLEAN),
        // Prazo curto da reserva de revisão (digest entregue → assinatura recebida).
        'pending_ttl_minutes' => (int) env('ASSINAVELOX_A3_PENDING_TTL_MINUTES', 10),
        // Espera pelo lock do envelope dentro de uma requisição HTTP (o mesmo lock do §2.12).
        'lock_wait_seconds' => (int) env('ASSINAVELOX_A3_LOCK_WAIT_SECONDS', 10),
        // Revisões pendentes e estado mínimo do pdftool (fora de public/; compartilhado entre
        // servidores web em produção). Apagados ao consumir, expirar ou descartar.
        'pending_path' => env('ASSINAVELOX_A3_PENDING_PATH') ?: storage_path('app/private/external-signing'),
        // Espaço reservado para o CMS no PDF (bytes).
        'bytes_reserved' => (int) env('ASSINAVELOX_A3_BYTES_RESERVED', 16384),
        'max_certificate_kb' => (int) env('ASSINAVELOX_A3_MAX_CERTIFICATE_KB', 32),
        'max_chain_certificates' => (int) env('ASSINAVELOX_A3_MAX_CHAIN', 6),
        'max_cms_kb' => (int) env('ASSINAVELOX_A3_MAX_CMS_KB', 48),
        // Âncoras de confiança FIXADAS por impressão digital: "caminho|sha256;caminho|sha256".
        // Âncora cujo arquivo não bate com a impressão digital é IGNORADA (e registrada). Sem
        // âncora válida, a cadeia é "não verificada" — nunca ICP-Brasil.
        'trust_anchors' => array_values(array_filter(array_map(
            static function (string $item): ?array {
                $parts = array_map('trim', explode('|', $item));

                return count($parts) === 2 && $parts[0] !== '' && $parts[1] !== '' ? ['path' => $parts[0], 'sha256' => strtolower($parts[1])] : null;
            },
            array_filter(array_map('trim', explode(';', (string) env('ASSINAVELOX_A3_TRUST_ANCHORS', '')))),
        ))),
        // Exigir cadeia validada até uma âncora na preparação (desligado: a cadeia é rotulada).
        'require_trusted_chain' => filter_var(env('ASSINAVELOX_A3_REQUIRE_TRUSTED_CHAIN', false), FILTER_VALIDATE_BOOLEAN),
        // Certificados de TESTE: aceitos fora de produção; sempre rotulados como teste.
        'accept_test_certificates' => filter_var(
            env('ASSINAVELOX_A3_ACCEPT_TEST_CERTIFICATES', env('APP_ENV', 'production') !== 'production'),
            FILTER_VALIDATE_BOOLEAN,
        ),
        'reason' => env('ASSINAVELOX_A3_REASON', 'Assinatura do participante com certificado em componente externo'),
        'components' => [
            // FakeLocalSigner: PKCS#12 de TESTE no servidor. Só nos ambientes listados, nunca em
            // produção; tudo o que produz é rotulado "simulado — nenhum token foi usado".
            'simulated' => [
                'enabled' => filter_var(env('ASSINAVELOX_A3_SIMULATOR_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
                'allowed_environments' => ['local', 'testing'],
                'pfx_path' => env('ASSINAVELOX_A3_SIMULATOR_PFX'),
                // NOME da variável de ambiente com a senha do PKCS#12 de teste (nunca o valor).
                'pass_env' => env('ASSINAVELOX_A3_SIMULATOR_PASS_ENV', 'ASSINAVELOX_A3_SIMULATOR_PFX_PASS'),
            ],
            // NexuLocalSigner: produção DESABILITADA (NexuLocalSigner::PRODUCTION_ENABLED = false).
            // Os endereços são os da API local do fork 1.25, para a UI orientar o participante.
            'nexu' => [
                'http_base' => env('ASSINAVELOX_A3_NEXU_HTTP', 'http://127.0.0.1:9795'),
                'https_base' => env('ASSINAVELOX_A3_NEXU_HTTPS', 'https://127.0.0.1:9895'),
                'minimum_version' => env('ASSINAVELOX_A3_NEXU_MIN_VERSION', '1.25.0'),
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fase 3 §3.7 — antifraude com revisão humana (P3-RISK, docs/fase-3/antifraude.md)
    |--------------------------------------------------------------------------
    |
    | Só vale com `features.antifraud` ligada. As REGRAS são um conjunto fechado em código
    | (App\Services\Risk\RiskRule); aqui só se ajustam janela, limiar e pontuação. A ação
    | automática máxima é `restricted` = envio de novos envelopes suspenso até revisão humana.
    | Aceites, evidências, leitura, download e assinatura de envelopes já enviados nunca mudam.
    |
    */
    'risk' => [
        // false = modo de observação: grava sinais e abre casos, mas nunca suspende o envio sozinho.
        'auto_restrict' => filter_var(env('ASSINAVELOX_RISK_AUTO_RESTRICT', true), FILTER_VALIDATE_BOOLEAN),
        // Soma das pontuações dos sinais ainda não revisados (dentro de `lookback_days`). Para
        // `restrict` só contam regras que podem suspender o envio (RiskRule::maxStatus()).
        'thresholds' => [
            'watch' => (int) env('ASSINAVELOX_RISK_WATCH_SCORE', 30),
            'restrict' => (int) env('ASSINAVELOX_RISK_RESTRICT_SCORE', 70),
        ],
        'lookback_days' => (int) env('ASSINAVELOX_RISK_LOOKBACK_DAYS', 30),
        // Chave do HMAC dos sujeitos (link, rede, dispositivo). Vazia: derivada da APP_KEY.
        'subject_key' => env('ASSINAVELOX_RISK_SUBJECT_KEY'),
        // Contagens de cadastro por rede/dispositivo (só o HMAC) são apagadas depois disto.
        'observation_retention_days' => (int) env('ASSINAVELOX_RISK_OBSERVATION_RETENTION_DAYS', 30),
        // Lista de confiança: ULIDs de organizações (separados por vírgula) cujos sinais são
        // gravados, mas que nunca mudam de estado nem abrem caso automaticamente.
        'trusted_organizations' => array_values(array_filter(array_map('trim', explode(',', (string) env('ASSINAVELOX_RISK_TRUSTED_ORGANIZATIONS', ''))))),
        // Pedido de revisão (LGPD art. 20): tamanho do texto e prazo interno de resposta exibido.
        'appeal' => [
            'max_message' => (int) env('ASSINAVELOX_RISK_APPEAL_MAX_MESSAGE', 2000),
            'response_days' => (int) env('ASSINAVELOX_RISK_APPEAL_RESPONSE_DAYS', 5),
        ],
        'rules' => [
            'new_org_send_spike' => [
                'score' => (int) env('ASSINAVELOX_RISK_SPIKE_SCORE', 40),
                'window_minutes' => (int) env('ASSINAVELOX_RISK_SPIKE_WINDOW_MINUTES', 1440),
                'threshold' => (int) env('ASSINAVELOX_RISK_SPIKE_THRESHOLD', 30),
                'organization_max_age_days' => (int) env('ASSINAVELOX_RISK_SPIKE_MAX_AGE_DAYS', 7),
            ],
            'delivery_failure_rate' => [
                'score' => (int) env('ASSINAVELOX_RISK_DELIVERY_SCORE', 30),
                'window_minutes' => (int) env('ASSINAVELOX_RISK_DELIVERY_WINDOW_MINUTES', 1440),
                'min_attempts' => (int) env('ASSINAVELOX_RISK_DELIVERY_MIN_ATTEMPTS', 20),
                'rate' => (float) env('ASSINAVELOX_RISK_DELIVERY_RATE', 0.3),
            ],
            'code_brute_force' => [
                'score' => (int) env('ASSINAVELOX_RISK_BRUTE_FORCE_SCORE', 20),
                'window_minutes' => (int) env('ASSINAVELOX_RISK_BRUTE_FORCE_WINDOW_MINUTES', 60),
                'per_link' => (int) env('ASSINAVELOX_RISK_BRUTE_FORCE_PER_LINK', 10),
                'per_ip' => (int) env('ASSINAVELOX_RISK_BRUTE_FORCE_PER_IP', 25),
            ],
            'external_recipients_burst' => [
                'score' => (int) env('ASSINAVELOX_RISK_BURST_SCORE', 50),
                'window_minutes' => (int) env('ASSINAVELOX_RISK_BURST_WINDOW_MINUTES', 1440),
                'threshold' => (int) env('ASSINAVELOX_RISK_BURST_THRESHOLD', 150),
            ],
            'payment_chargeback' => [
                'score' => (int) env('ASSINAVELOX_RISK_CHARGEBACK_SCORE', 40),
                'window_days' => (int) env('ASSINAVELOX_RISK_CHARGEBACK_WINDOW_DAYS', 90),
                'threshold' => (int) env('ASSINAVELOX_RISK_CHARGEBACK_THRESHOLD', 1),
            ],
            'serial_signup' => [
                'score' => (int) env('ASSINAVELOX_RISK_SIGNUP_SCORE', 30),
                'window_minutes' => (int) env('ASSINAVELOX_RISK_SIGNUP_WINDOW_MINUTES', 1440),
                'threshold' => (int) env('ASSINAVELOX_RISK_SIGNUP_THRESHOLD', 3),
            ],
            // Gravado pelo programa de afiliados (§3.10) via RiskSignals::record().
            'affiliate_self_referral' => [
                'score' => (int) env('ASSINAVELOX_RISK_SELF_REFERRAL_SCORE', 50),
                'window_minutes' => (int) env('ASSINAVELOX_RISK_SELF_REFERRAL_WINDOW_MINUTES', 43200),
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fase 3 §3.10 — programa de afiliados (P3-AFF, docs/fase-3/afiliados.md)
    |--------------------------------------------------------------------------
    |
    | Só vale com `features.affiliates` ligada. O sistema CALCULA comissões e monta lotes; o
    | repasse é feito FORA da plataforma e registrado manualmente. Os valores abaixo são
    | PROVISÓRIOS até a decisão do proprietário (taxas, janela, prazo de estorno, período de
    | comissão, tratamento tributário e contratual — docs/fase-3/afiliados.md §8).
    |
    */
    'affiliates' => [
        // Cookie de atribuição (cifrado e autenticado pelo EncryptCookies; guarda código + hora do clique).
        'cookie_name' => env('ASSINAVELOX_AFFILIATES_COOKIE', 'av_affiliate_ref'),
        // Janela do clique até o cadastro (roadmap §3.10: "ex.: 60 dias").
        'attribution_window_days' => (int) env('ASSINAVELOX_AFFILIATES_WINDOW_DAYS', 60),
        // first_touch (padrão, recomendado — docs §3) ou last_touch.
        'attribution_model' => env('ASSINAVELOX_AFFILIATES_ATTRIBUTION_MODEL', 'first_touch'),
        // Meses, desde a atribuição, em que os pagamentos da organização geram comissão (0 = sem prazo).
        'commission_months' => (int) env('ASSINAVELOX_AFFILIATES_COMMISSION_MONTHS', 12),
        // Prazo de estorno: dias em que a comissão fica pendente depois do pagamento aprovado.
        'approval_hold_days' => (int) env('ASSINAVELOX_AFFILIATES_HOLD_DAYS', 30),
        // Taxas em pontos-base (1000 = 10%). A taxa é por afiliado; esta é a sugerida na aprovação.
        'default_rate_bp' => (int) env('ASSINAVELOX_AFFILIATES_DEFAULT_RATE_BP', 1000),
        'max_rate_bp' => (int) env('ASSINAVELOX_AFFILIATES_MAX_RATE_BP', 5000),
        // Saldo mínimo por afiliado para entrar num lote (centavos).
        'min_payout_cents' => (int) env('ASSINAVELOX_AFFILIATES_MIN_PAYOUT_CENTS', 5000),
        // Pagamentos de sandbox não geram comissão em produção.
        'include_sandbox_payments' => filter_var(env('ASSINAVELOX_AFFILIATES_INCLUDE_SANDBOX', false), FILTER_VALIDATE_BOOLEAN),
        'currencies' => ['BRL'],
        // Versão dos termos do programa aceitos na candidatura (texto pendente do jurídico).
        'terms_version' => env('ASSINAVELOX_AFFILIATES_TERMS_VERSION', 'afiliados-rascunho-2026-09'),
        // Para estes domínios a regra "mesmo domínio corporativo" não se aplica.
        'public_email_domains' => [
            'gmail.com', 'googlemail.com', 'outlook.com', 'outlook.com.br', 'hotmail.com', 'hotmail.com.br',
            'live.com', 'msn.com', 'yahoo.com', 'yahoo.com.br', 'icloud.com', 'me.com', 'uol.com.br',
            'bol.com.br', 'terra.com.br', 'ig.com.br', 'globo.com', 'globomail.com', 'r7.com',
            'proton.me', 'protonmail.com', 'zoho.com', 'aol.com', 'gmx.com', 'yandex.com',
        ],
    ],
];
