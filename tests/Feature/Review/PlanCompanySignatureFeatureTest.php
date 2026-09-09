<?php

use App\Models\CertificateReference;
use App\Models\Plan;
use Database\Seeders\PlanSeeder;
use Symfony\Component\Finder\Finder;

require_once __DIR__.'/../Support/OrganizationHelpers.php';

/*
|--------------------------------------------------------------------------
| Revisão final (produto) — "Assinatura criptográfica da operadora" vendida como
| item de plano pago, sem nada por trás
|--------------------------------------------------------------------------
| `database/seeders/PlanSeeder.php` marca `features.company_signature`:
|
|   grátis        => false
|   profissional  => true   (R$ 49/mês)
|   empresarial   => true   (R$ 399/mês)
|
| `App\Http\Controllers\Billing\PlanController::FEATURE_LABELS` (linha 38) traduz a chave
| para "Assinatura criptográfica da operadora", e ela aparece como item incluído em duas
| telas — visto no navegador em /planos e em /configuracoes/plano:
|
|   PROFISSIONAL … 500 documentos por mês · 10 usuários · Código por e-mail ·
|   Página de evidências · **Assinatura criptográfica da operadora** · Pastas
|
| Só que `company_signature` não é lido em lugar nenhum da aplicação. Quem decide se o
| arquivo final recebe PAdES é `certificate_references` + `COMPANY_CERT_ENABLED`
| (PyHankoSigner) — nunca o plano. Consequências das duas pontas:
|
|  - o cliente do plano Profissional paga por um item que, sem certificado configurado
|    (padrão da Fase 1), nunca recebe: o mesmo produto lhe diz, na tela do documento,
|    "Nenhum certificado da operadora estava ativo na finalização";
|  - o cliente do plano Grátis, a quem o item é negado na comparação, recebe a assinatura
|    assim que a operadora configurar o certificado, porque nada consulta o plano.
|
| O banner de sandbox das duas telas ressalva "os **valores e limites** são fictícios" —
| não a lista de recursos, e o plano Grátis nem é sandbox.
|
| O aviso da arquitetura §2 vale para o discurso comercial também: nunca anunciar o que
| depende de um certificado que pode não existir.
*/

beforeEach(function () {
    $this->withoutVite();
    $this->seed(PlanSeeder::class);
});

it('não anuncia a assinatura criptográfica como item de plano enquanto nenhum certificado da operadora existe', function () {
    ['organization' => $organization, 'owner' => $owner] = createOrganizationWithOwner();

    expect(CertificateReference::query()->where('is_active', true)->exists())->toBeFalse();

    actingAsMember($owner, $organization);

    $plans = $this->get(route('plans.index'))
        ->assertOk()
        ->viewData('page')['props']['plans'];

    $advertised = [];

    foreach ($plans as $plan) {
        foreach ($plan['features'] as $feature) {
            if (str_contains((string) $feature, 'Assinatura criptográfica')) {
                $advertised[] = $plan['name'].' → '.$feature;
            }
        }
    }

    expect($advertised)->toBe(
        [],
        "Planos vendem a assinatura criptográfica sem certificado configurado:\n".implode("\n", $advertised)
    );
});

it('a flag de plano company_signature é lida por alguma regra da aplicação', function () {
    expect(Plan::query()->where('code', Plan::CODE_PROFESSIONAL)->value('features'))
        ->toHaveKey('company_signature');

    $finder = Finder::create()->files()->in([app_path(), base_path('routes')])->name('*.php');

    $readers = [];

    foreach ($finder as $file) {
        if (! str_contains($file->getContents(), 'company_signature')) {
            continue;
        }

        $relative = str_replace('\\', '/', $file->getRelativePathname());

        // O mapa de rótulos só traduz a chave para texto; não é regra de negócio.
        if ($relative === 'Http/Controllers/Billing/PlanController.php') {
            continue;
        }

        $readers[] = $relative;
    }

    expect($readers)->not->toBe(
        [],
        'Nenhum arquivo de app/ ou routes/ consulta features.company_signature: '.
        'o item aparece na comparação de planos e não governa comportamento nenhum.'
    );
});
