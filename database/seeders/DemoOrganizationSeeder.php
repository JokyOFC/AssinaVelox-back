<?php

namespace Database\Seeders;

use App\Enums\AccessLinkPurpose;
use App\Enums\AuditEventType;
use App\Enums\AuthMethod;
use App\Enums\DeliveryChannel;
use App\Enums\DeliveryPurpose;
use App\Enums\DocumentSourceType;
use App\Enums\DocumentVersionKind;
use App\Enums\EnvelopeStatus;
use App\Enums\FolderAccessLevel;
use App\Enums\MembershipRole;
use App\Enums\PaymentStatus;
use App\Enums\Permission;
use App\Enums\RecipientStatus;
use App\Enums\SigningOrder;
use App\Enums\SigningSessionStatus;
use App\Models\Affiliate;
use App\Models\ApiToken;
use App\Models\AuditEvent;
use App\Models\AuthChallenge;
use App\Models\CertificateReference;
use App\Models\DeliveryAttempt;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Envelope;
use App\Models\Folder;
use App\Models\FolderPermission;
use App\Models\Membership;
use App\Models\MembershipInvitation;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\Plan;
use App\Models\PlanConsumption;
use App\Models\Recipient;
use App\Models\RecipientAccessLink;
use App\Models\Referral;
use App\Models\RetentionPolicy;
use App\Models\Role;
use App\Models\SignatureAcceptance;
use App\Models\SigningField;
use App\Models\SigningFieldValue;
use App\Models\SigningSession;
use App\Models\SsoConnection;
use App\Models\SsoDomain;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\Template;
use App\Models\User;
use App\Models\VerificationRecord;
use App\Models\WebhookEndpoint;
use App\Services\Affiliates\CommissionLedger;
use App\Services\Anchors\TemplateAnchorRules;
use App\Services\Branding\BrandingManager;
use App\Services\Embed\AllowedOrigins;
use App\Services\Envelopes\Delegation\DelegationPolicy;
use App\Services\Envelopes\Steps\SigningStepPlan;
use App\Services\Identity\IdentityVideos;
use App\Services\Identity\Models\IdentityVideoRequirement;
use App\Services\PublicForms\PublicFormManager;
use App\Services\PublicForms\PublicFormSchema;
use App\Services\Retention\LegalHolds;
use App\Services\Retention\LegalHoldScope;
use App\Services\Risk\RiskSignals;
use App\Services\Signing\Certificates\ParticipantCertificateTool;
use App\Services\Signing\Channels\SenderPins;
use App\Services\Sso\SsoConnectionStatus;
use App\Services\Sso\SsoProtocol;
use App\Services\Tags\TagColor;
use App\Services\Tags\TagManager;
use App\Services\Templates\TemplateManager;
use App\Services\Timestamp\TsaToolRunner;
use App\Services\Webhooks\WebhookSignature;
use App\Support\CurrentOrganization;
use App\Support\PermissionsSystemRoles;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Throwable;

/**
 * Duas organizações de demonstração com dados fictícios coerentes. Só roda em local/testing.
 *
 * Credenciais (senha "password" para todos):
 *   - owner@horizonte.demo / admin@horizonte.demo / operador@horizonte.demo (plano Profissional sandbox)
 *   - owner@vega.demo / operador@vega.demo (plano Grátis)
 */
class DemoOrganizationSeeder extends Seeder
{
    public const PASSWORD = 'password';

    /** @var array<int, array{name: string, email: string}> */
    private const SIGNERS = [
        ['name' => 'Ana Beatriz Rocha', 'email' => 'ana.rocha@exemplo.com.br'],
        ['name' => 'Bruno Carvalho Lima', 'email' => 'bruno.lima@exemplo.com.br'],
        ['name' => 'Carla Mendes Furtado', 'email' => 'carla.furtado@exemplo.com.br'],
        ['name' => 'Diego Nogueira Prado', 'email' => 'diego.prado@exemplo.com.br'],
        ['name' => 'Elisa Martins Barros', 'email' => 'elisa.barros@exemplo.com.br'],
        ['name' => 'Fábio Teixeira Ramos', 'email' => 'fabio.ramos@exemplo.com.br'],
        ['name' => 'Gabriela Souza Pinto', 'email' => 'gabriela.pinto@exemplo.com.br'],
        ['name' => 'Henrique Alves Castro', 'email' => 'henrique.castro@exemplo.com.br'],
    ];

    /** Chaves de `plans.features` da Fase 2 (onda A e onda B). */
    private const PHASE2_PLAN_FEATURES = [
        'templates', 'multi_document', 'participant_roles', 'reminders',
        'custom_roles', 'tags', 'reports', 'audit_log',
        // Onda B (docs/fase-2/onda-b-relatorio.md §6). `cpf_lookup` e `cnpj_lookup` ficam de
        // fora: a consulta de CPF não tem serviço configurado e a de CNPJ acessa a rede.
        'sms_whatsapp', 'pin_auth', 'sender_domains', 'branding', 'cpf_field',
        'identity_capture', 'in_person', 'batch_signing', 'public_forms',
        // Onda C (docs/fase-2/onda-c-relatorio.md §6). `operator_tsa` e `pades_bt` são da
        // plataforma (só o .env), não de plano.
        'participant_a1', 'dossier_export', 'retention_policies',
        // Onda D (docs/fase-2/entrega-fase-2.md §5). `extended_payments` e `fiscal_invoices`
        // são da plataforma (só o .env), não de plano.
        'api_integrations', 'outbound_webhooks', 'rest_hooks',
    ];

    /**
     * Fase 3, parte 1 (docs/fase-3/parte-1-relatorio.md §5): itens de PLANO — ligados na
     * Horizonte, desligados na Vega. Antifraude, afiliados e PAdES de longo prazo são da
     * plataforma (só o .env). A interface só aparece com os interruptores globais ligados.
     */
    private const PHASE3_PART1_PLAN_FEATURES = ['a3_signing', 'govbr_return'];

    /**
     * Fase 3, parte 2 — onda F (docs/fase-3/onda-f-relatorio.md §5): itens de PLANO — ligados na
     * Horizonte, desligados na Vega. A interface só aparece com os interruptores globais ligados.
     */
    private const PHASE3_WAVE_F_PLAN_FEATURES = [
        'bulk_generation', 'field_anchors', 'ocr', 'conditional_steps', 'delegation', 'identity_video', 'multilingual',
    ];

    /**
     * Fase 3 — onda G (docs/fase-3/onda-g-relatorio.md §5): itens de PLANO — ligados na Horizonte,
     * desligados na Vega. A interface só aparece com os interruptores globais ligados.
     */
    private const PHASE3_WAVE_G_PLAN_FEATURES = ['embedded_signing', 'sso_oidc', 'sso_saml', 'cloud_import', 'hubspot'];

    /**
     * Fase 4 §4.1 (docs/fase-4/verificacao-facial.md): verificação facial com documento por
     * provedor externo — item de PLANO, ligado na Horizonte e desligado na Vega. Só vale junto
     * com `identity_capture` (já na onda B) e com o interruptor global ligado; o adaptador real
     * ainda depende das credenciais da Verifiky no `.env`.
     */
    private const PHASE4_PLAN_FEATURES = ['identity_verification'];

    /** Origem de EXEMPLO do widget na Horizonte (domínio reservado `.example`: não é um site real). */
    public const DEMO_EMBED_ORIGIN = 'https://portal.imobiliaria-horizonte.example';

    /** Diretório (em storage/app/private) do PKCS#12 de TESTE do simulador de componente local. */
    public const DEMO_PHASE3_DIR = 'demo/fase-3';

    /**
     * Senha do PKCS#12 de TESTE do SIMULADOR de componente local (Fase 3 §3.4). Certificado de
     * TESTE, nunca A3 nem ICP-Brasil; dado de demonstração, só para o ambiente local.
     */
    public const DEMO_A3_SIMULATOR_PASSWORD = 'demo-simulador-A3-TESTE';

    /**
     * Senha do certificado A1 de TESTE do participante gerado para a demonstração (onda C).
     * Certificado de TESTE (AC descartável, CN com "TESTE"), nunca ICP-Brasil: a senha é um
     * dado de demonstração como {@see self::PASSWORD}, só para o ambiente local.
     */
    public const DEMO_PARTICIPANT_PFX_PASSWORD = 'demo-A1-participante-TESTE';

    /** Senha do PKCS#12 da TSA de TESTE gerada para a demonstração (onda C), só local. */
    public const DEMO_TSA_PASSWORD = 'demo-TSA-operadora-TESTE';

    /** Diretório (em storage/app/private) dos arquivos de teste da onda C. */
    public const DEMO_WAVE_C_DIR = 'demo/onda-c';

    /** PIN de demonstração do participante "PIN" (onda B). Só para o ambiente local. */
    public const DEMO_PIN = '48291573';

    private int $signerCursor = 0;

    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            $this->command->warn('DemoOrganizationSeeder ignorado: só roda em local/testing.');

