<?php

use App\Enums\AuditEventType;
use App\Jobs\Risk\EvaluateRiskEvent;
use App\Models\RiskSignal;
use App\Services\Risk\RiskSignals;
use App\Services\Risk\SubjectKeys;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/Support/RiskHelpers.php';

/*
|--------------------------------------------------------------------------
| Nenhum sinal guarda dado proibido (roadmap §3.7: minimização; T10)
|--------------------------------------------------------------------------
| Sem conteúdo de documento, sem CPF, sem e-mail, sem IP completo; sujeito só como HMAC; a fila
| só recebe IDs, HMACs e o prefixo truncado.
*/

beforeEach(function (): void {
    $this->withoutVite();
    riskEnable();
    Notification::fake();
});

test('record descarta chaves e valores proibidos, só guarda escalares curtos e trunca o IP', function () {
    ['organization' => $organization] = createOrganizationWithOwner();

    $signal = RiskSignals::record('code_brute_force', $organization, [
        'scope' => 'ip',
        'ip_prefix' => '203.0.113.77',
        'failures_in_window' => 9,
        'window_minutes' => 60,
        'cpf' => '123.456.789-09',
        'email' => 'fraude@exemplo.test',
        'recipient' => 'maria@exemplo.test',            // chave válida, valor com e-mail → fora
        'document_content' => 'CONTRATO DE LOCAÇÃO ...',  // chave proibida → fora
        'nested' => ['a' => 1],                          // não listada → fora
        'match' => '12345678901',                        // não listada nesta regra → fora
        'threshold' => str_repeat('x', 65),              // texto longo → fora
    ]);

    expect($signal->exists)->toBeTrue()
        ->and($signal->fresh()->evidence)->toBe([
            '_dropped' => 7,
            'failures_in_window' => 9,
            'ip_prefix' => '203.0.113.0/24',
            'scope' => 'ip',
            'window_minutes' => 60,
        ]);

    $stored = (string) RiskSignal::query()->whereKey($signal->id)->toBase()->value('evidence');

    expect($stored)->not->toContain('@')
        ->not->toContain('123.456')
        ->not->toContain('203.0.113.77')
        ->not->toContain('CONTRATO');
});

test('valores que parecem CPF, CNPJ ou telefone são descartados mesmo em chave permitida', function () {
    ['organization' => $organization] = createOrganizationWithOwner();

    $signal = RiskSignals::record('affiliate_self_referral', $organization, [
        'affiliate' => '12345678909',
        'referral' => '11.222.333/0001-81',
        'match' => '(11) 98765-4321',
        'same_user' => true,
    ], null, 'referral:1');

    expect($signal->evidence)->toBe(['_dropped' => 3, 'same_user' => true]);
});

test('ULID (id público opaco) é mantido mesmo com dígitos seguidos', function () {
    ['organization' => $organization] = createOrganizationWithOwner();

    $signal = RiskSignals::record('affiliate_self_referral', $organization, [
        'affiliate' => '01K12345678901234567890ABC',
        'referral' => '01K7ZZZZZZZZZZZZZZZZZZZZZZ',
    ], null, 'referral:2');

    expect($signal->evidence)->toBe([
        'affiliate' => '01K12345678901234567890ABC',
        'referral' => '01K7ZZZZZZZZZZZZZZZZZZZZZZ',
    ]);
});

test('o sujeito é gravado só como HMAC, nunca o valor bruto', function () {
    ['organization' => $organization] = createOrganizationWithOwner();

    $signal = RiskSignals::record('affiliate_self_referral', $organization, ['same_ip' => true], null, 'ip:198.51.100.23');

    expect($signal->subject_key)->toMatch('/^[0-9a-f]{64}$/')
        ->and($signal->subject_key)->toBe(SubjectKeys::digest('ip:198.51.100.23'))
        ->and(json_encode($signal->fresh()->toArray()))->not->toContain('198.51.100');
});

test('sinais gerados pelas regras não guardam e-mail de destinatário nem IP completo', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();

    riskSentEnvelope($organization, $owner, ['a@fora.test', 'b@fora.test', 'c@longe.test', 'd@longe.test']);

    foreach (range(1, 5) as $i) {
        riskAuditEvent($organization, AuditEventType::ChallengeFailed, ['ip_address' => '203.0.113.99']);
    }

    $signals = riskSignalsOf($organization);

    expect($signals->pluck('rule_code')->unique()->sort()->values()->all())->toBe(['code_brute_force', 'external_recipients_burst']);

    foreach ($signals as $signal) {
        $raw = (string) RiskSignal::query()->whereKey($signal->id)->toBase()->value('evidence');

        expect($raw)->not->toContain('@')
            ->not->toContain('203.0.113.99')
            ->not->toContain('fora.test');
    }
});

test('a fila recebe só HMAC e o prefixo da rede no cadastro, nunca o IP completo', function () {
    Queue::fake();
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();
    request()->server->set('REMOTE_ADDR', '198.51.100.23');
    request()->headers->set('X-Device-Id', 'device-abc-123');

    event(new Registered($owner));

    Queue::assertPushed(EvaluateRiskEvent::class, function (EvaluateRiskEvent $job) use ($organization): bool {
        $serialized = serialize($job);

        return $job->kind === EvaluateRiskEvent::SIGNUP
            && $job->context['organization_id'] === $organization->id
            && $job->context['ip_prefix'] === '198.51.100.0/24'
            && preg_match('/^[0-9a-f]{64}$/', (string) $job->context['ip_key']) === 1
            && preg_match('/^[0-9a-f]{64}$/', (string) $job->context['device_key']) === 1
            && ! str_contains($serialized, '198.51.100.23')
            && ! str_contains($serialized, 'device-abc-123');
    });
});

test('regra fora do catálogo fechado é recusada', function () {
    ['organization' => $organization] = createOrganizationWithOwner();

    expect(fn () => RiskSignals::record('regra_do_usuario', $organization, []))
        ->toThrow(InvalidArgumentException::class);
});

test('sinais são append-only', function () {
    ['organization' => $organization] = createOrganizationWithOwner();
    $signal = RiskSignals::record('code_brute_force', $organization, ['scope' => 'link'], null, 'link:1');

    expect(fn () => $signal->forceFill(['score' => 0])->save())->toThrow(LogicException::class)
        ->and(fn () => $signal->delete())->toThrow(LogicException::class);
});
