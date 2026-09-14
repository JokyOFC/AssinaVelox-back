<?php

use App\Integrations\IntegrationsServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\AuthServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Services\Affiliates\AffiliatesServiceProvider;
use App\Services\Risk\RiskServiceProvider;
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
];
