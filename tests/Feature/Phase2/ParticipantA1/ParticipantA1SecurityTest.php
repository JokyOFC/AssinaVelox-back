<?php

use App\Enums\AuditEventType;
use App\Enums\DocumentVersionKind;
use App\Enums\EnvelopeStatus;
use App\Enums\ParticipantSignatureRequestStatus;
use App\Enums\RecipientStatus;
use App\Jobs\Envelopes\ApplyParticipantSignature;
use App\Models\AuditEvent;
use App\Models\ParticipantSignatureRequest;
use App\Services\Pdf\Exceptions\PdfToolException;
use App\Services\Signing\Certificates\Exceptions\ParticipantSignatureFailed;
use App\Services\Signing\Certificates\ParticipantCertificateConsent;
use App\Services\Signing\Certificates\ParticipantCertificateTool;
use App\Services\Signing\Certificates\ParticipantSignatureApplier;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/Support/ParticipantA1Helpers.php';

/*
|--------------------------------------------------------------------------
| Segredos, retenção mínima e recusas (roadmap T10, §2.12)
|--------------------------------------------------------------------------
| O PFX e a senha do participante nunca aparecem em banco, fila, log, trilha ou exceção. O PFX
| temporário é destruído com sucesso ou falha. Certificado vencido, senha errada e PFX sem
| chave são recusados com mensagem clara e nada é gravado.
*/

beforeEach(fn () => participantA1Boot($this));
afterEach(fn () => participantA1Teardown($this));

