<?php

/*
|--------------------------------------------------------------------------
| Helpers dos testes da Fase 2 — modelos com variáveis tipadas (B-TPL)
|--------------------------------------------------------------------------
| Incluído com require_once pelos arquivos de teste. Não contém testes.
*/

use App\Models\Organization;
use App\Models\Template;
use App\Models\TemplateRole;
use App\Models\User;
use App\Services\Templates\TemplateManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Pdf\Support\PdfFixtures;

require_once __DIR__.'/../../../Support/OrganizationHelpers.php';

if (! function_exists('templatesEnable')) {
    /**
     * Liga a flag `templates` para a organização: configuração global E plano vigente.
     * Atenção: o plano "free" é compartilhado entre organizações de teste.
     */
    function templatesEnable(Organization $organization, bool $participantRoles = false): void
    {
        config()->set('assinavelox.features.templates', true);
        config()->set('assinavelox.features.participant_roles', $participantRoles);

        $plan = $organization->currentSubscription()->with('plan')->first()?->plan;

        if ($plan === null) {
            return;
        }

        $features = (array) ($plan->features ?? []);
        $features['templates'] = true;
        $features['participant_roles'] = $participantRoles;
        $plan->forceFill(['features' => $features])->save();
    }
}

if (! function_exists('templatesWorkspace')) {
    /**
     * Área de trabalho do teste + disco `documents` falso + temporário do pdftool isolado.
     */
    function templatesWorkspace(): string
    {
        $work = PdfFixtures::workspace();
        config()->set('pdftool.tmp_path', $work.DIRECTORY_SEPARATOR.'pdftool-tmp');
        Storage::fake('documents');

        return $work;
    }
}

if (! function_exists('templatesRequirePdftool')) {
    function templatesRequirePdftool(): void
    {
        if (! PdfFixtures::available()) {
            test()->markTestSkipped(PdfFixtures::skipMessage());
        }
    }
}

if (! function_exists('templateDocxFile')) {
    /**
     * DOCX real e mínimo com um parágrafo por linha de `$lines` (texto já com marcadores).
     *
     * @param  list<string>  $lines
     * @param  array<string, string>  $extra  entradas adicionais do pacote (nome => conteúdo)
     */
    function templateDocxFile(string $path, array $lines, array $extra = [], ?string $contentTypes = null): string
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Não foi possível criar {$path}.");
        }

        $paragraphs = implode('', array_map(
            fn (string $line): string => '<w:p><w:r><w:t xml:space="preserve">'.htmlspecialchars($line, ENT_XML1).'</w:t></w:r></w:p>',
            $lines,
        ));

        $zip->addFromString('[Content_Types].xml', $contentTypes ?? '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
        $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'.$paragraphs.'</w:body></w:document>');
        $zip->addFromString('word/settings.xml', '<?xml version="1.0" encoding="UTF-8"?><w:settings xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"/>');

        foreach ($extra as $name => $content) {
            $zip->addFromString($name, $content);
        }

        $zip->close();

        return $path;
    }
}

if (! function_exists('templateUpload')) {
    function templateUpload(string $path, string $name): UploadedFile
    {
        return new UploadedFile($path, $name, null, null, true);
    }
}

if (! function_exists('templateHtml')) {
    /**
     * Modelo HTML pronto (versão 2: criação + definição tipada).
     *
     * @param  list<array<string, mixed>>  $variables
     * @param  list<array<string, mixed>>|null  $roles
     */
    function templateHtml(Organization $organization, User $user, string $html, array $variables, ?array $roles = null, string $name = 'Contrato de locação', ?string $category = 'Locação'): Template
    {
        $manager = app(TemplateManager::class);

        $template = $manager->create($organization, $user, [
            'name' => $name,
            'category' => $category,
            'source_type' => 'html',
            'html_body' => $html,
        ], null);

        $manager->update($template, $user, [
            'html_body' => $html,
            'variables' => $variables,
            'roles' => $roles ?? [['ref' => 'r1', 'name' => 'Locatário', 'participant_role' => 'signer']],
            'fields' => [],
        ]);

        return $template->fresh();
    }
}

if (! function_exists('templatePdf')) {
    /**
     * Modelo PDF fixo (2 páginas A4) com papéis e campos.
     *
     * @param  list<array<string, mixed>>  $roles
     * @param  list<array<string, mixed>>  $fields  `role_ref` aponta para `ref` dos papéis
     */
    function templatePdf(Organization $organization, User $user, string $work, array $roles, array $fields, string $name = 'Termo de vistoria'): Template
    {
        $manager = app(TemplateManager::class);
        $pdf = PdfFixtures::twoPagePdf($work.DIRECTORY_SEPARATOR.'modelo-'.uniqid().'.pdf');

        $template = $manager->create($organization, $user, [
            'name' => $name,
            'category' => 'Locação',
            'source_type' => 'pdf',
        ], templateUpload($pdf, 'vistoria.pdf'));

        $manager->update($template, $user, [
            'roles' => $roles,
            'fields' => $fields,
            'signing_order' => 'sequential',
        ]);

        return $template->fresh();
    }
}

if (! function_exists('templateRoleIds')) {
    /**
     * ULID de cada papel da versão atual, por nome.
     *
     * @return array<string, string>
     */
    function templateRoleIds(Template $template): array
    {
        return $template->fresh()->currentVersion->roles
            ->mapWithKeys(fn (TemplateRole $role): array => [$role->name => $role->ulid])
            ->all();
    }
}

if (! function_exists('templateDocxXml')) {
    function templateDocxXml(string $path): string
    {
        $zip = new ZipArchive;
        $zip->open($path);
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();

        return $xml;
    }
}
