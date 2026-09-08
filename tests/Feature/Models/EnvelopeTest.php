<?php

use App\Enums\EnvelopeStatus;
use App\Exceptions\InvalidEnvelopeTransition;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Envelope;
use App\Models\Organization;
use App\Models\Recipient;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('atribui number sequencial por organização começando em 1', function () {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();

    $a1 = Envelope::factory()->forOrganization($orgA)->create();
    $a2 = Envelope::factory()->forOrganization($orgA)->create();
    $b1 = Envelope::factory()->forOrganization($orgB)->create();
    $a3 = Envelope::factory()->forOrganization($orgA)->create();
    $b2 = Envelope::factory()->forOrganization($orgB)->create();

    expect([$a1->number, $a2->number, $a3->number])->toBe([1, 2, 3])
        ->and([$b1->number, $b2->number])->toBe([1, 2])
        ->and($a1->display_code)->toBe('AV-00001')
        ->and($a3->display_code)->toBe('AV-00003')
        ->and(Envelope::formatDisplayCode(12345))->toBe('AV-12345');
});

it('nextNumberFor funciona dentro de uma transação e considera envelopes excluídos (soft delete)', function () {
    $organization = Organization::factory()->create();

    $first = Envelope::factory()->forOrganization($organization)->create();
    $first->delete();

    $next = DB::transaction(fn () => Envelope::nextNumberFor($organization));

    expect($next)->toBe(2);
});

it('recusa number duplicado na mesma organização (UNIQUE organization_id + number)', function () {
    $organization = Organization::factory()->create();
    Envelope::factory()->forOrganization($organization)->create(['number' => 7]);

    expect(fn () => Envelope::factory()->forOrganization($organization)->create(['number' => 7]))
        ->toThrow(QueryException::class);
});

it('permite o mesmo number em organizações diferentes', function () {
    $a = Envelope::factory()->create(['number' => 42]);
    $b = Envelope::factory()->create(['number' => 42]);

    expect($a->number)->toBe(42)->and($b->number)->toBe(42);
});

it('transitionTo aplica transições válidas sem persistir', function () {
    $envelope = Envelope::factory()->ready()->create();

    $envelope->transitionTo(EnvelopeStatus::InProgress);

    expect($envelope->status)->toBe(EnvelopeStatus::InProgress)
        ->and($envelope->sent_at)->not->toBeNull()
        ->and($envelope->isDirty('status'))->toBeTrue()
        ->and($envelope->fresh()->status)->toBe(EnvelopeStatus::Ready);

    $envelope->transitionTo(EnvelopeStatus::Finalizing);
    $envelope->transitionTo(EnvelopeStatus::Completed);

    expect($envelope->status)->toBe(EnvelopeStatus::Completed)
        ->and($envelope->completed_at)->not->toBeNull()
        ->and($envelope->isTerminal())->toBeTrue();
});

it('transitionTo lança InvalidEnvelopeTransition para transições proibidas', function (string $from, string $to) {
    $envelope = Envelope::factory()->create(['status' => EnvelopeStatus::from($from)]);

    expect(fn () => $envelope->transitionTo(EnvelopeStatus::from($to)))
        ->toThrow(InvalidEnvelopeTransition::class);

    expect($envelope->status)->toBe(EnvelopeStatus::from($from));
})->with([
    ['draft', 'in_progress'],
    ['draft', 'completed'],
    ['ready', 'finalizing'],
    ['in_progress', 'completed'],
    ['in_progress', 'draft'],
    ['finalizing', 'canceled'],
    ['finalizing', 'refused'],
    ['completed', 'canceled'],
    ['refused', 'in_progress'],
    ['expired', 'in_progress'],
    ['canceled', 'draft'],
]);

it('a exceção informa origem, destino e ulid', function () {
    $envelope = Envelope::factory()->completed()->create();

    try {
        $envelope->transitionTo(EnvelopeStatus::Canceled);
        $this->fail('Deveria lançar InvalidEnvelopeTransition.');
    } catch (InvalidEnvelopeTransition $e) {
        expect($e->from)->toBe(EnvelopeStatus::Completed)
            ->and($e->to)->toBe(EnvelopeStatus::Canceled)
            ->and($e->envelopeUlid)->toBe($envelope->ulid)
            ->and($e->getMessage())->toContain('completed → canceled');
    }
});

it('formata o código de verificação em grupos de 4', function () {
    $envelope = Envelope::factory()->create(['verification_code' => 'ABCDEFGHJKLM']);

    expect($envelope->formatted_verification_code)->toBe('ABCD-EFGH-JKLM')
        ->and(Envelope::factory()->make(['verification_code' => null])->formatted_verification_code)->toBeNull();

    $code = Envelope::generateVerificationCode();
    expect($code)->toHaveLength(12)->toMatch('/^[A-HJ-NP-Z2-9]{12}$/');
});

it('carrega relações principais', function () {
    $creator = User::factory()->create();
    $envelope = Envelope::factory()->inProgress()->create(['created_by_user_id' => $creator->id]);

    $recipients = Recipient::factory()->count(2)->forEnvelope($envelope)->create();
    Recipient::factory()->forEnvelope($envelope, 3)->signed()->create();
    $document = Document::factory()->forEnvelope($envelope)->ready()->create();
    $version = DocumentVersion::factory()->forDocument($document)->create();
    $envelope->update(['sent_document_version_id' => $version->id]);

    $envelope = $envelope->fresh();

    expect($envelope->creator->is($creator))->toBeTrue()
        ->and($envelope->organization)->toBeInstanceOf(Organization::class)
        ->and($envelope->document->is($document))->toBeTrue()
        ->and($envelope->sentVersion->is($version))->toBeTrue()
        ->and($envelope->recipients)->toHaveCount(3)
        ->and($envelope->signedCount())->toBe(1)
        ->and($envelope->statusLabel())->toBe('Em andamento')
        ->and($version->envelope->is($envelope))->toBeTrue();
});
