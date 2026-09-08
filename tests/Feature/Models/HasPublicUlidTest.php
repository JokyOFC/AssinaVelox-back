<?php

use App\Models\Envelope;
use App\Models\Organization;
use App\Models\Recipient;
use App\Models\VerificationRecord;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

it('gera ulid no creating quando vazio e usa ulid como route key', function () {
    $organization = Organization::factory()->create();
    $envelope = Envelope::factory()->create();

    expect($organization->ulid)->toHaveLength(26)
        ->and(Str::isUlid($organization->ulid))->toBeTrue()
        ->and($envelope->getRouteKeyName())->toBe('ulid')
        ->and($envelope->getRouteKey())->toBe($envelope->ulid)
        ->and(Envelope::query()->where('ulid', $envelope->ulid)->first()->is($envelope))->toBeTrue();
});

it('respeita um ulid informado explicitamente', function () {
    $ulid = (string) Str::ulid();

    $recipient = Recipient::factory()->create(['ulid' => $ulid]);

    expect($recipient->ulid)->toBe($ulid);
});

it('impede ulid duplicado', function () {
    $ulid = (string) Str::ulid();
    Envelope::factory()->create(['ulid' => $ulid]);

    expect(fn () => Envelope::factory()->create(['ulid' => $ulid]))->toThrow(QueryException::class);
});

it('usa code como route key em verification_records', function () {
    $record = VerificationRecord::factory()->create(['code' => 'ABCDEFGHJKLM']);

    expect($record->getRouteKeyName())->toBe('code')
        ->and($record->formatted_code)->toBe('ABCD-EFGH-JKLM')
        ->and(VerificationRecord::normalizeCode(' abcd-efgh jklm '))->toBe('ABCDEFGHJKLM');
});
