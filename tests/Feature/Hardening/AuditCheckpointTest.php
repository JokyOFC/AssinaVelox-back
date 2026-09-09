<?php

use App\Models\AuditEvent;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../Support/OrganizationHelpers.php';

/*
|--------------------------------------------------------------------------
| H-SEC §3 — checkpoint encadeado da trilha
|--------------------------------------------------------------------------
| O que o checkpoint entrega é DETECÇÃO, não impedimento. Estes testes cobram exatamente
| isso: que a cadeia se feche quando nada foi tocado e que ela ACUSE quando alguém edita a
| trilha por baixo — que é o único cenário em que ela tem algum valor.
*/

beforeEach(function () {
    $this->work = storage_path('framework/testing/hardening-checkpoint-'.uniqid());
    File::ensureDirectoryExists($this->work);

    config()->set('filesystems.disks.documents', [
        'driver' => 'local',
        'root' => $this->work,
        'visibility' => 'private',
        'serve' => false,
        'throw' => true,
        'report' => false,
    ]);
    Storage::forgetDisk('documents');

    ['organization' => $this->organization] = createOrganizationWithOwner();
});

afterEach(function () {
    File::deleteDirectory($this->work ?? '');
});

function seedAuditEvents(int $count, Organization $organization, ?CarbonImmutable $at = null): void
{
    $at ??= CarbonImmutable::now()->subHour();

    for ($i = 0; $i < $count; $i++) {
        AuditEvent::factory()->for($organization)->create([
            'occurred_at' => $at->addSeconds($i),
        ]);
    }
}

it('exporta o lote para o disco privado e encadeia o resumo', function () {
    seedAuditEvents(5, $this->organization);

    $this->artisan('audit:checkpoint')->assertExitCode(0);

    $first = DB::table('audit_checkpoints')->orderBy('sequence')->first();

    expect($first)->not->toBeNull()
        ->and((int) $first->sequence)->toBe(1)
        ->and((int) $first->event_count)->toBe(5)
        ->and($first->previous_sha256)->toBeNull()
        ->and($first->chain_sha256)->toMatch('/^[0-9a-f]{64}$/');

    // O arquivo existe no disco privado, começa pelo cabeçalho e traz uma linha por evento.
    expect(Storage::disk('documents')->exists($first->storage_path))->toBeTrue();

    $content = (string) Storage::disk('documents')->get($first->storage_path);
    $lines = explode("\n", trim($content));
    $header = json_decode($lines[0], true);

    expect($header['format'])->toBe('assinavelox.audit-checkpoint/1')
        ->and($header['event_count'])->toBe(5)
        ->and(count($lines) - 1)->toBe(5)
        ->and(hash('sha256', $content))->toBe($first->file_sha256);

    // Segundo lote: eventos posteriores ao fim da janela já fechada. O elo anterior do
    // novo checkpoint tem de ser o elo do primeiro.
    seedAuditEvents(3, $this->organization, CarbonImmutable::now()->addMinute());
    $this->artisan('audit:checkpoint --until='.CarbonImmutable::now()->addHour()->toIso8601String())
        ->assertExitCode(0);

    $second = DB::table('audit_checkpoints')->orderByDesc('sequence')->first();

    expect((int) $second->sequence)->toBe(2)
        ->and($second->previous_sha256)->toBe($first->chain_sha256)
        ->and($second->chain_sha256)->not->toBe($first->chain_sha256);

    $this->artisan('audit:checkpoint --verify')->assertExitCode(0);
});

it('acusa quando um evento já exportado é reescrito no banco', function () {
    seedAuditEvents(4, $this->organization);
    $this->artisan('audit:checkpoint')->assertExitCode(0);
    $this->artisan('audit:checkpoint --verify')->assertExitCode(0);

    $row = DB::table('audit_checkpoints')->orderBy('sequence')->first();

    /*
     * Adulteração por quem tem privilégio no banco: um UPDATE direto, que o model
     * append-only jamais emitiria. É o cenário que o checkpoint existe para expor.
     *
     * O procedimento de conferência do operador: reexportar a MESMA janela e comparar o
     * `events_sha256` com o que ficou registrado. Se a trilha foi reescrita, os dois
     * valores divergem.
     */
    $event = DB::table('audit_events')->orderBy('id')->first();
    DB::table('audit_events')->where('id', $event->id)->update(['ip_address' => '198.51.100.66']);

    Artisan::call('audit:checkpoint', [
        '--since' => CarbonImmutable::parse($row->period_start)->toIso8601String(),
        '--until' => CarbonImmutable::parse($row->period_end)->toIso8601String(),
        '--json' => true,
        '--allow-empty' => true,
    ]);

    /** @var array<string, mixed> $reexport */
    $reexport = json_decode(Artisan::output(), true);

    expect($reexport['event_count'])->toBe((int) $row->event_count)
        ->and($reexport['events_sha256'])->not->toBe($row->events_sha256);
});

it('acusa quando o arquivo exportado é alterado no disco', function () {
    seedAuditEvents(3, $this->organization);
    $this->artisan('audit:checkpoint')->assertExitCode(0);

    $row = DB::table('audit_checkpoints')->orderBy('sequence')->first();
    $content = (string) Storage::disk('documents')->get($row->storage_path);

    Storage::disk('documents')->put($row->storage_path, str_replace('"actor_type"', '"ator"', $content));

    $this->artisan('audit:checkpoint --verify')
        ->expectsOutputToContain('alterado')
        ->assertExitCode(1);
});

it('acusa quando um checkpoint some da cadeia', function () {
    // Três janelas fechadas, uma por hora, para que a cadeia tenha três elos.
    seedAuditEvents(2, $this->organization, CarbonImmutable::now()->subHours(3));
    $this->artisan('audit:checkpoint --until='.CarbonImmutable::now()->subHours(2)->toIso8601String())->assertExitCode(0);

    seedAuditEvents(2, $this->organization, CarbonImmutable::now()->subHours(2));
    $this->artisan('audit:checkpoint --until='.CarbonImmutable::now()->subHour()->toIso8601String())->assertExitCode(0);

    seedAuditEvents(2, $this->organization, CarbonImmutable::now()->subHour());
    $this->artisan('audit:checkpoint')->assertExitCode(0);

    expect(DB::table('audit_checkpoints')->count())->toBe(3);

    // Alguém apaga o elo do meio para esconder o período que ele cobre.
    DB::table('audit_checkpoints')->where('sequence', 2)->delete();

    $this->artisan('audit:checkpoint --verify')->assertExitCode(1);
});

it('não grava lote vazio sem que se peça explicitamente', function () {
    $this->artisan('audit:checkpoint')->assertExitCode(0);

    expect(DB::table('audit_checkpoints')->count())->toBe(0);

    $this->artisan('audit:checkpoint --allow-empty')->assertExitCode(0);

    expect(DB::table('audit_checkpoints')->count())->toBe(1);
});

it('recusa um período invertido em vez de gravar um lote sem sentido', function () {
    $this->artisan('audit:checkpoint --since=2026-09-08 --until=2026-09-01')
        ->assertExitCode(1);

    expect(DB::table('audit_checkpoints')->count())->toBe(0);
});

it('diz, na própria saída, que o checkpoint precisa sair deste sistema para valer como prova', function () {
    seedAuditEvents(1, $this->organization);

    $this->artisan('audit:checkpoint')
        ->expectsOutputToContain('FORA deste sistema')
        ->assertExitCode(0);
});
