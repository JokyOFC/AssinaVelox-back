<?php

use App\Enums\AuditEventType;
use App\Enums\DocumentVersionKind;
use App\Enums\EnvelopeStatus;
use App\Models\AuditEvent;
use App\Models\DocumentVersion;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../Support/OrganizationHelpers.php';
require_once __DIR__.'/../Sign/Support/SignerHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão adversarial — posse do link não pode valer por identidade confirmada
|--------------------------------------------------------------------------
| `sign.download` é a única rota do grupo `assinar/{token}` que fica FORA de
| `signer.verified` (routes/web.php:86). A autorização é feita no controller como
| "existe aceite deste destinatário OU existe sessão viva"
| (app/Http/Controllers/Sign/DownloadController.php:64).
|
| O primeiro ramo dessa disjunção nunca expira e não olha o navegador: depois que o
| destinatário assina, QUALQUER pessoa que tenha a URL do convite — e-mail encaminhado,
| caixa compartilhada, histórico de um computador de uso comum, backup de mailbox —
| baixa o comprovante de aceite e, quando o envelope conclui, o PDF final assinado, sem
| nunca ter recebido o código por e-mail.
|
| Isso contradiz a própria razão de existir do fluxo: `sign.document` (o PDF ainda não
| assinado) EXIGE sessão, enquanto `sign.download/signed` (o PDF final, que carrega todas
| as assinaturas) não exige. O justificativa registrada no controller cobre apenas a
| janela imediata depois do aceite, quando a sessão acabou de ser consumida — não uma
| autorização permanente por posse do link.
|
| De quebra, cada GET sem sessão grava `envelope.downloaded` na trilha com o destinatário
| como ator, fabricando evidência de que o signatário baixou o arquivo.
*/

beforeEach(function () {
    $this->work = storage_path('framework/testing/review-download-'.uniqid());
    File::ensureDirectoryExists($this->work);
    signerDisk($this->work);
    $this->withoutVite();
    $this->codes = signerCaptureCodes();
});

afterEach(function () {
    File::deleteDirectory($this->work ?? '');
});

if (! function_exists('reviewSignedEnvelope')) {
    /**
     * Assina pelo caminho público real e devolve o contexto do envelope + o token.
     *
     * @return array<string, mixed>
     */
    function reviewSignedEnvelope(object $test): array
    {
        $ctx = signerEnvelope();
        $token = $ctx['tokens']['maria@exemplo.test'];
        $props = authenticateSigner($test, $token);

        $test->post(route('sign.complete', ['token' => $token]), [
            'authorization' => $props['authorization']['token'],
            'consent' => true,
            'signature' => ['method' => 'draw', 'image_base64' => pngDataUri()],
        ])->assertRedirect();

        return $ctx + ['token' => $token];
    }
}

it('não entrega o comprovante nem o PDF final a quem só tem o link e nunca confirmou o código', function () {
    $ctx = reviewSignedEnvelope($this);
    $token = $ctx['token'];

    // Outro navegador: mesma URL de convite, nenhuma sessão de assinatura.
    $this->flushSession();

    $this->get(route('sign.download', ['token' => $token, 'type' => 'evidence']))
        ->assertNotFound();

    // Finalização (incremento 4) simulada só para alcançar o ramo `signed` do controller.
    $envelope = $ctx['envelope']->fresh();
    $bytes = signerPdfBytes('final');
    $path = sprintf('orgs/%s/envelopes/%s/final.pdf', $ctx['organization']->ulid, $envelope->ulid);
    Storage::disk('documents')->put($path, $bytes);

    $final = DocumentVersion::factory()->forDocument($envelope->document)->create([
        'kind' => DocumentVersionKind::Final,
        'storage_path' => $path,
        'mime_type' => 'application/pdf',
        'size_bytes' => strlen($bytes),
        'sha256' => hash('sha256', $bytes),
        'page_count' => 2,
    ]);

    $envelope->forceFill([
        'status' => EnvelopeStatus::Completed,
        'completed_at' => now(),
        'final_document_version_id' => $final->id,
    ])->save();

    $this->flushSession();

    $this->get(route('sign.download', ['token' => $token, 'type' => 'signed']))
        ->assertNotFound();
});

it('não grava envelope.downloaded quando o pedido não tem sessão de assinatura', function () {
    $ctx = reviewSignedEnvelope($this);

    AuditEvent::query()->where('event_type', AuditEventType::EnvelopeDownloaded->value)->delete();

    $this->flushSession();
    $this->get(route('sign.download', ['token' => $ctx['token'], 'type' => 'evidence']));

    expect(AuditEvent::query()->where('event_type', AuditEventType::EnvelopeDownloaded->value)->count())
        ->toBe(0);
});
