<?php

use App\Models\Recipient;
use App\Notifications\Envelopes\RecipientSignedNotification;
use App\Services\Retention\RetentionRunner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Phase2/Retention/Support/RetentionHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão adversarial da onda C (ciclo de vida) — notificações do sino sobrevivem à exclusão
|--------------------------------------------------------------------------
| RetentionCategory::Completed->deletes() promete apagar os "Participantes (nomes, e-mails,
| telefones)" e o documento; `preserves()` lista só o recibo sem dado pessoal, o aviso público
| e a trilha. As notificações do canal `database` (sino do app) guardam o título do documento,
| o ULID do envelope e o NOME do participante (RecipientSignedNotification::toArray()).
| EnvelopePurger não toca a tabela `notifications`: depois da exclusão por retenção o nome do
| signatário e o título continuam no banco, ligados ao ULID do envelope "excluído".
*/

beforeEach(function () {
    Storage::fake('documents');
    ['organization' => $this->organization, 'owner' => $this->owner] = createOrganizationWithOwner();
    retentionEnable($this->organization);
});

it('a exclusão por retenção não deixa o nome do participante nem o título nas notificações do sino', function () {
    $ctx = retentionFinishedEnvelope($this->organization, $this->owner, 2000);
    $envelope = $ctx['envelope'];
    $recipient = Recipient::withoutOrganizationScope()->where('envelope_id', $envelope->id)->firstOrFail();

    // Exatamente o que o canal `database` grava para "participante assinou".
    $data = (new RecipientSignedNotification($envelope, $recipient, (string) Str::ulid(), 1, 1))->toArray($this->owner);

    DB::table('notifications')->insert([
        'id' => (string) Str::uuid(),
        'type' => RecipientSignedNotification::class,
        'notifiable_type' => $this->owner->getMorphClass(),
        'notifiable_id' => $this->owner->id,
        'data' => json_encode($data),
        'created_at' => now()->subDays(2000),
        'updated_at' => now()->subDays(2000),
    ]);

    expect($data['recipient_name'])->toBe($recipient->name);

    retentionPolicyFor($this->organization, ['completed' => 1825]);

    app(RetentionRunner::class)->run();

    expect(retentionEnvelopeGone($envelope))->toBeTrue();

    $leftovers = DB::table('notifications')->pluck('data')->filter(
        fn (string $json): bool => str_contains($json, $envelope->ulid) || str_contains($json, json_encode($recipient->name, JSON_UNESCAPED_UNICODE) ?: $recipient->name) || str_contains($json, $recipient->name),
    );

    expect($leftovers)->toHaveCount(0);
});
