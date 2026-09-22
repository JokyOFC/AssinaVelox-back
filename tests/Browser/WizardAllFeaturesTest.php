<?php

use App\Models\Envelope;
use App\Models\Subscription;
use Database\Seeders\DemoOrganizationSeeder;
use Illuminate\Http\UploadedFile;
use Tests\Feature\Pdf\Support\PdfFixtures;

require_once __DIR__.'/Support/BrowserHelpers.php';
require_once __DIR__.'/../Feature/Support/OrganizationHelpers.php';

/*
|--------------------------------------------------------------------------
| O wizard monta com TODOS os recursos ligados
|--------------------------------------------------------------------------
| Cada onda das Fases 2 e 3 foi testada com a própria flag ligada e as demais desligadas. A
| combinação "tudo ligado ao mesmo tempo" — interruptor global E plano — só apareceu na máquina
| do proprietário. Este teste a reproduz nos dois primeiros passos do wizard (documento e
| participantes, que concentram os recursos: modelos, vários arquivos, lembretes, papéis,
| canais, PIN, captura, verificação facial com documento, vídeo, idioma, etapas e delegação) e
| afirma que a página monta sem erro de JavaScript e sem texto em inglês.
|
| Sem documento o passo 2 é rebaixado ao passo 1 (`wizardStep()`), por isso o arquivo é enviado
| pela rota real com o cliente de teste, como em PreparationTest — o plugin não atende multipart.
*/

beforeEach(function () {
    if (! PdfFixtures::available()) {
        $this->markTestSkipped(PdfFixtures::skipMessage());
    }

    $this->work = browserWorkspace();
    config()->set('pdftool.tmp_path', $this->work.DIRECTORY_SEPARATOR.'pdftool-tmp');
    browserDocumentsDisk($this->work.DIRECTORY_SEPARATOR.'disco-documents');

    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner(['name' => 'Tudo Ligado Ltda']);
    $owner->forceFill(['password' => bcrypt(browserPassword())])->save();

    foreach (array_keys((array) config('assinavelox.features')) as $flag) {
        config()->set("assinavelox.features.{$flag}", true);
    }

    // As mesmas chaves de plano que o seeder de demonstração liga na Horizonte.
    $keys = [];
    $seeder = new ReflectionClass(DemoOrganizationSeeder::class);
    foreach (['PHASE2_PLAN_FEATURES', 'PHASE3_PART1_PLAN_FEATURES', 'PHASE3_WAVE_F_PLAN_FEATURES', 'PHASE3_WAVE_G_PLAN_FEATURES', 'PHASE4_PLAN_FEATURES'] as $constant) {
        $keys = array_merge($keys, (array) $seeder->getConstant($constant));
    }

    $plan = Subscription::query()->where('organization_id', $organization->id)->latest('id')->firstOrFail()->plan;
    $plan->forceFill(['features' => array_replace((array) $plan->features, array_fill_keys($keys, true))])->save();

    $this->organization = $organization;
    $this->owner = $owner->fresh();
    $this->pdfPath = browserPdf($this->work.DIRECTORY_SEPARATOR.'contrato.pdf', 'Contrato com tudo ligado');
});

afterEach(function () {
    browserCleanup($this->work ?? null);
});

it('abre a nova solicitação com todos os recursos ligados sem erro de JavaScript', function () {
    $page = visit('/login');
    browserLogin($page, $this->owner->email);
    $page->assertPathIs('/dashboard')->assertNoJavaScriptErrors();

    $page->navigate('/documentos/nova');
    $page->assertPathContains('/editar');

    $envelope = browserWaitFor(
        fn () => Envelope::query()->where('organization_id', $this->organization->id)->latest('id')->first(),
        'o envelope rascunho ser criado',
    );

    $page->assertSee('Informações do documento')
        ->assertSee('Ou comece por um modelo')
        ->assertNoJavaScriptErrors();
    browserAssertNoEnglish($page, 'wizard com tudo ligado · documento');

    $this->actingAs($this->owner)
        ->withSession(['current_organization_id' => $this->organization->id])
        ->post(route('envelopes.document.store', ['envelope' => $envelope->ulid]), [
            'file' => new UploadedFile($this->pdfPath, 'contrato.pdf', 'application/pdf', null, true),
        ])
        ->assertRedirect();
    auth()->forgetGuards();
    browserWaitForDocumentReady($envelope);

    $page->navigate('/documentos/'.$envelope->ulid.'/editar?step=2');
    $page->assertSee('Ordem de assinatura')
        ->assertNoJavaScriptErrors();
    browserAssertNoEnglish($page, 'wizard com tudo ligado · participantes');

    // Um participante novo monta todos os controles por participante de uma vez: fotos,
    // verificação facial com documento (Fase 4 §4.1), vídeo, PIN e idioma.
    $page->click('internal:role=button[name="Adicionar signatário"s]');
    $page->assertSee('Fotos antes do aceite')
        ->assertSee('Verificação facial com documento pelo provedor')
        ->assertNoJavaScriptErrors();
    browserAssertNoEnglish($page, 'wizard com tudo ligado · participante novo');
});
