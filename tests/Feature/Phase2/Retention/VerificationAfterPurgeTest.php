<?php

use App\Models\RetentionDeletion;
use App\Services\Retention\RetentionRunner;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/Support/RetentionHelpers.php';

/*
|--------------------------------------------------------------------------
| Fase 2 §2.19 (K-RET) — verificação pública depois da exclusão (decisão configurável)
|--------------------------------------------------------------------------
| Padrão recomendado: aviso de remoção + só o resumo do arquivo final. `notice`: só o aviso.
| `hidden`: indistinguível de código inexistente. Nunca título, organização, participantes.
*/

beforeEach(function () {
    Storage::fake('documents');
    config()->set('inertia.ssr.enabled', false);
    ['organization' => $this->organization, 'owner' => $this->owner] = createOrganizationWithOwner(['name' => 'Imobiliária Aurora Ltda']);
    retentionEnable($this->organization);
    retentionPolicyFor($this->organization, ['completed' => 1825]);
});

function purgedVerifiable(): array
{
    $ctx = retentionFinishedEnvelope(test()->organization, test()->owner, 2000);
    $code = $ctx['envelope']->verification_code;
    $sent = $ctx['envelope']->sentVersion->sha256;

    app(RetentionRunner::class)->run();

    return $ctx + ['code' => $code, 'sent_sha256' => $sent];
}

function assertNoLeak(string $json, array $ctx): void
{
    foreach (['Contrato de locação residencial', 'Imobiliária Aurora', 'Maria', 'maria@exemplo.test', '203.0.113', $ctx['sent_sha256'], $ctx['envelope']->ulid] as $needle) {
        expect($json)->not->toContain($needle);
    }
}

it('padrão: responde que foi removido por retenção, com a data e só o resumo final, sem vazar dados', function () {
    $ctx = purgedVerifiable();

    $response = $this->get(route('verify.show', ['code' => $ctx['code']]))->assertOk();
    $props = verifyProps($response);

    expect($props['found'])->toBeTrue()
        ->and($props['result']['retention']['purged'])->toBeTrue()
        ->and($props['result']['retention']['mode'])->toBe('notice_with_final_hash')
        ->and($props['result']['retention']['purged_at'])->not->toBeNull()
        ->and($props['result']['hashes']['final_sha256'])->toBe($ctx['final_sha256'])
        ->and($props['result']['hashes']['sent_sha256'])->toBeNull()
        ->and($props['result']['organization_name'])->toBe('')
        ->and($props['result']['recipients'])->toBe([]);

    assertNoLeak(json_encode($props), $ctx);

    // A conferência por resumo ainda funciona para quem guardou a cópia final.
    $this->from(route('verify.show', ['code' => $ctx['code']]))
        ->post(route('verify.check_file', ['code' => $ctx['code']]), ['sha256' => $ctx['final_sha256']])
        ->assertSessionHas('file_check', fn (array $check): bool => $check['matches'] === 'signed');

    $this->from(route('verify.show', ['code' => $ctx['code']]))
        ->post(route('verify.check_file', ['code' => $ctx['code']]), ['sha256' => $ctx['sent_sha256']])
        ->assertSessionHas('file_check', fn (array $check): bool => $check['matches'] === 'none');
});

it('modo notice: só o aviso e a data, sem resumo nenhum', function () {
    config()->set('assinavelox.retention.verification_after_purge', 'notice');
    $ctx = purgedVerifiable();

    $props = verifyProps($this->get(route('verify.show', ['code' => $ctx['code']]))->assertOk());

    expect($props['found'])->toBeTrue()
        ->and($props['result']['hashes']['final_sha256'])->toBeNull()
        ->and($props['result']['retention']['final_hashes_count'])->toBe(0);

    $json = json_encode($props);
    assertNoLeak($json, $ctx);
    expect($json)->not->toContain($ctx['final_sha256']);

    // E o recibo nem chegou a guardar o resumo.
    expect(RetentionDeletion::withoutOrganizationScope()->where('verification_code', $ctx['code'])->value('final_hashes'))->toBeNull();
});

it('modo hidden: a resposta é idêntica à de um código inexistente', function () {
    config()->set('assinavelox.retention.verification_after_purge', 'hidden');
    $ctx = purgedVerifiable();

    $purged = verifyProps($this->get(route('verify.show', ['code' => $ctx['code']]))->assertOk());
    $missing = verifyProps($this->get(route('verify.show', ['code' => 'ABCDEFGHJKLM']))->assertOk());

    expect($purged['found'])->toBeFalse()
        ->and($purged['result'])->toBeNull()
        ->and(array_keys($purged))->toBe(array_keys($missing));
});

it('a regra vigente é o teto: trocar para notice depois esconde o resumo já guardado', function () {
    $ctx = purgedVerifiable();

    config()->set('assinavelox.retention.verification_after_purge', 'notice');

    $props = verifyProps($this->get(route('verify.show', ['code' => $ctx['code']]))->assertOk());

    expect($props['result']['hashes']['final_sha256'])->toBeNull()
        ->and(json_encode($props))->not->toContain($ctx['final_sha256']);
});
