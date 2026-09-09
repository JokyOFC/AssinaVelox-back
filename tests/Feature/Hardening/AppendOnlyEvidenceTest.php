<?php

use App\Enums\FieldType;
use App\Models\AuditEvent;
use App\Models\DocumentVersion;
use App\Models\SignatureAcceptance;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/../Support/OrganizationHelpers.php';
require_once __DIR__.'/../Sign/Support/SignerHelpers.php';

/*
|--------------------------------------------------------------------------
| H-SEC §3 — a aplicação não emite UPDATE nem DELETE nas tabelas de evidência
|--------------------------------------------------------------------------
| O privilégio restrito no MySQL é a trava de verdade (docs/seguranca-operacional.md §3).
| Mas o privilégio só pode ser aplicado se a aplicação de fato nunca precisar desses
| comandos — do contrário o REVOKE quebra a produção no primeiro fluxo que os usasse, e
| quebra no pior lugar possível: no meio de um aceite.
|
| Este teste percorre o caminho que MAIS mexe com evidência — abrir o convite, pedir o
| código, verificar, ler o documento e aceitar — espionando o SQL emitido, e falha se
| aparecer UPDATE ou DELETE em `audit_events`, `signature_acceptances`,
| `document_versions` ou `verification_records`.
|
| Ele não prova que ninguém pode alterar essas linhas — prova que a APLICAÇÃO não precisa
| poder. Quem tem privilégio no banco continua podendo, e é exatamente por isso que existe
| o checkpoint encadeado.
*/

beforeEach(function () {
    $this->work = storage_path('framework/testing/hardening-appendonly-'.uniqid());
    File::ensureDirectoryExists($this->work);
    signerDisk($this->work);
    $this->withoutVite();
    $this->codes = signerCaptureCodes();

    $this->statements = [];
    DB::listen(function ($query): void {
        $this->statements[] = $query->sql;
    });
});

afterEach(function () {
    File::deleteDirectory($this->work ?? '');
});

/**
 * Comandos que a aplicação NÃO pode emitir, segundo a política de cada tabela
 * (`assinavelox.audit.evidence_tables`).
 *
 * @param  list<string>  $statements
 * @return list<string>
 */
function forbiddenEvidenceStatements(array $statements): array
{
    /** @var array<string, string> $policies */
    $policies = (array) config('assinavelox.audit.evidence_tables');

    return array_values(array_filter($statements, static function (string $sql) use ($policies): bool {
        $normalized = strtolower((string) preg_replace('/\s+/', ' ', $sql));

        foreach ($policies as $table => $policy) {
            $verbs = $policy === 'no_update_no_delete' ? 'update|delete' : 'delete';

            if (preg_match('/^\s*('.$verbs.')\b[^;]*\b'.preg_quote((string) $table, '/').'\b/', $normalized) === 1) {
                return true;
            }
        }

        return false;
    }));
}

it('declara a política de cada tabela de evidência', function () {
    /*
     * `verification_records` NÃO é append-only, e dizer que é seria mentira: a retentativa
     * da finalização reescreve o registro quando o arquivo final precisou ser reconstruído
     * (EnvelopeFinalizer, passo "verification_record: rewritten"). Publicar o registro de
     * uma execução descartada sobre um PDF diferente seria pior do que reescrever. O que
     * vale para ela, portanto, é a proibição de DELETE.
     */
    expect(config('assinavelox.audit.evidence_tables'))->toBe([
        'audit_events' => 'no_update_no_delete',
        'signature_acceptances' => 'no_update_no_delete',
        'document_versions' => 'no_update_no_delete',
        'verification_records' => 'no_delete',
    ]);
});

it('vai do convite ao aceite sem emitir UPDATE ou DELETE nas tabelas de evidência', function () {
    $ctx = signerEnvelope([[
        'name' => 'Maria Alves Souza',
        'email' => 'maria@exemplo.test',
        'fields' => [FieldType::Signature, FieldType::Name, FieldType::Date],
    ]]);

    $token = $ctx['tokens']['maria@exemplo.test'];

    $this->get(route('sign.show', ['token' => $token]))->assertOk();

    $props = authenticateSigner($this, $token);
    $fields = collect($props['my_fields'])->keyBy('type');

    $this->post(route('sign.complete', ['token' => $token]), [
        'authorization' => $props['authorization']['token'],
        'consent' => true,
        'signature' => ['method' => 'draw', 'image_base64' => pngDataUri()],
        'fields' => [
            $fields['name']['id'] => 'Maria A. Souza',
            $fields['date']['id'] => '09/09/2026',
        ],
    ])->assertRedirect();

    // O caminho realmente aconteceu (senão o teste passaria por não ter feito nada).
    expect(SignatureAcceptance::query()->count())->toBe(1)
        ->and(AuditEvent::query()->count())->toBeGreaterThan(0);

    expect(forbiddenEvidenceStatements($this->statements))->toBe([]);
});

it('reconhece um UPDATE proibido quando ele acontece (o detector não é decorativo)', function () {
    // Sem esta prova, um detector quebrado passaria despercebido: ele nunca acusa nada.
    $statements = [
        'update "audit_events" set "payload" = ? where "id" = ?',
        'delete from "signature_acceptances" where "id" = ?',
        'delete from "verification_records" where "id" = ?',
        // Permitidos: leitura em qualquer tabela, UPDATE onde a política é só `no_delete`
        // e qualquer comando fora das tabelas de evidência.
        'select * from "audit_events" where "id" = ?',
        'update "verification_records" set "final_sha256" = ? where "id" = ?',
        'update "envelopes" set "status" = ? where "id" = ?',
    ];

    expect(forbiddenEvidenceStatements($statements))->toHaveCount(3);
});

it('recusa alteração e remoção de evento de auditoria também no nível do model', function () {
    ['organization' => $organization] = createOrganizationWithOwner();

    $event = AuditEvent::factory()->for($organization)->create();

    expect(fn () => $event->update(['ip_address' => '203.0.113.1']))->toThrow(LogicException::class);
    expect(fn () => $event->delete())->toThrow(LogicException::class);
});

it('nenhuma tabela de evidência tem coluna de atualização', function () {
    // `updated_at` só existe onde a linha PODE mudar. Nestas ela não pode.
    expect(AuditEvent::UPDATED_AT)->toBeNull()
        ->and(DocumentVersion::UPDATED_AT)->toBeNull();

    foreach (array_keys((array) config('assinavelox.audit.evidence_tables')) as $table) {
        expect(Schema::hasColumn($table, 'updated_at'))->toBeFalse("A tabela {$table} ganhou `updated_at`.");
    }
});
