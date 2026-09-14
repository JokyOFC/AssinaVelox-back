<?php

use App\Services\Ltv\VerificationHashHistory;
use App\Services\Verification\PublicVerification;
use Illuminate\Support\Facades\Notification;

require_once __DIR__.'/../../Phase3/Ltv/Support/LtvHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão adversarial I-3A — o re-carimbo quebra a conferência pública do arquivo antigo
|--------------------------------------------------------------------------
| `ArchiveTimestampRefresher` publica uma nova versão final (novo SHA-256) só com `pades_ltv`
| ligada — flag que NÃO exige a decisão de produto 29. `PublicVerification::checkHash()` não
| consulta `VerificationHashHistory::match()`: o arquivo que os participantes receberam na
| conclusão (e-mail, download, dossiê) passa a ser dado como "não confere" pela verificação
| pública depois do primeiro re-carimbo, embora o histórico saiba que é o mesmo documento.
| O item está no checklist de `pades_ltv_advertise`, mas o dano acontece sem essa flag.
*/

beforeEach(function () {
    Notification::fake();
    $this->work = ktsaWorkspace($this);
    $this->pki = ltvSetup($this->work);
    $this->scenario = ltvCompletedEnvelopeWithLtaFinal($this->work, $this->pki);
});

afterEach(function () {
    ltvCleanup($this->work ?? null);
});

it('depois do re-carimbo, o arquivo final entregue na conclusão continua conferindo na verificação pública', function () {
    ['record' => $record, 'envelope' => $envelope, 'version' => $before] = $this->scenario;

    // Antes do re-carimbo: o arquivo entregue confere.
    expect(app(PublicVerification::class)->checkHash($envelope->fresh(), $before->sha256)['matches'])->toBe('signed');

    ltvRunRefresh($record, [(int) $before->getKey()]);

    // O histórico sabe que é o mesmo documento...
    expect(app(VerificationHashHistory::class)->match($record->refresh(), $before->sha256))->not->toBeNull();

    // ...mas a verificação pública (a mesma chamada do VerificationController::checkFile) diz "não confere".
    expect(app(PublicVerification::class)->checkHash($envelope->fresh(), $before->sha256)['matches'])->not->toBe('none');
});