it('PFX e senha nunca aparecem em banco, fila, log, trilha ou exceção', function () {
    $logs = [];
    Log::listen(function (MessageLogged $event) use (&$logs): void {
        $logs[] = $event->message.' '.json_encode($event->context, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    });

    Queue::fake([ApplyParticipantSignature::class]);

    $scenario = participantA1Scenario($this->work);
    $password = 'Senha-Sigilosa-da-Maria-7731!';
    $wrong = 'Senha-Errada-da-Maria-0000!';
    $maria = participantA1Certificate($this->work.'/certs', 'Maria Alves Souza', $password, '52998224725');
    $bytes = (string) file_get_contents($maria['pfx']);
    $fragment = substr($bytes, 96, 48);
    $needles = [$password, $wrong, $fragment, base64_encode($fragment), bin2hex($fragment), base64_encode($bytes)];

    // 1. Senha errada: recusa com mensagem clara, sem ecoar a senha.
    $refused = participantA1Submit($this, $scenario, 'maria@exemplo.test', ['pfx' => $maria['pfx'], 'password' => $wrong]);
    $refused->assertStatus(422)->assertJsonPath('code', 'wrong_passphrase')->assertJsonPath('errors.password.0', 'A senha não abre este certificado. Confira a senha e tente de novo.');

    // Exceção do cliente do pdftool: mensagem, contexto e pilha sem a senha (#[\SensitiveParameter]).
    $exceptionText = '';

    try {
        app(ParticipantCertificateTool::class)->inspect($maria['pfx'], $wrong);
    } catch (PdfToolException $exception) {
        $exceptionText = (string) $exception.json_encode($exception->context()).$exception->getTraceAsString();
    }

    expect($exceptionText)->not->toBe('')->not->toContain($wrong);

    // 2. Envio correto: fica selado (cifrado) aguardando o worker.
    participantA1Submit($this, $scenario, 'maria@exemplo.test', $maria)->assertStatus(202)->assertJsonPath('stage', 'queued');

    $sealed = glob($this->work.'/selado/*.sealed');
    expect($sealed)->toHaveCount(1);
    $sealedBytes = (string) file_get_contents($sealed[0]);

    foreach ([$password, $fragment, base64_encode($fragment)] as $needle) {
        expect(str_contains($sealedBytes, $needle))->toBeFalse();
    }

    Queue::assertPushed(ApplyParticipantSignature::class, function (ApplyParticipantSignature $job) use ($needles): bool {
        $payload = serialize($job);

        foreach ($needles as $needle) {
            if (str_contains($payload, $needle)) {
                return false;
            }
        }

        return true;
    });

    // 3. A finalização monta a base e espera; o worker aplica; a finalização conclui.
    finalizationRun($scenario['envelope']);
    $request = ParticipantSignatureRequest::withoutOrganizationScope()->firstOrFail();
    expect(app(ParticipantSignatureApplier::class)->apply((int) $request->getKey()))->toBe(ParticipantSignatureApplier::APPLIED);
    finalizationRun($scenario['envelope']);
    expect($scenario['envelope']->refresh()->status)->toBe(EnvelopeStatus::Completed);

    // 4. Varredura: TODAS as tabelas, a trilha e os logs.
    $dump = '';

    foreach (DB::select("select name from sqlite_master where type = 'table'") as $table) {
        foreach (DB::table($table->name)->get() as $row) {
            $dump .= serialize((array) $row);
        }
    }

    $events = AuditEvent::withoutOrganizationScope()->get()->map(fn (AuditEvent $event): string => json_encode($event->payload) ?: '')->implode("\n");
    $log = implode("\n", $logs);

    foreach ($needles as $needle) {
        expect(str_contains($dump, $needle))->toBeFalse()
            ->and(str_contains($events, $needle))->toBeFalse()
            ->and(str_contains($log, $needle))->toBeFalse();
    }

    // O CPF completo também não é gravado (só mascarado).
    expect($dump)->not->toContain('52998224725')
        ->and($request->fresh()->holder_cpf_masked)->toBe('***.982.247-**')
        ->and(participantA1Leftovers($this))->toBe([]);
});

it('o PFX temporário é apagado mesmo quando a aplicação falha', function () {
    Queue::fake([ApplyParticipantSignature::class]);

    $scenario = participantA1Scenario($this->work);
    $maria = participantA1Certificate($this->work.'/certs', 'Maria Alves Souza', 'senha-da-Maria-Falha-3!');

    participantA1Submit($this, $scenario, 'maria@exemplo.test', $maria)->assertStatus(202);
    finalizationRun($scenario['envelope']);
    $request = ParticipantSignatureRequest::withoutOrganizationScope()->firstOrFail();
    expect(glob($this->work.'/selado/*.sealed'))->toHaveCount(1)
        ->and(participantA1Versions($scenario['document'], DocumentVersionKind::PreSignature))->toHaveCount(1);

    // Falha DEPOIS de o material ser aberto: o certificado conferido não é o que está no arquivo.
    $request->forceFill(['fingerprint_sha256' => str_repeat('a', 64)])->save();

    expect(fn () => app(ParticipantSignatureApplier::class)->apply((int) $request->getKey()))->toThrow(ParticipantSignatureFailed::class);

    $request->refresh();
    expect($request->status)->toBe(ParticipantSignatureRequestStatus::Failed)
        ->and($request->failure_code)->toBe('fingerprint_mismatch')
        ->and($request->sealed_ulid)->toBeNull()
        ->and(participantA1Versions($scenario['document'], DocumentVersionKind::SignedIncremental))->toBe([])
        ->and(AuditEvent::withoutOrganizationScope()->where('event_type', AuditEventType::ParticipantSignatureFailed->value)->exists())->toBeTrue()
        ->and(participantA1Leftovers($this))->toBe([]);

    // Recusa na entrada (senha errada): o arquivo do upload e o diretório temporário somem também.
    $file = participantA1File($maria['pfx']);
    $uploadPath = (string) $file->getRealPath();

    participantA1Submit($this, $scenario, 'maria@exemplo.test', ['pfx' => $maria['pfx'], 'password' => 'errada-errada'], [], $file)->assertStatus(422);

    expect(file_exists($uploadPath))->toBeFalse()
        ->and(participantA1Leftovers($this))->toBe([]);
});

it('recusa certificado vencido, senha errada e PFX sem chave com mensagem clara, sem gravar nada', function () {
    $scenario = participantA1Scenario($this->work);
    $dir = $this->work.'/certs';
    $expired = participantA1Certificate($dir, 'Maria Alves Souza', 'senha-vencida-1', null, -60, 30);
    $noKey = participantA1Certificate($dir, 'Maria Alves Souza', 'senha-sem-chave-2', null, 0, 30, true);
    $valid = participantA1Certificate($dir, 'Maria Alves Souza', 'senha-valida-3');

    $cases = [
        [$expired, [], 'certificate_expired', 'certificate', 'vencido'],
        [['pfx' => $valid['pfx'], 'password' => 'nao-e-esta'], [], 'wrong_passphrase', 'password', 'senha não abre'],
        [$noKey, [], 'pkcs12_without_key', 'certificate', 'chave privada'],
    ];

    foreach ($cases as [$certificate, $overrides, $code, $field, $text]) {
        $response = participantA1Submit($this, $scenario, 'maria@exemplo.test', $certificate, $overrides);

        $response->assertStatus(422)->assertJsonPath('code', $code);
        expect($response->json('errors.'.$field.'.0'))->toContain($text);
    }

    expect(ParticipantSignatureRequest::withoutOrganizationScope()->where('status', ParticipantSignatureRequestStatus::Queued->value)->exists())->toBeFalse()
        ->and(glob($this->work.'/selado/*.sealed') ?: [])->toBe([])
        ->and(participantA1Versions($scenario['document'], DocumentVersionKind::SignedIncremental))->toBe([])
        ->and(AuditEvent::withoutOrganizationScope()->where('event_type', AuditEventType::ParticipantCertificateRejected->value)->pluck('payload')->pluck('error_code')->all())
        ->toBe(['certificate_expired', 'wrong_passphrase', 'pkcs12_without_key'])
        ->and(participantA1Leftovers($this))->toBe([]);
});

it('rotula o certificado de teste como teste e o recusa onde certificados de teste não são aceitos', function () {
    $scenario = participantA1Scenario($this->work);
    $maria = participantA1Certificate($this->work.'/certs', 'Maria Alves Souza', 'senha-da-Maria-4', '52998224725');

    $preview = participantA1Preview($this, $scenario, 'maria@exemplo.test', $maria)->assertOk()->json();

    expect($preview['certificate']['is_test'])->toBeTrue()
        ->and($preview['certificate']['kind_label'])->toContain('TESTE')->toContain('não é ICP-Brasil')
        ->and($preview['certificate']['label'])->toContain('Maria Alves Souza')->toContain('TESTE')
        ->and($preview['certificate']['icp_brasil_validated'])->toBeFalse()
        ->and($preview['certificate']['holder_cpf_masked'])->toBe('***.982.247-**')
        ->and($preview['certificate']['cpf_confirmed'])->toBeFalse()
        ->and($preview['consent']['legal_review_required'])->toBeTrue()
        ->and($preview['consent']['statement'])->toContain('certificado de TESTE')
        ->and(json_encode($preview))->not->toContain('52998224725');

    // Nada foi guardado pela prévia.
    expect(ParticipantSignatureRequest::withoutOrganizationScope()->count())->toBe(0)
        ->and(participantA1Leftovers($this))->toBe([]);

    config()->set('assinavelox.participant_a1.accept_test_certificates', false);

    participantA1Submit($this, $scenario, 'maria@exemplo.test', $maria)
        ->assertStatus(422)
        ->assertJsonPath('code', 'test_certificate_not_accepted');
});

it('exige o consentimento específico e só aceita o PFX depois que o conteúdo congela', function () {
    $scenario = participantA1Scenario($this->work, [
        ['name' => 'Maria Alves Souza', 'email' => 'maria@exemplo.test', 'accepted' => true],
        ['name' => 'Joao Lima', 'email' => 'joao@exemplo.test', 'accepted' => false],
    ]);
    $scenario['envelope']->forceFill(['status' => EnvelopeStatus::InProgress])->save();
    $maria = participantA1Certificate($this->work.'/certs', 'Maria Alves Souza', 'senha-da-Maria-5');

    // Ainda falta o João: o PFX não é aceito (nem guardado); a escolha fica registrada.
    $file = participantA1File($maria['pfx']);
    $uploadPath = (string) $file->getRealPath();

    participantA1Submit($this, $scenario, 'maria@exemplo.test', $maria, [], $file)
        ->assertStatus(409)
        ->assertJsonPath('code', 'not_ready');

    expect(file_exists($uploadPath))->toBeFalse()
        ->and(glob($this->work.'/selado/*.sealed') ?: [])->toBe([])
        ->and(ParticipantSignatureRequest::withoutOrganizationScope()->firstOrFail()->status)->toBe(ParticipantSignatureRequestStatus::Requested);

    participantA1Show($this, $scenario, 'maria@exemplo.test')
        ->assertOk()
        ->assertJsonPath('stage', 'awaiting_others')
        ->assertJsonPath('can_upload', false);

    // Todos aceitaram: agora o consentimento específico é exigido, na versão vigente.
    $scenario['recipients']['joao@exemplo.test']->forceFill(['status' => RecipientStatus::Signed, 'signed_at' => now()])->save();
    $scenario['envelope']->forceFill(['status' => EnvelopeStatus::Finalizing])->save();

    participantA1Submit($this, $scenario, 'maria@exemplo.test', $maria, ['consent' => null])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['consent']);

    participantA1Submit($this, $scenario, 'maria@exemplo.test', $maria, ['consent_version' => 'v0-antiga'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['consent_version']);

    // Sem a janela deste navegador (só a posse do link), nada é aceito.
    $this->flushSession();
    $this->post(route('sign.certificate.store', ['token' => $scenario['tokens']['maria@exemplo.test']]), [
        'certificate' => participantA1File($maria['pfx']),
        'password' => $maria['password'],
        'consent' => '1',
        'consent_version' => ParticipantCertificateConsent::VERSION,
    ], ['Accept' => 'application/json'])->assertStatus(403)->assertJsonPath('code', 'not_authenticated');

    expect(glob($this->work.'/selado/*.sealed') ?: [])->toBe([]);
});