            return;
        }

        fake()->seed(20260908);

        $free = Plan::query()->where('code', Plan::CODE_FREE)->firstOrFail();
        $professional = Plan::query()->where('code', Plan::CODE_PROFESSIONAL)->firstOrFail();

        // Fase 2, onda A (roadmap §1 T8): o plano da Horizonte (Profissional sandbox) inclui
        // os itens da onda A; o da Vega (Grátis) não. O recurso só aparece quando o
        // interruptor GLOBAL também está ligado (`ASSINAVELOX_FEATURE_*` no .env) — desligado,
        // que é o padrão e o que os testes usam, a demonstração é exatamente a da Fase 1.
        $professional->forceFill(['features' => array_replace((array) $professional->features, array_fill_keys(self::PHASE2_PLAN_FEATURES, true), array_fill_keys(self::PHASE3_PART1_PLAN_FEATURES, true), array_fill_keys(self::PHASE3_WAVE_F_PLAN_FEATURES, true), array_fill_keys(self::PHASE3_WAVE_G_PLAN_FEATURES, true), array_fill_keys(self::PHASE4_PLAN_FEATURES, true))])->save();
        $free->forceFill(['features' => array_replace((array) $free->features, array_fill_keys(self::PHASE2_PLAN_FEATURES, false), array_fill_keys(self::PHASE3_PART1_PLAN_FEATURES, false), array_fill_keys(self::PHASE3_WAVE_F_PLAN_FEATURES, false), array_fill_keys(self::PHASE3_WAVE_G_PLAN_FEATURES, false), array_fill_keys(self::PHASE4_PLAN_FEATURES, false))])->save();

        DB::transaction(function () use ($free, $professional): void {
            $hasPlatformCertificate = CertificateReference::query()
                ->whereNull('organization_id')
                ->where('secret_ref', 'SIGNING_CERT_TEST_PFX')
                ->exists();

            if (! $hasPlatformCertificate) {
                CertificateReference::factory()->active()->create();
            }

            $this->seedHorizonte($professional);
            $this->seedVega($free);
        });

        // Onda C: arquivos de TESTE gerados pelo pdftool — fora da transação (regra da onda:
        // nenhuma transação aberta durante chamada ao pdftool).
        $this->seedWaveCTestFiles();
        $this->seedPhase3TestFiles();
    }

    /**
     * Fase 3, parte 1: PKCS#12 de TESTE do simulador de componente local (A3 simulado — nunca A3),
     * fora da transação (chama o pdftool). As variáveis do .env para usá-lo são impressas aqui;
     * a senha é a constante {@see self::DEMO_A3_SIMULATOR_PASSWORD}, só local.
     */
    private function seedPhase3TestFiles(): void
    {
        if (app()->runningUnitTests()) {
            return;
        }

        $directory = storage_path('app/private/'.self::DEMO_PHASE3_DIR);
        File::ensureDirectoryExists($directory, 0700);

        try {
            app(ParticipantCertificateTool::class)->generateTestCertificate(
                $directory.DIRECTORY_SEPARATOR.'simulador-a3-teste.pfx',
                self::DEMO_A3_SIMULATOR_PASSWORD,
                'Maria Alves Souza',
                days: 365,
                outCaPem: $directory.DIRECTORY_SEPARATOR.'simulador-a3-teste-ac.pem',
            );

            $this->command->info('Fase 3: PKCS#12 de TESTE do simulador de componente local em storage/app/private/'.self::DEMO_PHASE3_DIR
                .' (ASSINAVELOX_A3_SIMULATOR_PFX / ASSINAVELOX_A3_SIMULATOR_PASS_ENV; senha: DemoOrganizationSeeder::DEMO_A3_SIMULATOR_PASSWORD).');
        } catch (Throwable $exception) {
            $this->command->warn('Fase 3: PKCS#12 do simulador não gerado ('.class_basename($exception).'). O pdftool precisa do venv em tools/pdftool/.venv.');
        }
    }

    /**
     * Fase 2, onda C (docs/fase-2/onda-c-relatorio.md §6) na Horizonte: política de retenção
     * ativa (prazos iguais aos mínimos ou maiores — nada da demonstração vence) e um documento
     * concluído PRESERVADO. As flags de plano da onda C ficam ligadas na Horizonte e desligadas
     * na Vega; a interface só aparece com os interruptores globais `ASSINAVELOX_FEATURE_*`.
     */
    private function seedHorizonteWaveC(Organization $org, User $owner): void
    {
        $ownerMembership = Membership::query()->where('organization_id', $org->id)->where('user_id', $owner->id)->firstOrFail();

        CurrentOrganization::instance()->runAs($org, function () use ($org, $owner): void {
            $policy = RetentionPolicy::query()->firstOrNew(['organization_id' => $org->id]);
            $policy->forceFill([
                'organization_id' => $org->id,
                'is_active' => true,
                'completed_days' => 1825,
                'terminal_days' => 365,
                'draft_days' => 90,
                'identity_capture_days' => 30,
                'dossier_days' => 7,
                'audit_trail_days' => null,
                'updated_by_user_id' => $owner->id,
            ])->save();

            $preserved = Envelope::query()
                ->where('organization_id', $org->id)
                ->where('status', EnvelopeStatus::Completed)
                ->orderBy('id')
                ->first();

            if ($preserved !== null) {
                app(LegalHolds::class)->place(
                    $org,
                    $owner,
                    LegalHoldScope::Envelope,
                    'Demonstração: notificação extrajudicial em andamento — preservar até o fim da negociação (pedido do jurídico).',
                    envelope: $preserved,
                );
            }
        }, $ownerMembership);
    }

    /**
     * Certificado A1 de TESTE de participante (para enviar na página pública) e uma TSA de
     * TESTE da operadora, em storage/app/private/{@see self::DEMO_WAVE_C_DIR}. Sem o pdftool
     * (venv ausente), só avisa: o resto da demonstração não depende disso.
     */
    private function seedWaveCTestFiles(): void
    {
        // Os testes que rodam este seeder (SeedersTest e os de revisão) não precisam dos arquivos
        // e não devem chamar o pdftool nem escrever no storage real.
        if (app()->runningUnitTests()) {
            return;
        }

        $directory = storage_path('app/private/'.self::DEMO_WAVE_C_DIR);
        File::ensureDirectoryExists($directory.DIRECTORY_SEPARATOR.'tsa', 0700);

        try {
            app(ParticipantCertificateTool::class)->generateTestCertificate(
                $directory.DIRECTORY_SEPARATOR.'participante-teste.pfx',
                self::DEMO_PARTICIPANT_PFX_PASSWORD,
                'Ana Beatriz Rocha',
                days: 365,
                outCaPem: $directory.DIRECTORY_SEPARATOR.'participante-teste-ac.pem',
            );

            $passEnv = 'ASSINAVELOX_DEMO_TSA_PASSWORD';
            putenv($passEnv.'='.self::DEMO_TSA_PASSWORD);

            try {
                app(TsaToolRunner::class)->run('tsa-gen-test', [
                    '--out-pfx', $directory.DIRECTORY_SEPARATOR.'tsa'.DIRECTORY_SEPARATOR.'tsa.pfx',
                    '--pass-env', $passEnv,
                    '--out-root-pem', $directory.DIRECTORY_SEPARATOR.'tsa'.DIRECTORY_SEPARATOR.'root.pem',
                    '--out-chain-pem', $directory.DIRECTORY_SEPARATOR.'tsa'.DIRECTORY_SEPARATOR.'chain.pem',
                    '--days', '365',
                    '--key', 'ec-p256',
                ], [$passEnv]);
            } finally {
                putenv($passEnv);
            }

            $this->command->info('Onda C: certificado A1 de TESTE do participante e TSA de TESTE em storage/app/private/'.self::DEMO_WAVE_C_DIR.' (senhas: constantes DEMO_* do DemoOrganizationSeeder; ver docs/fase-2/onda-c-relatorio.md §6).');
        } catch (Throwable $exception) {
            $this->command->warn('Onda C: arquivos de TESTE não gerados ('.class_basename($exception).'). O pdftool precisa do venv em tools/pdftool/.venv.');
        }
    }

    // -- Organização 1: Imobiliária Horizonte (Profissional sandbox) ---------------------

    private function seedHorizonte(Plan $plan): void
    {
        $owner = $this->user('Marina Castelo Horizonte', 'owner@horizonte.demo');
        $admin = $this->user('Rafael Duarte', 'admin@horizonte.demo');
        $member = $this->user('Juliana Peres', 'operador@horizonte.demo');

        $org = Organization::factory()->createdBy($owner)->create([
            'name' => 'Imobiliária Horizonte Demo',
            'legal_name' => 'Horizonte Negócios Imobiliários Demo Ltda.',
            'tax_id' => '12.345.678/0001-90',
            'settings' => Organization::DEFAULT_SETTINGS,
        ]);

        $this->attach($org, $owner, MembershipRole::Owner);
        $this->attach($org, $admin, MembershipRole::Admin);
        $this->attach($org, $member, MembershipRole::Member);

        // Fase 2 §2.14: as três funções de sistema existem como linhas (o mesmo que
        // CreateOrganization faz); as memberships continuam no papel de sistema.
        PermissionsSystemRoles::ensureFor($org);

        MembershipInvitation::factory()->create([
            'organization_id' => $org->id,
            'email' => 'novo.colaborador@horizonte.demo',
            'role' => MembershipRole::Member,
            'invited_by_user_id' => $admin->id,
        ]);

        $subscription = Subscription::factory()
            ->forOrganization($org)->ofPlan($plan)->active()
            ->create(['provider' => 'mercadopago']);

        $approvedPayment = Payment::factory()->forSubscription($subscription)->approved()->create();
        Payment::factory()->forSubscription($subscription)->pending()->create([
            'created_at' => now()->subMonth(),
            'updated_at' => now()->subMonth(),
        ]);

        $locacoes = Folder::factory()->create(['organization_id' => $org->id, 'name' => 'Locações', 'created_by_user_id' => $admin->id]);
        $vendas = Folder::factory()->create(['organization_id' => $org->id, 'name' => 'Vendas', 'created_by_user_id' => $admin->id]);
        // ROUTES_AND_PAGES.md (glossário): "Pasta | Folder | Sem hierarquia na Fase 1".
        // Como subpasta, "Comerciais" aparecia na coluna Pasta da listagem mas não na
        // árvore lateral (que só lista raízes), deixando 4 documentos sem filtro.
        $comerciais = Folder::factory()->create(['organization_id' => $org->id, 'name' => 'Comerciais', 'created_by_user_id' => $admin->id]);
        Folder::factory()->create(['organization_id' => $org->id, 'name' => 'Recursos Humanos', 'created_by_user_id' => $owner->id]);

        // Rascunhos
        $this->envelope($org, $member, EnvelopeStatus::Draft, 'Contrato de locação residencial — Rua das Acácias, 120', $locacoes, signers: 2, sourceType: DocumentSourceType::Docx);
        $this->envelope($org, $admin, EnvelopeStatus::Preparing, 'Proposta de compra — Apartamento 1204', $vendas, signers: 1, sourceType: DocumentSourceType::Docx);
        $this->envelope($org, $member, EnvelopeStatus::Ready, 'Termo de vistoria de entrada — Sala 302', $comerciais, signers: 2);

        // Em andamento
        $this->envelope($org, $member, EnvelopeStatus::InProgress, 'Contrato de locação comercial — Av. Paulista, 1500', $comerciais, signers: 3, signedCount: 1, subscription: $subscription);
        $this->envelope($org, $admin, EnvelopeStatus::InProgress, 'Aditivo de reajuste — Contrato 2024/118', $locacoes, signers: 2, signedCount: 0, subscription: $subscription);
        $this->envelope($org, $owner, EnvelopeStatus::InProgress, 'Compromisso de compra e venda — Lote 17', $vendas, signers: 2, signedCount: 1, order: SigningOrder::Parallel, subscription: $subscription);
        $this->envelope($org, $member, EnvelopeStatus::Finalizing, 'Distrato amigável — Rua Ipê, 45', $locacoes, signers: 2, signedCount: 2, subscription: $subscription);

        // Concluídos
        $this->envelope($org, $member, EnvelopeStatus::Completed, 'Contrato de locação residencial — Rua Jasmim, 88', $locacoes, signers: 2, signedCount: 2, subscription: $subscription, signedByCompany: true);
        $this->envelope($org, $admin, EnvelopeStatus::Completed, 'Procuração para administração de imóvel', null, signers: 1, signedCount: 1, subscription: $subscription, signedByCompany: true);
        $this->envelope($org, $owner, EnvelopeStatus::Completed, 'Termo de entrega de chaves — Apto 702', $locacoes, signers: 3, signedCount: 3, order: SigningOrder::Parallel, subscription: $subscription);

        // Recusado / expirado / cancelado
        $this->envelope($org, $member, EnvelopeStatus::Refused, 'Contrato de locação comercial — Galpão 3', $comerciais, signers: 2, signedCount: 1, subscription: $subscription);
        $this->envelope($org, $admin, EnvelopeStatus::Expired, 'Proposta de locação — Casa Vila Nova', $locacoes, signers: 1, signedCount: 0, subscription: $subscription);
        $this->envelope($org, $member, EnvelopeStatus::Canceled, 'Termo de reserva — Unidade 405', $vendas, signers: 1);

        $subscription->update(['envelopes_used' => PlanConsumption::query()
            ->withoutGlobalScopes()
            ->where('subscription_id', $subscription->id)
            ->where('status', 'committed')
            ->sum('quantity')]);

        $this->seedHorizontePhase2($org, $owner, $admin, $locacoes, $vendas);
        $this->seedHorizonteWaveB($org, $owner);
        $this->seedHorizonteWaveC($org, $owner);
        $this->seedHorizonteWaveD($org, $owner, $subscription, $approvedPayment);
        $this->seedHorizontePhase3PartOne($org, $approvedPayment);
        $this->seedHorizonteWaveF($org, $owner);
        $this->seedHorizonteWaveG($org, $owner);
    }

    /**
     * Fase 3 — onda G (docs/fase-3/onda-g-relatorio.md §5) na Horizonte, SEM segredo nenhum:
     *  - widget de assinatura: uma origem de exemplo (domínio reservado `.example`);
     *  - login corporativo: uma conexão OIDC em RASCUNHO (sem client secret, sem teste) e o
     *    domínio `horizonte.demo` ainda NÃO verificado — a tela mostra o estado honesto de "falta
     *    configurar", e ninguém entra por ela;
     *  - Google Drive, Dropbox e HubSpot: nada é semeado. Conexão falsa fingiria um app que o
     *    proprietário ainda não registrou (classe B); sem as credenciais em config/services.php
     *    as telas dizem "Aguardando app registrado pelo proprietário".
     * As flags de plano ficam ligadas na Horizonte e desligadas na Vega; os interruptores globais
     * continuam os do .env (desligados por padrão).
     */
    private function seedHorizonteWaveG(Organization $org, User $owner): void
    {
        AllowedOrigins::replace($org, [self::DEMO_EMBED_ORIGIN], $owner);

        SsoConnection::withoutOrganizationScope()->create([
            'organization_id' => $org->id,
            'created_by_user_id' => $owner->id,
            'protocol' => SsoProtocol::Oidc,
            'name' => 'Login da Horizonte (exemplo)',
            'status' => SsoConnectionStatus::Draft,
            'oidc_issuer' => 'https://login.imobiliaria-horizonte.example',
            'oidc_client_id' => 'assinavelox-demo',
            'oidc_client_secret' => null,
            'oidc_id_token_alg' => 'RS256',
        ]);

        SsoDomain::withoutOrganizationScope()->create([
            'organization_id' => $org->id,
            'created_by_user_id' => $owner->id,
            'domain' => 'horizonte.demo',
            'verified_domain' => null,
            'verification_token' => Str::random(40),
            'verified_at' => null,
        ]);
    }

    /**
     * Fase 3, parte 2 — onda F (docs/fase-3/onda-f-relatorio.md §5) na Horizonte, pelos serviços
     * REAIS (nenhum segredo, nenhum arquivo, nenhuma chamada ao pdftool):
     *  - regra de âncora no modelo "Contrato de locação residencial": SUGERE a data do locatário
     *    abaixo do texto "Locatário" — só sugestão, revisada no editor;
     *  - "Termo de vistoria de entrada — Sala 302" (pronto): duas etapas, delegação permitida com
     *    confirmação (o primeiro participante é pessoal) e vídeo curto exigido do segundo;
     *  - "Aditivo de reajuste — Contrato 2024/118" (em andamento): primeiro participante em inglês
     *    (fuso de Nova York), com vídeo exigido e delegação permitida com confirmação; o segundo
     *    em espanhol. A exigência e a política são gravadas direto, como teriam ficado no preparo.
     * As flags de plano ficam ligadas na Horizonte e desligadas na Vega; os interruptores globais
     * são ligados só durante a semeadura e voltam ao valor do .env.
     */
    private function seedHorizonteWaveF(Organization $org, User $owner): void
    {
        $previous = [];

        foreach (self::PHASE3_WAVE_F_PLAN_FEATURES as $flag) {
            $previous[$flag] = config("assinavelox.features.{$flag}");
            config()->set("assinavelox.features.{$flag}", true);
        }

        $ownerMembership = Membership::query()->where('organization_id', $org->id)->where('user_id', $owner->id)->firstOrFail();

        try {
            CurrentOrganization::instance()->runAs($org, function () use ($org, $owner): void {
                $template = Template::query()->where('organization_id', $org->id)->where('name', 'Contrato de locação residencial')->first();

                if ($template !== null) {
                    app(TemplateAnchorRules::class)->replace($template, $owner, [[
                        'pattern' => 'Locatário',
                        'field_type' => 'date',
                        'role_position' => 2,
                        'placement' => 'below',
                        'offset_x_pt' => 0,
                        'offset_y_pt' => 4,
                        'width_pt' => 100,
                        'height_pt' => 18,
                        'required' => true,
                        'occurrence' => 'first',
                    ]]);
                }

                $ready = Envelope::query()->where('organization_id', $org->id)->where('title', 'Termo de vistoria de entrada — Sala 302')->first();
                $readySigners = $ready !== null
                    ? Recipient::query()->where('envelope_id', $ready->id)->orderBy('order_index')->orderBy('id')->get()->values()
                    : collect();

                if ($ready !== null && $readySigners->count() >= 2) {
                    app(SigningStepPlan::class)->update($ready, true, [
                        ['name' => 'Vistoriador', 'recipients' => [$readySigners[0]->ulid]],
                        ['name' => 'Locatário', 'recipients' => [$readySigners[1]->ulid]],
                    ]);
                    DelegationPolicy::update($ready, ['allow' => true, 'requires_confirmation' => true, 'personal' => [$readySigners[0]->ulid]]);
                    app(IdentityVideos::class)->setRequirement($ready, $readySigners[1], true, 10, $owner);
                }

                $running = Envelope::query()->where('organization_id', $org->id)->where('title', 'Aditivo de reajuste — Contrato 2024/118')->first();

                if ($running !== null) {
                    $people = Recipient::query()->where('envelope_id', $running->id)->orderBy('order_index')->orderBy('id')->get()->values();
                    $first = $people->get(0);
                    $second = $people->get(1);

                    $first?->forceFill(['locale' => 'en', 'timezone' => 'America/New_York'])->save();
                    $second?->forceFill(['locale' => 'es', 'timezone' => 'America/Argentina/Buenos_Aires'])->save();

                    if ($first !== null) {
                        $requirement = new IdentityVideoRequirement;
                        $requirement->forceFill([
                            'organization_id' => $org->id,
                            'envelope_id' => $running->id,
                            'recipient_id' => $first->id,
                            'max_seconds' => 10,
                            'updated_by_user_id' => $owner->id,
                        ])->save();
                    }

                    $running->forceFill(['settings' => array_replace((array) ($running->settings ?? []), [
                        DelegationPolicy::SETTING_ALLOW => true,
                        DelegationPolicy::SETTING_CONFIRM => true,
                        DelegationPolicy::SETTING_PERSONAL => [],
                    ])])->save();
                }
            }, $ownerMembership);
        } finally {
            foreach ($previous as $flag => $value) {
                config()->set("assinavelox.features.{$flag}", $value);
            }
        }
    }

    /**
     * Fase 2, onda D (docs/fase-2/entrega-fase-2.md §5) na Horizonte:
     *  - uma chave de API de exemplo — só o hash vai para o banco; o texto é descartado aqui e
     *    NUNCA impresso (a tela mostra só o prefixo);
     *  - um endpoint de webhook para um destino INVÁLIDO (`.invalid` nunca resolve), pausado,
     *    para a tela de webhooks ter o que mostrar sem que nada saia para a rede;
     *  - um estorno parcial aprovado no pagamento aprovado e um pagamento antigo estornado por
     *    inteiro, para o painel interno de faturamento.
     * As flags de plano ficam ligadas na Horizonte e desligadas na Vega; a interface só aparece
     * com os interruptores globais `ASSINAVELOX_FEATURE_*` (e `extended_payments` só pelo .env).
     */
    /**
     * Fase 3, parte 1 (docs/fase-3/parte-1-relatorio.md §5) na Horizonte, pelos serviços REAIS:
     *  - antifraude: um sinal de taxa de falha de entrega (só contagens) que leva a organização a
     *    `watch` com um caso aberto na fila — `watch` NÃO suspende envio;
     *  - afiliados: uma parceira aprovada (dados de repasse de TESTE), a indicação da Horizonte e
     *    as comissões calculadas sobre os pagamentos da demonstração. O sistema calcula, não paga.
     * As flags da plataforma são ligadas só durante a semeadura e voltam ao valor do .env.
     */
    private function seedHorizontePhase3PartOne(Organization $org, Payment $approvedPayment): void
    {
        $antifraud = config('assinavelox.features.antifraud');
        $affiliates = config('assinavelox.features.affiliates');
        $sandbox = config('assinavelox.affiliates.include_sandbox_payments');

        try {
            config()->set('assinavelox.features.antifraud', true);
            RiskSignals::record('delivery_failure_rate', $org, [
                // Só as chaves do catálogo da regra (RiskRule::evidenceKeys): contagens, nunca endereços.
                'attempts_in_window' => 24,
                'failed_in_window' => 9,
                'failure_rate' => 0.375,
                'window_minutes' => 1440,
                'threshold' => 0.3,
            ]);

            config()->set('assinavelox.features.affiliates', true);
            config()->set('assinavelox.affiliates.include_sandbox_payments', true);

            $partner = $this->user('Paula Parceira Demo', 'parceira@afiliados.demo');
            $affiliate = Affiliate::query()->create([
                'user_id' => $partner->id,
                'code' => 'PARCDEMO',
                'commission_rate_bp' => 1000,
                'status' => Affiliate::STATUS_APPROVED,
                // CPF de TESTE (dígitos válidos, público em documentação de exemplo) — nenhum dado real.
                'payout_details' => [
                    'method' => 'pix',
                    'pix_key_type' => 'email',
                    'pix_key' => 'parceira@afiliados.demo',
                    'holder_name' => 'Paula Parceira Demo',
                    'holder_tax_id' => '52998224725',
                ],
                'terms_version' => 'afiliados-demo',
                'terms_accepted_at' => now()->subDays(120),
                'approved_at' => now()->subDays(119),
            ]);

            Referral::query()->create([
                'affiliate_id' => $affiliate->id,
                'organization_id' => $org->id,
                'user_id' => $org->created_by_user_id,
                'source' => Referral::SOURCE_LINK,
                'status' => Referral::STATUS_ACTIVE,
                'clicked_at' => now()->subDays(100),
                'attributed_at' => now()->subDays(100),
                'expires_at' => now()->addMonths(9),
            ]);

            app(CommissionLedger::class)->syncOrganization((int) $org->id);
        } finally {
            config()->set('assinavelox.features.antifraud', $antifraud);
            config()->set('assinavelox.features.affiliates', $affiliates);
            config()->set('assinavelox.affiliates.include_sandbox_payments', $sandbox);
        }
    }

    private function seedHorizonteWaveD(Organization $org, User $owner, Subscription $subscription, Payment $approvedPayment): void
    {
        $secret = (string) config('sanctum.token_prefix', '').Str::random(40);

        $token = new ApiToken;
        $token->forceFill([
            'tokenable_type' => $owner->getMorphClass(),
            'tokenable_id' => $owner->getKey(),
            'organization_id' => $org->id,
            'created_by_user_id' => $owner->id,
            'name' => 'Integração ERP (demonstração)',
            'token' => hash('sha256', $secret),
            'token_prefix' => mb_substr($secret, 0, 8),
            'abilities' => ['envelopes:read', 'envelopes:write', 'envelopes:send', 'documents:read', 'templates:read', 'templates:use'],
            'expires_at' => now()->addDays(90),
        ])->save();
        unset($secret);

        $webhookSecret = WebhookSignature::generateSecret();
        WebhookEndpoint::withoutOrganizationScope()->create([
            'organization_id' => $org->id,
            'created_by_user_id' => $owner->id,
            'url' => 'https://destino-invalido.invalid/assinavelox/webhooks',
            'description' => 'Demonstração — destino inválido, pausado (nada é entregue)',
            'events' => ['envelope.completed', 'recipient.signed'],
            'secret' => $webhookSecret,
            'secret_hint' => WebhookSignature::hint($webhookSecret),
            'is_active' => false,
            'paused_at' => now(),
            'paused_reason' => WebhookEndpoint::PAUSED_MANUAL,
            'consecutive_failures' => 0,
            'source' => WebhookEndpoint::SOURCE_WEB,
        ]);
        unset($webhookSecret);

        // Estorno parcial aprovado (não muda plano nem cota — política conservadora).
        $approvedPayment->forceFill(['refunded_cents' => 1_000])->save();
        PaymentRefund::withoutOrganizationScope()->create([
            'organization_id' => $org->id,
            'payment_id' => $approvedPayment->id,
            'provider' => $approvedPayment->provider,
            'provider_refund_id' => 'demo-refund-parcial-1',
            'amount_cents' => 1_000,
            'currency' => $approvedPayment->currency ?? 'BRL',
            'kind' => PaymentRefund::KIND_PARTIAL,
            'status' => PaymentRefund::STATUS_APPROVED,
            'provider_status' => 'approved',
            'reason' => 'Demonstração: desconto concedido',
            'initiator' => PaymentRefund::INITIATOR_PLATFORM_ADMIN,
            'idempotency_key' => (string) Str::uuid(),
            'requested_at' => now()->subDays(2),
            'confirmed_at' => now()->subDays(2),
        ]);

        // Pagamento de um ciclo antigo, estornado por inteiro.
        $old = Payment::factory()->forSubscription($subscription)->approved()->create([
            'created_at' => now()->subMonths(2),
            'updated_at' => now()->subMonths(2),
        ]);
        $old->forceFill(['status' => PaymentStatus::Refunded, 'refunded_cents' => $old->amount_cents, 'paid_at' => now()->subMonths(2), 'activated_at' => now()->subMonths(2)])->save();
        PaymentRefund::withoutOrganizationScope()->create([
            'organization_id' => $org->id,
            'payment_id' => $old->id,
            'provider' => $old->provider,
            'provider_refund_id' => 'demo-refund-total-1',
            'amount_cents' => $old->amount_cents,
            'currency' => $old->currency ?? 'BRL',
            'kind' => PaymentRefund::KIND_TOTAL,
            'status' => PaymentRefund::STATUS_APPROVED,
            'provider_status' => 'approved',
            'reason' => 'Demonstração: cobrança em duplicidade',
            'initiator' => PaymentRefund::INITIATOR_PLATFORM_ADMIN,
            'idempotency_key' => (string) Str::uuid(),
            'requested_at' => now()->subMonths(2)->addDay(),
            'confirmed_at' => now()->subMonths(2)->addDay(),
        ]);
    }

    /**
     * Dados de demonstração da Fase 2, onda B (docs/fase-2/onda-b-relatorio.md §6): marca da
     * organização, um formulário público publicado (fila de revisão) e um documento em andamento
     * com um participante por SMS simulado e outro com PIN do remetente (PIN {@see self::DEMO_PIN}).
     * Tudo só aparece com os interruptores globais `ASSINAVELOX_FEATURE_*` ligados: desligados
     * (o padrão e o que os testes usam), a demonstração continua a da onda A.
     */
    private function seedHorizonteWaveB(Organization $org, User $owner): void
    {
        $ownerMembership = Membership::query()->where('organization_id', $org->id)->where('user_id', $owner->id)->firstOrFail();

        CurrentOrganization::instance()->runAs($org, function () use ($org, $owner): void {
            $branding = app(BrandingManager::class);
            $branding->update($org, $owner, [
                'display_name' => 'Horizonte Imóveis',
                'primary_color' => '#1257C9',
                'accent_color' => '#0E9F6E',
                'reply_to_email' => 'atendimento@horizonte.demo',
            ]);

            $logo = tempnam(sys_get_temp_dir(), 'horizonte-logo');

            if ($logo !== false && function_exists('imagecreatetruecolor')) {
                $image = imagecreatetruecolor(480, 160);
                imagealphablending($image, false);
                imagesavealpha($image, true);
                imagefilledrectangle($image, 0, 0, 479, 159, (int) imagecolorallocatealpha($image, 255, 255, 255, 127));
                imagealphablending($image, true);
                imagefilledrectangle($image, 12, 24, 124, 136, (int) imagecolorallocate($image, 18, 87, 201));
                imagefilledpolygon($image, [24, 110, 68, 44, 112, 110], (int) imagecolorallocate($image, 255, 255, 255));
                imagefilledrectangle($image, 150, 60, 460, 100, (int) imagecolorallocate($image, 14, 159, 110));
                imagepng($image, $logo);
                imagedestroy($image);

                $branding->replaceLogo($org, $owner, $logo);
                @unlink($logo);
            }

            $template = Template::query()->where('organization_id', $org->id)->where('name', 'Termo de entrega de chaves')->first();

            if ($template !== null) {
                $forms = app(PublicFormManager::class);
                $form = $forms->create($org, $owner, $template, 'Solicitação de termo de entrega de chaves');
                $forms->update($form, $owner, array_replace(app(PublicFormSchema::class)->configInput($form), [
                    'instructions' => 'Preencha os dados do imóvel. Você receberá um e-mail para confirmar o pedido; o documento é revisado pela equipe antes do envio.',
                ]));
                $forms->activate($form->fresh());
            }

            $envelope = Envelope::query()->where('organization_id', $org->id)->where('title', 'Aditivo de reajuste — Contrato 2024/118')->first();
            $recipients = $envelope?->recipients()->orderBy('order_index')->get() ?? collect();

            if ($recipients->count() >= 2) {
                $recipients[0]->forceFill([
                    'phone' => '+5511912345678',
                    'auth_method' => AuthMethod::SmsOtp,
                    'delivery_channel' => DeliveryChannel::Sms->value,
                ])->save();

                app(SenderPins::class)->set($recipients[1], self::DEMO_PIN, $owner);
            }
        }, $ownerMembership);
    }

    /**
     * Dados de demonstração da Fase 2, onda A (docs/fase-2/onda-a-relatorio.md §6): papéis de
     * sistema, uma função personalizada com acesso por pasta, um time, dois modelos HTML e
     * etiquetas. Nada disso muda o que owner/admin/operador veem na Fase 1: a função e o time
     * ficam com um usuário NOVO (gerente@horizonte.demo) e com o admin, que já vê tudo.
     */
    private function seedHorizontePhase2(Organization $org, User $owner, User $admin, Folder $locacoes, Folder $vendas): void
    {
        $manager = $this->user('Otávio Gerente', 'gerente@horizonte.demo');

        $role = new Role;
        $role->forceFill([
            'organization_id' => $org->id,
            'name' => 'Gerente de locações',
            'description' => 'Prepara e acompanha os contratos das pastas liberadas.',
            'is_system' => false,
            'created_by_user_id' => $owner->id,
        ])->save();
        $role->syncPermissions([
            Permission::CreateEnvelopes, Permission::SendEnvelopes, Permission::ManageTemplates,
            Permission::ManageTags, Permission::ViewReports, Permission::ExportData,
        ]);

        Membership::query()->updateOrCreate(
            ['organization_id' => $org->id, 'user_id' => $manager->id],
            ['role' => MembershipRole::Member, 'role_id' => $role->id, 'status' => 'active'],
        );

        if ($manager->current_organization_id === null) {
            $manager->forceFill(['current_organization_id' => $org->id])->save();
        }

        FolderPermission::query()->create([
            'organization_id' => $org->id,
            'folder_id' => $locacoes->id,
            'role_id' => $role->id,
            'level' => FolderAccessLevel::Manage,
            'granted_by_user_id' => $owner->id,
        ]);

        $team = Team::query()->create([
            'organization_id' => $org->id,
            'name' => 'Equipe comercial',
            'description' => 'Vendas e locações comerciais.',
            'created_by_user_id' => $owner->id,
        ]);
        $team->memberships()->attach(Membership::query()
            ->where('organization_id', $org->id)
            ->whereIn('user_id', [$admin->id, $manager->id])
            ->pluck('id')
            ->all());

        FolderPermission::query()->create([
            'organization_id' => $org->id,
            'folder_id' => $vendas->id,
            'team_id' => $team->id,
            'level' => FolderAccessLevel::View,
            'granted_by_user_id' => $owner->id,
        ]);

        $ownerMembership = Membership::query()->where('organization_id', $org->id)->where('user_id', $owner->id)->firstOrFail();

        CurrentOrganization::instance()->runAs($org, function () use ($org, $owner): void {
            $templates = app(TemplateManager::class);

            foreach ([
                [
                    'name' => 'Contrato de locação residencial',
                    'category' => 'Locação',
                    'html' => '<h1>Contrato de locação residencial</h1><p>Locador: {{locador}}. Locatário: {{locatario}}, CPF {{cpf_locatario}}.</p><p>Imóvel: {{endereco}}. Aluguel mensal de {{valor}}, com início em {{inicio}}.</p>',
                    'variables' => [
                        ['key' => 'locador', 'label' => 'Locador', 'type' => 'text', 'required' => true],
                        ['key' => 'locatario', 'label' => 'Locatário', 'type' => 'text', 'required' => true],
                        ['key' => 'cpf_locatario', 'label' => 'CPF do locatário', 'type' => 'cpf', 'required' => true],
                        ['key' => 'endereco', 'label' => 'Endereço do imóvel', 'type' => 'text', 'required' => true],
                        ['key' => 'valor', 'label' => 'Valor do aluguel', 'type' => 'currency', 'required' => true],
                        ['key' => 'inicio', 'label' => 'Início da locação', 'type' => 'date', 'required' => true],
                    ],
                    'roles' => [
                        ['ref' => 'locador', 'name' => 'Locador', 'participant_role' => 'signer'],
                        ['ref' => 'locatario', 'name' => 'Locatário', 'participant_role' => 'signer'],
                    ],
                ],
                [
                    'name' => 'Termo de entrega de chaves',
                    'category' => 'Vistoria',
                    'html' => '<h1>Termo de entrega de chaves</h1><p>Recebi de {{imobiliaria}} as chaves do imóvel {{endereco}} em {{data_entrega}}.</p>',
                    'variables' => [
                        ['key' => 'imobiliaria', 'label' => 'Imobiliária', 'type' => 'text', 'required' => true, 'default_value' => 'Imobiliária Horizonte Demo'],
                        ['key' => 'endereco', 'label' => 'Endereço do imóvel', 'type' => 'text', 'required' => true],
                        ['key' => 'data_entrega', 'label' => 'Data da entrega', 'type' => 'date', 'required' => true],
                    ],
                    'roles' => [
                        ['ref' => 'locatario', 'name' => 'Locatário', 'participant_role' => 'signer'],
                    ],
                ],
            ] as $spec) {
                $template = $templates->create($org, $owner, [
                    'name' => $spec['name'],
                    'category' => $spec['category'],
                    'source_type' => 'html',
                    'html_body' => $spec['html'],
                ], null);

                $templates->update($template, $owner, [
                    'html_body' => $spec['html'],
                    'variables' => $spec['variables'],
                    'roles' => $spec['roles'],
                    'fields' => [],
                ]);
            }

            $tags = app(TagManager::class);
            $residencial = $tags->create($org->id, 'Residencial', TagColor::Blue, $owner);
            $comercial = $tags->create($org->id, 'Comercial', TagColor::Amber, $owner);
            $tags->create($org->id, 'Urgente', TagColor::Red, $owner);

            $byTitle = Envelope::query()->where('organization_id', $org->id)->pluck('id', 'title');
            $now = now();

            foreach ([
                'Contrato de locação residencial — Rua das Acácias, 120' => $residencial,
                'Contrato de locação residencial — Rua Jasmim, 88' => $residencial,
                'Contrato de locação comercial — Av. Paulista, 1500' => $comercial,
                'Contrato de locação comercial — Galpão 3' => $comercial,
            ] as $title => $tag) {
                if (isset($byTitle[$title])) {
                    DB::table('envelope_tag')->insert([
                        'organization_id' => $org->id,
                        'envelope_id' => $byTitle[$title],
                        'tag_id' => $tag->id,
                        'added_by_user_id' => $owner->id,
                        'created_at' => $now,
                    ]);
                }
            }
        }, $ownerMembership);
    }

    // -- Organização 2: Consultoria Vega (Grátis) --------------------------------------

    private function seedVega(Plan $plan): void
    {
        $owner = $this->user('Tiago Vega', 'owner@vega.demo');
        $member = $this->user('Camila Ferraz', 'operador@vega.demo');

        $org = Organization::factory()->createdBy($owner)->withoutTaxId()->create([
            'name' => 'Consultoria Vega Demo',
            'legal_name' => null,
            'settings' => array_replace(Organization::DEFAULT_SETTINGS, ['default_expiration_days' => 15]),
        ]);

        $this->attach($org, $owner, MembershipRole::Owner);
        $this->attach($org, $member, MembershipRole::Member);
        PermissionsSystemRoles::ensureFor($org);

        $subscription = Subscription::factory()->forOrganization($org)->ofPlan($plan)->active()->create();

        $this->envelope($org, $owner, EnvelopeStatus::Draft, 'Termo de confidencialidade (NDA) — Projeto Aurora', null, signers: 1);
        $this->envelope($org, $member, EnvelopeStatus::InProgress, 'Contrato de consultoria — Diagnóstico organizacional', null, signers: 1, signedCount: 0, subscription: $subscription);
        $this->envelope($org, $owner, EnvelopeStatus::Completed, 'Proposta comercial — Plano estratégico 2027', null, signers: 2, signedCount: 2, order: SigningOrder::Parallel, subscription: $subscription);

        $subscription->update(['envelopes_used' => 2]);
    }

    // -- Helpers ------------------------------------------------------------------------

    private function user(string $name, string $email): User
    {
        $user = User::query()->firstOrNew(['email' => $email]);

        $user->forceFill([
            'name' => $name,
            'password' => self::PASSWORD,
            'email_verified_at' => now(),
            'locale' => 'pt_BR',
            'timezone' => 'America/Sao_Paulo',
            'terms_accepted_at' => now()->subMonths(2),
            'terms_version' => '2026-09',
        ])->save();

        return $user;
    }

    private function attach(Organization $org, User $user, MembershipRole $role): void
    {
        Membership::query()->updateOrCreate(
            ['organization_id' => $org->id, 'user_id' => $user->id],
            ['role' => $role, 'status' => 'active'],
        );

        if ($user->current_organization_id === null) {
            $user->forceFill(['current_organization_id' => $org->id])->save();
        }
    }

    /**
     * @return array{name: string, email: string}
     */
    private function nextSigner(): array
    {
        $signer = self::SIGNERS[$this->signerCursor % count(self::SIGNERS)];
        $this->signerCursor++;

        return $signer;
    }

    /**
     * Cria um envelope coerente no estado pedido: documento + versões, destinatários, campos,
     * aceites, links, sessões, entregas, consumo e trilha de auditoria.
     */
    private function envelope(
        Organization $org,
        User $creator,
        EnvelopeStatus $status,
        string $title,
        ?Folder $folder,
        int $signers,
        int $signedCount = 0,
        SigningOrder $order = SigningOrder::Sequential,
        DocumentSourceType $sourceType = DocumentSourceType::Pdf,
        ?Subscription $subscription = null,
        bool $signedByCompany = false,
    ): Envelope {
        $isSent = ! $status->isDraftLike() && $status !== EnvelopeStatus::Canceled;
        $daysAgo = fake()->numberBetween(2, 45);

        if ($status === EnvelopeStatus::Expired) {
            // Só expira depois de `default_expiration_days` a contar do envio; sem esse piso
            // a demo gerava "Expirado" com data no futuro.
            $daysAgo = max($daysAgo, (int) $org->setting('default_expiration_days', 30) + 3);
        }

        $createdAt = Carbon::now()->subDays($daysAgo)->subMinutes(fake()->numberBetween(0, 600));
        $clock = $createdAt->copy();
        $tick = fn (int $minutes = 3): Carbon => $clock->addMinutes($minutes)->copy();

        $envelope = Envelope::factory()->forOrganization($org, $creator)->create([
            'folder_id' => $folder?->id,
            'title' => $title,
            'status' => EnvelopeStatus::Draft,
            'signing_order' => $order,
            'message' => 'Olá! Segue o documento para assinatura eletrônica. Qualquer dúvida, responda este e-mail.',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        $this->audit($envelope, AuditEventType::EnvelopeCreated, $tick(0), user: $creator);

        // Documento e versões
        $document = Document::factory()->forEnvelope($envelope)->create([
            'name' => $title,
            'original_filename' => str($title)->slug().'.'.($sourceType === DocumentSourceType::Docx ? 'docx' : 'pdf'),
            'source_type' => $sourceType,
            'created_at' => $tick(),
        ]);
        $this->audit($envelope, AuditEventType::DocumentUploaded, $clock->copy(), user: $creator, payload: ['filename' => $document->original_filename]);

        $original = $sourceType === DocumentSourceType::Docx
            ? DocumentVersion::factory()->forDocument($document)->docxOriginal()->create(['created_at' => $clock->copy()])
            : DocumentVersion::factory()->forDocument($document)->original()->create(['created_at' => $clock->copy()]);

        $displayVersion = $original;

        if ($status === EnvelopeStatus::Preparing) {
            $document->update(['processing_status' => 'converting']);
            $this->audit($envelope, AuditEventType::DocumentConversionStarted, $tick(1));
            $envelope->update(['status' => EnvelopeStatus::Preparing]);

            $this->recipients($envelope, $signers, RecipientStatus::Pending, $clock);

            return $envelope;
        }

        if ($sourceType !== DocumentSourceType::Pdf) {
            $this->audit($envelope, AuditEventType::DocumentConversionStarted, $tick(1));
            $displayVersion = DocumentVersion::factory()->forDocument($document)->converted()->create(['created_at' => $tick(2)]);
            $this->audit($envelope, AuditEventType::DocumentConverted, $clock->copy());
        }

        $document->update([
            'processing_status' => 'ready',
            'current_version_id' => $displayVersion->id,
            'page_count' => $displayVersion->page_count,
        ]);

        // Destinatários
        $recipients = $this->recipients($envelope, $signers, RecipientStatus::Pending, $clock);
        $this->audit($envelope, AuditEventType::RecipientsUpdated, $tick(), user: $creator, payload: ['count' => $signers]);

        // Campos: assinatura + data por destinatário na última página; rubrica na 1ª página
        $pages = max(1, (int) $displayVersion->page_count);
        foreach ($recipients as $index => $recipient) {
            SigningField::factory()->forRecipient($recipient, $displayVersion)->signature()->onPage($pages)->create([
                'x' => 0.08 + ($index * 0.30),
                'y' => 0.78,
                'sort_order' => $index * 2,
            ]);
            SigningField::factory()->forRecipient($recipient, $displayVersion)->date()->onPage($pages)->create([
                'x' => 0.08 + ($index * 0.30),
                'y' => 0.85,
                'sort_order' => $index * 2 + 1,
            ]);
            SigningField::factory()->forRecipient($recipient, $displayVersion)->initials()->onPage(1)->create([
                'x' => 0.86 - ($index * 0.12),
            ]);
        }
        $this->audit($envelope, AuditEventType::FieldsUpdated, $tick(), user: $creator);

        if ($status === EnvelopeStatus::Draft) {
            return $envelope;
        }

        if ($status === EnvelopeStatus::Ready) {
            $envelope->update(['status' => EnvelopeStatus::Ready, 'updated_at' => $clock->copy()]);

            return $envelope;
        }

        if ($status === EnvelopeStatus::Canceled) {
            $canceledAt = $tick(60);
            $envelope->update(['status' => EnvelopeStatus::Canceled, 'canceled_at' => $canceledAt, 'updated_at' => $canceledAt]);
            foreach ($recipients as $recipient) {
                $recipient->update(['status' => RecipientStatus::Canceled]);
            }
            $this->audit($envelope, AuditEventType::EnvelopeCanceled, $canceledAt, user: $creator, payload: ['reason' => 'Negociação suspensa.']);

            return $envelope;
        }

        // Envio
        $sentAt = $tick(15);
        $expirationDays = (int) $org->setting('default_expiration_days', 30);
        $expiresAt = $sentAt->copy()->setTimezone($org->timezone)->addDays($expirationDays)->endOfDay()->setTimezone('UTC');

        $envelope->update([
            'status' => EnvelopeStatus::InProgress,
            'sent_at' => $sentAt,
            'expires_at' => $expiresAt,
            'sent_document_version_id' => $displayVersion->id,
            'verification_code' => Envelope::generateVerificationCode(),
            'updated_at' => $sentAt,
        ]);

        if ($subscription) {
            $consumption = PlanConsumption::factory()->forEnvelope($envelope, $subscription)->committed()->create([
                'reserved_at' => $sentAt,
                'committed_at' => $sentAt->copy()->addSecond(),
            ]);
            $this->audit($envelope, AuditEventType::PlanConsumptionReserved, $sentAt, payload: ['idempotency_key' => $consumption->idempotency_key]);
            $this->audit($envelope, AuditEventType::PlanConsumptionCommitted, $sentAt->copy()->addSecond());
        }

        $this->audit($envelope, AuditEventType::EnvelopeSent, $sentAt, user: $creator, payload: ['signing_order' => $order->value, 'recipients' => $signers]);

        // Convites: todos (paralelo) ou só a 1ª ordem (sequencial)
        $toNotify = $order === SigningOrder::Parallel ? $recipients : $recipients->take(1);
        foreach ($toNotify as $recipient) {
            $this->notify($recipient, $displayVersion, $sentAt->copy()->addSeconds(5));
        }

        // Aceites
        $signedRecipients = $recipients->take($signedCount);
        foreach ($signedRecipients as $index => $recipient) {
            if ($recipient->status === RecipientStatus::Pending) {
                $this->notify($recipient, $displayVersion, $clock->copy()->addMinutes(2));
            }

            $this->sign($recipient, $displayVersion, $tick(fake()->numberBetween(60, 60 * 30)));

            if ($order === SigningOrder::Sequential) {
                $next = $recipients->get($index + 1);
                if ($next && $next->status === RecipientStatus::Pending) {
                    $envelope->update(['current_order' => $next->order_index]);
                    $this->notify($next, $displayVersion, $clock->copy()->addSeconds(10));
                }
            }
        }

        return match ($status) {
            EnvelopeStatus::InProgress => $envelope->refresh(),
            EnvelopeStatus::Finalizing => $this->finalize($envelope, $displayVersion, $tick(5), complete: false, signedByCompany: $signedByCompany),
            EnvelopeStatus::Completed => $this->finalize($envelope, $displayVersion, $tick(5), complete: true, signedByCompany: $signedByCompany),
            EnvelopeStatus::Refused => $this->refuse($envelope, $recipients->get($signedCount) ?? $recipients->last(), $tick(120)),
            EnvelopeStatus::Expired => $this->expire($envelope, $recipients, $expiresAt),
        };
    }

    /**
     * @return Collection<int, Recipient>
     */
    private function recipients(Envelope $envelope, int $count, RecipientStatus $status, Carbon $clock): Collection
    {
        return collect(range(1, $count))->map(function (int $order) use ($envelope, $status, $clock) {
            $signer = $this->nextSigner();

            return Recipient::factory()->forEnvelope($envelope, $order)->create([
                'name' => $signer['name'],
                'email' => $signer['email'],
                'status' => $status,
                'created_at' => $clock->copy(),
                'updated_at' => $clock->copy(),
            ]);
        });
    }

    private function notify(Recipient $recipient, DocumentVersion $version, Carbon $at): void
    {
        $link = RecipientAccessLink::factory()->forRecipient($recipient)->create([
            'document_version_id' => $version->id,
            'purpose' => AccessLinkPurpose::Signing,
            'expires_at' => $recipient->envelope->expires_at,
            'created_at' => $at,
        ]);

        $delivery = DeliveryAttempt::factory()->forRecipient($recipient)->purpose(DeliveryPurpose::Invitation)->sent()->create([
            'queued_at' => $at,
            'sent_at' => $at->copy()->addSeconds(20),
            'created_at' => $at,
            'updated_at' => $at->copy()->addSeconds(20),
        ]);

        $recipient->update([
            'status' => RecipientStatus::Notified,
            'notification_count' => $recipient->notification_count + 1,
            'last_notified_at' => $at,
        ]);

        $this->audit($recipient->envelope, AuditEventType::InvitationSent, $at, recipient: $recipient, payload: [
            'access_link' => $link->ulid,
            'delivery_attempt' => $delivery->ulid,
            'email_masked' => $recipient->masked_email,
        ]);
    }

    private function sign(Recipient $recipient, DocumentVersion $version, Carbon $at): void
    {
        $envelope = $recipient->envelope;
        $link = $recipient->accessLinks()->latest('id')->firstOrFail();
        $ip = fake()->ipv4();
        $ua = fake()->userAgent();

        $recipient->update(['status' => RecipientStatus::Viewed]);
        $this->audit($envelope, AuditEventType::InvitationOpened, $at, recipient: $recipient, ip: $ip, ua: $ua);

        $session = SigningSession::factory()->forAccessLink($link)->authenticated()->create([
            'ip_address' => $ip,
            'user_agent' => $ua,
            'created_at' => $at->copy()->addMinute(),
            'authenticated_at' => $at->copy()->addMinutes(3),
            'expires_at' => $at->copy()->addMinutes(31),
            'last_seen_at' => $at->copy()->addMinutes(6),
        ]);

        $otpDelivery = DeliveryAttempt::factory()->forRecipient($recipient)->purpose(DeliveryPurpose::Otp)->sent()->create([
            'queued_at' => $at->copy()->addMinute(),
            'sent_at' => $at->copy()->addMinutes(1)->addSeconds(10),
        ]);
        $challenge = AuthChallenge::factory()->forSession($session)->consumed()->create([
            'delivery_attempt_id' => $otpDelivery->id,
            'created_at' => $at->copy()->addMinute(),
            'expires_at' => $at->copy()->addMinutes(11),
            'consumed_at' => $at->copy()->addMinutes(3),
        ]);

        $this->audit($envelope, AuditEventType::ChallengeSent, $at->copy()->addMinute(), recipient: $recipient, ip: $ip, ua: $ua, payload: ['channel' => 'email']);
        $this->audit($envelope, AuditEventType::ChallengeVerified, $at->copy()->addMinutes(3), recipient: $recipient, ip: $ip, ua: $ua);
        $this->audit($envelope, AuditEventType::SessionStarted, $at->copy()->addMinutes(3), recipient: $recipient, ip: $ip, ua: $ua, payload: ['session' => $session->ulid]);

        $signedAt = $at->copy()->addMinutes(6);
        $recipient->update(['status' => RecipientStatus::Signed, 'signed_at' => $signedAt]);

        $fields = $recipient->fields()->get();
        $acceptance = SignatureAcceptance::factory()->forRecipient($recipient, $version)->create([
            'signing_session_id' => $session->id,
            'auth_challenge_id' => $challenge->id,
            'accepted_at' => $signedAt,
            'ip_address' => $ip,
            'user_agent' => $ua,
            'document_sha256' => $version->sha256,
            'fields_snapshot' => $fields->map(fn (SigningField $f) => [
                'ulid' => $f->ulid, 'type' => $f->type->value, 'page' => $f->page,
                'x' => (float) $f->x, 'y' => (float) $f->y, 'width' => (float) $f->width, 'height' => (float) $f->height,
            ])->all(),
            'created_at' => $signedAt,
        ]);

        foreach ($fields as $field) {
            SigningFieldValue::factory()->forField($field, $acceptance)->create([
                // `copy()` é obrigatório: Carbon é mutável e `setTimezone()` mudaria o
                // próprio $signedAt para o fuso local, fazendo tudo o que é gravado depois
                // (sessão, link e o evento `acceptance.recorded`) sair três horas atrás do
                // aceite — o mesmo fato com dois horários nas telas de evidência.
                'value_text' => $field->type->value === 'date'
                    ? $signedAt->copy()->setTimezone($envelope->organization->timezone)->format('d/m/Y')
                    : null,
                'created_at' => $signedAt,
            ]);
        }

        $session->update(['status' => SigningSessionStatus::Consumed, 'consumed_at' => $signedAt]);
        $link->update(['use_count' => 2, 'last_used_at' => $signedAt]);

        $this->audit($envelope, AuditEventType::AcceptanceRecorded, $signedAt, recipient: $recipient, ip: $ip, ua: $ua, payload: [
            'acceptance' => $acceptance->ulid,
            'document_sha256' => $version->sha256,
            'signature_kind' => $acceptance->signature_kind?->value,
        ]);
    }

    private function finalize(Envelope $envelope, DocumentVersion $sentVersion, Carbon $at, bool $complete, bool $signedByCompany): Envelope
    {
        $envelope->update(['status' => EnvelopeStatus::Finalizing, 'finalization_key' => (string) Str::ulid(), 'updated_at' => $at]);
        $this->audit($envelope, AuditEventType::EnvelopeFinalizing, $at, payload: ['finalization_key' => $envelope->finalization_key]);

        if (! $complete) {
            return $envelope->refresh();
        }

        $document = $sentVersion->document;
        $consolidated = DocumentVersion::factory()->forDocument($document)->consolidated()->create(['created_at' => $at->copy()->addSeconds(20)]);
        $this->audit($envelope, AuditEventType::EnvelopeConsolidated, $at->copy()->addSeconds(20), payload: ['sha256' => $consolidated->sha256]);

        $evidence = DocumentVersion::factory()->forDocument($document)->evidence()->create(['created_at' => $at->copy()->addSeconds(40)]);
        $this->audit($envelope, AuditEventType::EnvelopeEvidenceGenerated, $at->copy()->addSeconds(40), payload: ['sha256' => $evidence->sha256]);

        $final = DocumentVersion::factory()->forDocument($document)->final($signedByCompany)->create([
            'page_count' => ($consolidated->page_count ?? 1) + 1,
            'created_at' => $at->copy()->addSeconds(60),
        ]);

        $certificate = $signedByCompany
            ? CertificateReference::query()->whereNull('organization_id')->where('is_active', true)->first()
            : null;

        if ($signedByCompany) {
            $this->audit($envelope, AuditEventType::EnvelopeSignedCompanyA1, $at->copy()->addSeconds(55), payload: [
                'profile' => 'PAdES-B-B', 'environment' => 'test', 'certificate' => $certificate?->name,
            ]);
        }

        $completedAt = $at->copy()->addSeconds(65);
        $envelope->update([
            'status' => EnvelopeStatus::Completed,
            'final_document_version_id' => $final->id,
            'completed_at' => $completedAt,
            'updated_at' => $completedAt,
        ]);

        $original = $document->versions()->where('kind', DocumentVersionKind::Original->value)->first();

        $record = VerificationRecord::factory()->forEnvelope($envelope)->create([
            'original_sha256' => $original?->sha256,
            'sent_sha256' => $sentVersion->sha256,
            'consolidated_sha256' => $consolidated->sha256,
            'final_sha256' => $final->sha256,
            'signature_status' => $signedByCompany ? 'company_a1' : 'none',
            'signature_profile' => $signedByCompany ? 'PAdES-B-B' : null,
            'certificate_reference_id' => $certificate?->id,
            /*
             * Forma real de `validation_result` (OperatorSignature::validationPayload):
             * envelope com os fatos negativos explícitos e o resultado bruto em `result`.
             *
             * `result` fica **null** de propósito: os bytes deste envelope de demonstração
             * são de fábrica e nenhuma `pdftool validate` rodou sobre eles. Fabricar
             * `all_intact = true` faria a página pública afirmar uma integridade que
             * ninguém verificou — exatamente o que a arquitetura §2 proíbe. A tela então
             * mostra o caminho honesto ("resultado não registrado"), que é a verdade
             * sobre este dado.
             */
            'validation_result' => $signedByCompany ? [
                'signed' => true,
                'profile' => 'PAdES-B-B',
                'environment' => 'test',
                'validated_at' => $completedAt->toIso8601String(),
                'timestamp' => null,
                'long_term_validation' => false,
                'revocation' => 'not_checked',
                'reason' => 'demo_seed_not_validated',
                'result' => null,
            ] : null,
            'validated_at' => $signedByCompany ? $completedAt : null,
            'created_at' => $completedAt,
        ]);

        $this->audit($envelope, AuditEventType::EnvelopeCompleted, $completedAt, payload: [
            'verification_code' => $record->code,
            'final_sha256' => $final->sha256,
            'signature_status' => $record->signature_status->value,
        ]);

        foreach ($envelope->recipients as $recipient) {
            RecipientAccessLink::factory()->forRecipient($recipient)->download()->create([
                'document_version_id' => $final->id,
                'expires_at' => $completedAt->copy()->addDays(90),
                'created_at' => $completedAt,
            ]);
            DeliveryAttempt::factory()->forRecipient($recipient)->purpose(DeliveryPurpose::Completed)->delivered()->create([
                'queued_at' => $completedAt,
                'sent_at' => $completedAt->copy()->addSeconds(15),
                'delivered_at' => $completedAt->copy()->addSeconds(40),
            ]);
        }

        return $envelope->refresh();
    }

    private function refuse(Envelope $envelope, Recipient $recipient, Carbon $at): Envelope
    {
        $ip = fake()->ipv4();

        if ($recipient->status === RecipientStatus::Pending) {
            $this->notify($recipient, $envelope->sentVersion, $at->copy()->subMinutes(30));
        }

        $recipient->update([
            'status' => RecipientStatus::Refused,
            'refused_at' => $at,
            'refusal_reason' => 'Valores divergentes do combinado na proposta.',
        ]);
        $this->audit($envelope, AuditEventType::InvitationOpened, $at->copy()->subMinutes(4), recipient: $recipient, ip: $ip);
        $this->audit($envelope, AuditEventType::RecipientRefused, $at, recipient: $recipient, ip: $ip, payload: ['reason' => $recipient->refusal_reason]);

        $envelope->update(['status' => EnvelopeStatus::Refused, 'refused_at' => $at, 'updated_at' => $at]);
        $this->audit($envelope, AuditEventType::EnvelopeRefused, $at->copy()->addSecond(), payload: ['policy' => 'close_envelope']);

        foreach ($envelope->recipients()->where('status', '!=', RecipientStatus::Refused->value)->whereNotIn('status', ['signed'])->get() as $other) {
            $other->update(['status' => RecipientStatus::Canceled]);
        }

        DeliveryAttempt::factory()->forRecipient($recipient)->purpose(DeliveryPurpose::Refused)->sent()->create([
            'to_address' => $envelope->creator->email,
            'queued_at' => $at,
            'sent_at' => $at->copy()->addSeconds(10),
        ]);

        return $envelope->refresh();
    }

    /**
     * @param  Collection<int, Recipient>  $recipients
     */
    private function expire(Envelope $envelope, Collection $recipients, Carbon $expiresAt): Envelope
    {
        $expiredAt = $expiresAt->copy()->addMinutes(7);

        foreach ($recipients as $recipient) {
            if (! $recipient->refresh()->status->isTerminal()) {
                $recipient->update(['status' => RecipientStatus::Expired]);
            }
        }

        $envelope->update(['status' => EnvelopeStatus::Expired, 'expired_at' => $expiredAt, 'updated_at' => $expiredAt]);
        $this->audit($envelope, AuditEventType::EnvelopeExpired, $expiredAt, payload: ['expires_at' => $expiresAt->toIso8601String(), 'trigger' => 'scheduler']);

        return $envelope->refresh();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function audit(
        Envelope $envelope,
        AuditEventType $type,
        Carbon $at,
        ?User $user = null,
        ?Recipient $recipient = null,
        ?string $ip = null,
        ?string $ua = null,
        array $payload = [],
    ): AuditEvent {
        $factory = AuditEvent::factory()->forEnvelope($envelope)->ofType($type, $payload)->at($at);

        $factory = match (true) {
            $user !== null => $factory->byUser($user, $ip ?? '203.0.113.'.fake()->numberBetween(2, 200)),
            $recipient !== null => $factory->byRecipient($recipient, $ip ?? '198.51.100.'.fake()->numberBetween(2, 200)),
            default => $factory->bySystem(),
        };

        return $factory->create(array_filter(['user_agent' => $ua, 'created_at' => $at], fn ($v) => $v !== null));
    }
}
