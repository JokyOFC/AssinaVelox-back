<?php

namespace Database\Seeders;

use App\Enums\AccessLinkPurpose;
use App\Enums\AuditEventType;
use App\Enums\DeliveryPurpose;
use App\Enums\DocumentSourceType;
use App\Enums\DocumentVersionKind;
use App\Enums\EnvelopeStatus;
use App\Enums\MembershipRole;
use App\Enums\RecipientStatus;
use App\Enums\SigningOrder;
use App\Enums\SigningSessionStatus;
use App\Models\AuditEvent;
use App\Models\AuthChallenge;
use App\Models\CertificateReference;
use App\Models\DeliveryAttempt;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Envelope;
use App\Models\Folder;
use App\Models\Membership;
use App\Models\MembershipInvitation;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\PlanConsumption;
use App\Models\Recipient;
use App\Models\RecipientAccessLink;
use App\Models\SignatureAcceptance;
use App\Models\SigningField;
use App\Models\SigningFieldValue;
use App\Models\SigningSession;
use App\Models\Subscription;
use App\Models\User;
use App\Models\VerificationRecord;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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

        MembershipInvitation::factory()->create([
            'organization_id' => $org->id,
            'email' => 'novo.colaborador@horizonte.demo',
            'role' => MembershipRole::Member,
            'invited_by_user_id' => $admin->id,
        ]);

        $subscription = Subscription::factory()
            ->forOrganization($org)->ofPlan($plan)->active()
            ->create(['provider' => 'mercadopago']);

        Payment::factory()->forSubscription($subscription)->approved()->create();
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
