<?php

use App\Enums\ParticipantSignatureRequestStatus;
use App\Jobs\Envelopes\ApplyParticipantSignature;
use App\Models\ParticipantSignatureRequest;
use App\Services\Signing\Certificates\ParticipantCertificateTool;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../../Phase2/ParticipantA1/Support/ParticipantA1Helpers.php';

/*
|--------------------------------------------------------------------------
| Revisão adversarial da onda C — custódia e criptografia
|--------------------------------------------------------------------------
| `ParticipantCertificateTool::run()` troca TODA ocorrência da senha do participante por
| `[REDACTED]` no stdout ANTES de decodificar o JSON (linha 203). O pdftool nunca imprime a
| senha, então essa troca só atinge dados públicos que por acaso contêm a mesma sequência:
| datas de validade, impressão digital, série, nome do titular. Uma senha curta e comum
| (o ano, "2026") corrompe `not_before`/`not_after`; o envio do certificado quebra (Carbon não
| entende "[REDACTED]-09-11…") ou grava fatos adulterados — a impressão digital adulterada
| vira `--expect-fingerprint` na aplicação.
*/

beforeEach(fn () => participantA1Boot($this, false));
afterEach(fn () => participantA1Teardown($this));

it('senha do PFX igual a um trecho público da saída (o ano da validade) não corrompe os fatos do certificado', function () {
    Queue::fake([ApplyParticipantSignature::class]);

    $scenario = participantA1Scenario($this->work);
    $year = gmdate('Y');
    $maria = participantA1Certificate($this->work.'/certs', 'Maria Alves Souza', $year);

    // 1. O próprio cliente do pdftool devolve os fatos públicos intactos.
    $inspection = app(ParticipantCertificateTool::class)->inspect($maria['pfx'], $year);

    expect($inspection->notBefore)->not->toBeNull()->not->toContain('[REDACTED]')
        ->and($inspection->notAfter)->not->toContain('[REDACTED]')
        ->and($inspection->fingerprint)->toMatch('/^[0-9a-f]{64}$/');

    // 2. A prévia mostra a validade real.
    $preview = participantA1Preview($this, $scenario, 'maria@exemplo.test', $maria)->assertOk()->json('certificate');

    expect((string) $preview['valid_from'])->not->toContain('[REDACTED]')
        ->and((string) $preview['valid_to'])->not->toContain('[REDACTED]');

    // 3. O envio é aceito e grava a validade verdadeira.
    participantA1Submit($this, $scenario, 'maria@exemplo.test', $maria)->assertStatus(202);

    $request = ParticipantSignatureRequest::withoutOrganizationScope()->firstOrFail();

    expect($request->status)->toBe(ParticipantSignatureRequestStatus::Queued)
        // A aplicação usa Date::use(CarbonImmutable) (AppServiceProvider): o cast devolve
        // CarbonImmutable, não Illuminate\Support\Carbon. O que importa é ser uma data válida.
        ->and($request->not_before)->toBeInstanceOf(CarbonInterface::class)
        ->and($request->not_before->utc()->format('Y'))->toBe($year)
        ->and($request->fingerprint_sha256)->toMatch('/^[0-9a-f]{64}$/');
});
