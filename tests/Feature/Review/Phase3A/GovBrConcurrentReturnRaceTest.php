<?php

use App\Enums\DocumentVersionKind;
use App\Http\Middleware\ResolveSignerToken;
use App\Models\DocumentVersion;
use App\Services\Signing\GovBr\ExternalSignatureRequestStatus;
use App\Services\Signing\GovBr\GovBrReturnService;
use App\Services\Signing\GovBr\GovBrReturnStage;
use App\Services\Signing\GovBr\Models\ExternalSignatureRequest;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../../Phase3/GovBr/Support/GovBrHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão adversarial I-3A — duas devoluções gov.br do MESMO pedido em paralelo
|--------------------------------------------------------------------------
| GovBrReturnService::receive() confere `status === Pending` e a reserva FORA do lock do envelope,
| com o modelo carregado no início da requisição. Se outra requisição do mesmo pedido termina
| antes (duplo clique, retry do navegador), o compare-and-set desta falha (a revisão reservada não
| é mais a mais recente) e `baseChanged()` chama `releaseReservation()` sobre o modelo VELHO:
| o pedido já `completed` volta a `requested`, mas `signed_document_version_id`, `signature_kind`
| e `completed_at` continuam gravados, e a assinatura continua na cadeia.
|
| Consequências: `GovBrReturnStage::signatureCount()` passa a contar 0 devoluções embora a cadeia
| tenha uma; a finalização volta a esperar um pedido que já assinou (e o participante pode
| reservar de novo e assinar outra vez sobre a própria assinatura).
|
| O entrelaçamento é determinístico: a segunda devolução roda dentro da consulta de CPF que
| `receive()` faz entre as conferências e o lock.
*/

beforeEach(fn () => govbrBoot($this));
afterEach(fn () => govbrTeardown($this));

it('duas devoluções do mesmo pedido em paralelo: a perdedora não desfaz o pedido já concluído', function () {
    $scenario = govbrScenario($this->work);
    $maria = govbrCertificate($this->work, 'Maria Alves Souza');
    $requestUlid = govbrReserve($this, $scenario, 'maria@exemplo.test');
    $returned = govbrSimulate('sign', govbrBaseFile($scenario['base'], $this->work.'/base.pdf'), $this->work.'/devolvido.pdf', $maria);

    $fired = false;
    $inner = null;

    DB::listen(function (QueryExecuted $query) use (&$fired, &$inner, $requestUlid, $returned): void {
        if ($fired || ! str_contains($query->sql, 'signing_field_values') || ! str_contains($query->sql, 'signing_fields')) {
            return;
        }

        $insideReceive = collect(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS))
            ->contains(fn (array $frame): bool => ($frame['class'] ?? null) === GovBrReturnService::class && ($frame['function'] ?? null) === 'receive');

        if (! $insideReceive) {
            return;
        }

        $fired = true;
        $request = request();

        try {
            // A "outra aba": o mesmo participante, o mesmo pedido, o mesmo arquivo.
            app(GovBrReturnService::class)->submit(ResolveSignerToken::context($request), $request, $requestUlid, govbrFile($returned));
            $inner = 'accepted';
        } catch (Throwable $exception) {
            $inner = $exception::class.': '.$exception->getMessage();
        }
    });

    $outer = govbrUpload($this, $scenario, 'maria@exemplo.test', $requestUlid, $returned);

    $row = ExternalSignatureRequest::withoutOrganizationScope()->where('ulid', $requestUlid)->firstOrFail();
    $signedInChain = DocumentVersion::withoutOrganizationScope()
        ->where('document_id', $scenario['document']->getKey())
        ->where('kind', DocumentVersionKind::SignedIncremental->value)
        ->count();

    // Pré-condições do entrelaçamento: a segunda devolução entrou; a primeira não gravou revisão irmã.
    expect($fired)->toBeTrue()
        ->and($inner)->toBe('accepted')
        ->and($outer->status())->toBeGreaterThanOrEqual(400)
        ->and($signedInChain)->toBe(1);

    // O defeito: o pedido concluído não pode voltar a "requested" com a assinatura ainda na cadeia.
    expect($row->status)->toBe(ExternalSignatureRequestStatus::Completed)
        ->and(app(GovBrReturnStage::class)->signatureCount($scenario['document']))->toBe($signedInChain);
});
