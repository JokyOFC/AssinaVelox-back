<?php

use App\Enums\MembershipRole;
use App\Models\AuditEvent;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Envelope;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Recipient;
use App\Models\SignatureAcceptance;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Envelopes\Sending\SendEnvelope;
use App\Services\Organizations\OrganizationPurge;
use App\Support\OrganizationSettings;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../Support/OrganizationHelpers.php';
require_once __DIR__.'/../Sending/Support/SendingHelpers.php';

/*
|--------------------------------------------------------------------------
| Exclusão efetiva da organização (ROUTES §2.12 e Q23)
|--------------------------------------------------------------------------
| A tela promete "Remove todos os usuários e documentos após 30 dias" e a política de
| privacidade publicada promete ao titular que "os dados são apagados dos sistemas ativos".
| Estes casos verificam que a promessa é cumprida: banco e disco.
*/

beforeEach(function (): void {
    $this->withoutVite();
    fakeEmailProvider();
});

it('apaga envelopes, documentos, aceites, membros e os arquivos no disco', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    $envelope = readyEnvelope($organization, $owner);

    actingAsMember($owner, $organization);
    app(SendEnvelope::class)->handle($envelope);
    $envelope = $envelope->fresh();

    $recipient = $envelope->recipients()->firstOrFail();

    SignatureAcceptance::query()->create([
        'recipient_id' => $recipient->id,
        'envelope_id' => $envelope->id,
        'document_version_id' => $envelope->sent_document_version_id,
        'organization_id' => $organization->id,
        'accepted_at' => now(),
        'auth_method' => 'email_otp',
        'consent_statement' => 'Declaração de aceite.',
        'document_sha256' => str_repeat('c', 64),
    ]);

    $version = DocumentVersion::withoutOrganizationScope()
        ->whereKey($envelope->sent_document_version_id)
        ->firstOrFail();

    // Os helpers de envio não escrevem bytes; a purga precisa apagar arquivo de verdade.
    Storage::disk('documents')->put($version->storage_path, '%PDF-1.7 conteudo de teste');

    expect(Storage::disk('documents')->exists($version->storage_path))->toBeTrue();

    OrganizationSettings::of($organization)->put(['deletion_requested_at' => now()->subDays(31)]);

    $receipt = app(OrganizationPurge::class)->purge($organization->fresh());

    expect($receipt['organization'])->toBe($organization->ulid)
        ->and(Organization::withTrashed()->whereKey($organization->getKey())->exists())->toBeFalse()
        ->and(Envelope::withoutOrganizationScope()->withTrashed()->where('organization_id', $organization->id)->count())->toBe(0)
        ->and(Document::withoutOrganizationScope()->where('organization_id', $organization->id)->count())->toBe(0)
        ->and(SignatureAcceptance::withoutOrganizationScope()->where('organization_id', $organization->id)->count())->toBe(0)
        ->and(AuditEvent::withoutOrganizationScope()->where('organization_id', $organization->id)->count())->toBe(0)
        ->and(Membership::query()->where('organization_id', $organization->id)->count())->toBe(0)
        ->and(Subscription::withoutOrganizationScope()->where('organization_id', $organization->id)->count())->toBe(0)
        ->and(Recipient::withoutOrganizationScope()->where('organization_id', $organization->id)->count())->toBe(0)
        ->and(Storage::disk('documents')->exists($version->storage_path))->toBeFalse()
        ->and(User::query()->whereKey($owner->getKey())->exists())->toBeFalse();
});

it('preserva o usuário que ainda participa de outra organização', function () {
    ['organization' => $primeira, 'owner' => $owner] = createOrganizationWithOwner();
    ['organization' => $segunda] = createOrganizationWithOwner();

    attachMember($segunda, MembershipRole::Member, user: $owner);

    OrganizationSettings::of($primeira)->put(['deletion_requested_at' => now()->subDays(31)]);

    app(OrganizationPurge::class)->purge($primeira->fresh());

    expect(User::query()->whereKey($owner->getKey())->exists())->toBeTrue()
        ->and(Membership::query()->where('user_id', $owner->getKey())->count())->toBe(1)
        ->and(Organization::withTrashed()->whereKey($segunda->getKey())->exists())->toBeTrue();
});

it('lista somente as organizações cuja carência já venceu', function () {
    ['organization' => $vencida] = createOrganizationWithOwner();
    ['organization' => $recente] = createOrganizationWithOwner();
    ['organization' => $semPedido] = createOrganizationWithOwner();

    OrganizationSettings::of($vencida)->put(['deletion_requested_at' => now()->subDays(31)]);
    OrganizationSettings::of($recente)->put(['deletion_requested_at' => now()->subDays(2)]);

    $due = app(OrganizationPurge::class)->due()->pluck('ulid')->all();

    expect($due)->toBe([$vencida->ulid])
        ->and($due)->not->toContain($recente->ulid)
        ->and($due)->not->toContain($semPedido->ulid);
});
