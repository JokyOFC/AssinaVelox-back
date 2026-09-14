<?php

use App\Enums\AuditEventType;
use Illuminate\Support\Facades\Notification;

require_once __DIR__.'/../../Phase3/Risk/Support/RiskHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão 3A — força bruta por rede atribuída à organização errada
|--------------------------------------------------------------------------
| RiskDetector::challengeFailed() conta as falhas do IP em TODAS as organizações, mas grava o
| sinal na organização do evento que cruzou o limiar. Resultado: uma organização com UMA
| tentativa errada recebe o sinal (e a explicação "muitas tentativas erradas ... em links de
| assinatura desta conta"), enquanto a organização em que ocorreram as outras falhas não
| recebe nada. A regra dispara com dado de outra organização.
| Limiar de teste (riskEnable): 5 por rede.
*/

beforeEach(function (): void {
    riskEnable();
    Notification::fake();
});

test('uma organização com uma única falha não recebe sinal de força bruta por causa das falhas de outra organização', function () {
    ['organization' => $attacked] = createOrganizationWithOwner();
    ['organization' => $bystander] = createOrganizationWithOwner();

    foreach (range(1, 4) as $i) {
        riskAuditEvent($attacked, AuditEventType::ChallengeFailed, ['ip_address' => '203.0.113.50']);
    }

    // Uma única tentativa errada num link da outra organização, do mesmo IP.
    riskAuditEvent($bystander, AuditEventType::ChallengeFailed, ['ip_address' => '203.0.113.50']);

    expect(riskSignalsOf($bystander, 'code_brute_force'))->toHaveCount(0);
});
