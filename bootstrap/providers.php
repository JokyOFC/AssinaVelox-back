<?php

use App\Integrations\IntegrationsServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\AuthServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Services\Affiliates\AffiliatesServiceProvider;
use App\Services\HubSpot\HubSpotServiceProvider;
use App\Services\Risk\RiskServiceProvider;
use App\Services\Sso\SsoServiceProvider;
use App\Services\Webhooks\WebhooksServiceProvider;

return [
    AppServiceProvider::class,
    AuthServiceProvider::class,
    FortifyServiceProvider::class,
    HorizonServiceProvider::class,
    IntegrationsServiceProvider::class,
    WebhooksServiceProvider::class,
    RiskServiceProvider::class,
    // Fase 3 §3.10 (integração I-3A): gancho de pagamento e `affiliates:settle`. Com a flag
    // `affiliates` desligada (o padrão) o gancho e o comando não fazem nada.
    AffiliatesServiceProvider::class,
    // Fase 3 §3.9 (G-CONN): gancho da trilha → atualização do negócio/contato no HubSpot. Com a
    // flag `hubspot` desligada (o padrão) o gancho sai antes de qualquer consulta.
    HubSpotServiceProvider::class,
    // Fase 3 §3.9 (G-SSO): limitadores nomeados, listener do 2FA pós-SSO e `sso:prune`. Com as
    // flags `sso_oidc`/`sso_saml` desligadas (o padrão) nada muda.
    SsoServiceProvider::class,
];
