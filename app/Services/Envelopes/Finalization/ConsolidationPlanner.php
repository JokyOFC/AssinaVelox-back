<?php

namespace App\Services\Envelopes\Finalization;

use App\Enums\FieldType;
use App\Models\DocumentVersion;
use App\Models\Envelope;
use App\Models\SignatureAcceptance;
use App\Models\SigningField;
use App\Models\SigningFieldValue;
use App\Services\Branding\Stamp\StampComposer;
use App\Services\Documents\DocumentStorage;
use App\Services\Pdf\Dto\ComposePlan;
use App\Services\Pdf\Support\TemporaryDirectory;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;

/**
 * Etapa (a) da finalização: **plano de composição** dos campos autorizados sobre a versão
 * congelada no envio.
 *
 * ## O que "autorizado" quer dizer, e por que a regra é essa
 *
 * Um valor entra no PDF final se, e somente se, existe um `SignatureAcceptance` **gravado**
 * para o destinatário dono do campo. Não basta a linha em `signing_field_values`: a consulta
 * exige o `INNER JOIN` com `signature_acceptances` do próprio envelope. Um destinatário que
 * recusou, que expirou ou cujo aceite foi desfeito não tem aceite — e portanto não tem
 * nenhum traço no documento consolidado. Escrever no PDF um valor sem aceite seria afirmar
 * uma manifestação de vontade que não existe.
 *
 * Também é exigido que o campo pertença à **versão congelada** (`sent_document_version_id`):
 * um campo posicionado sobre outra versão descreve outra página e cairia no lugar errado.
 *
 * ## O que a composição NÃO faz
 *
 * Não altera uma cláusula, não reescreve texto, não move nada: só achata (`flatten`) os
 * valores nas coordenadas onde os campos foram apresentados. As páginas, as caixas
 * (MediaBox/CropBox) e a rotação são preservadas pelo `pdftool compose`, e as coordenadas
 * normalizadas são as mesmas que o editor e a tela do signatário usaram — por isso a
 * rotação de página é respeitada sem nenhuma conta extra aqui.
 *
 * ## Assinatura digitada
 *
 * Quando o participante escolheu "digitar", `signature_acceptances.signature_image_path` é
 * nulo por construção (não existe imagem): o que existe é `typed_name`. Nesse caso o campo
 * de assinatura é desenhado como **texto** dentro do mesmo retângulo. A fonte manuscrita da
 * tela (Caveat) não é embutida no PDF — ela é carregada pelo navegador e não existe no
 * servidor —, então o nome sai na fonte padrão do documento. `typed_font` continua gravado
 * como evidência do que a pessoa viu.
 */
class ConsolidationPlanner
{
    public function __construct(
        private readonly DocumentStorage $storage,
        private readonly Repository $config,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Monta o plano. `$sourcePath` é o PDF da versão congelada já copiado para o diretório
     * temporário; as imagens de assinatura também são copiadas para lá.
     *
     * @return array{plan: ComposePlan, fields: int, footers: int}
     */
    public function build(Envelope $envelope, DocumentVersion $sentVersion, string $sourcePath, TemporaryDirectory $workDir): array
    {
        $plan = ComposePlan::source($sourcePath);

        $authorized = $this->authorizedValues($envelope, $sentVersion);
        $acceptances = $this->acceptancesById($envelope);

        $drawn = 0;

        foreach ($authorized as $value) {
            $field = $value->field;

            if ($field === null) {
                continue;
            }

            $acceptance = $acceptances[$value->signature_acceptance_id] ?? null;

            if ($this->addField($plan, $field, $value, $acceptance, $workDir)) {
                $drawn++;
            }
        }

        $footers = $this->addFooters($plan, $envelope, $sentVersion);

        return ['plan' => $plan, 'fields' => $drawn, 'footers' => $footers];
    }

    /**
     * Valores de campo cobertos por um aceite gravado deste envelope, sobre a versão
     * congelada. Sem aceite, nada entra no PDF.
     *
     * @return Collection<int, SigningFieldValue>
     */
    public function authorizedValues(Envelope $envelope, DocumentVersion $sentVersion): Collection
    {
        /** @var Collection<int, SigningFieldValue> $values */
        $values = SigningFieldValue::withoutOrganizationScope()
            ->with(['field'])
            ->where('signing_field_values.envelope_id', $envelope->getKey())
            ->whereIn('signature_acceptance_id', SignatureAcceptance::withoutOrganizationScope()
                ->where('envelope_id', $envelope->getKey())
                ->select('id'))
            ->whereIn('signing_field_id', SigningField::withoutOrganizationScope()
                ->where('envelope_id', $envelope->getKey())
                ->where('document_version_id', $sentVersion->getKey())
                ->select('id'))
            ->orderBy('signing_field_id')
            ->get();

        return $values;
    }

    /**
     * @return array<int, SignatureAcceptance>
     */
    private function acceptancesById(Envelope $envelope): array
    {
        return SignatureAcceptance::withoutOrganizationScope()
            ->where('envelope_id', $envelope->getKey())
            ->get()
            ->keyBy(fn (SignatureAcceptance $acceptance): int => (int) $acceptance->getKey())
            ->all();
    }

    /**
     * Acrescenta um campo ao plano. Devolve false quando não há nada para desenhar
     * (checkbox desmarcada, texto vazio, imagem ausente) — o `compose` também pularia,
     * mas evitar a viagem deixa o plano honesto sobre o que foi desenhado.
     */
    private function addField(
        ComposePlan $plan,
        SigningField $field,
        SigningFieldValue $value,
        ?SignatureAcceptance $acceptance,
        TemporaryDirectory $workDir,
    ): bool {
        $x = (float) $field->x;
        $y = (float) $field->y;
        $width = (float) $field->width;
        $height = (float) $field->height;
        $page = (int) $field->page;
        $id = $field->ulid;

        $options = array_filter([
            'font_size' => $this->fontSize($field),
            'box' => $field->box_type->value,
        ], static fn ($v): bool => $v !== null);

        if ($field->type === FieldType::Signature || $field->type === FieldType::Initials) {
            return $this->addVisual($plan, $field, $value, $acceptance, $workDir, $id, $page, $x, $y, $width, $height);
        }

        if ($field->type === FieldType::Checkbox) {
            if ($value->value_bool !== true) {
                return false;
            }

            $plan->addCheckbox($id, $page, $x, $y, $width, $height, true, ['box' => $field->box_type->value]);

            return true;
        }

        // Fase 2 §2.8 (C-BRAND): carimbo visual congelado no aceite (`image_path`), desenhado
        // pelo caminho de imagem que o pdftool já conhece. Sem imagem, o campo fica vazio.
        if ($field->type === FieldType::Stamp) {
            $imagePath = $value->image_path;
            $local = is_string($imagePath) && $imagePath !== '' ? $this->copyImage($imagePath, $workDir, $id) : null;

            if ($local === null) {
                return false;
            }

            StampComposer::add($plan, $field, $local);

            return true;
        }

        // Fase 2 §2.11 (C-ID): o CPF é texto no PDF; o pdftool só conhece os tipos da Fase 1.
        if ($field->type === FieldType::Cpf) {
            return $this->addTextValue($plan, $field, (string) ($value->value_text ?? ''), $id, $page, $x, $y, $width, $height, $options, FieldType::Text);
        }

        return $this->addTextValue($plan, $field, (string) ($value->value_text ?? ''), $id, $page, $x, $y, $width, $height, $options);
    }

    private function addVisual(
        ComposePlan $plan,
        SigningField $field,
        SigningFieldValue $value,
        ?SignatureAcceptance $acceptance,
        TemporaryDirectory $workDir,
        string $id,
        int $page,
        float $x,
        float $y,
        float $width,
        float $height,
    ): bool {
        $imagePath = $value->image_path;

        if (is_string($imagePath) && $imagePath !== '') {
            $local = $this->copyImage($imagePath, $workDir, $id);

            if ($local !== null) {
                $plan->addImage($id, $page, $field->type, $x, $y, $width, $height, $local, ['box' => $field->box_type->value]);

                return true;
            }

            // A imagem consta no aceite mas sumiu do disco. Não se inventa um traço no
            // lugar dela: o campo fica vazio e o incidente é registrado.
            $this->logger->warning('Finalização: imagem da representação visual ausente no disco.', [
                'field_ulid' => $field->ulid,
                'field_type' => $field->type->value,
            ]);
        }

        // Assinatura digitada: não existe imagem, existe o nome digitado.
        $typed = trim((string) ($acceptance->typed_name ?? ''));

        if ($typed !== '') {
            $plan->addField($id, $page, FieldType::Text, $x, $y, $width, $height, $typed, [
                'align' => 'center',
                'box' => $field->box_type->value,
            ]);

            return true;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function addTextValue(
        ComposePlan $plan,
        SigningField $field,
        string $text,
        string $id,
        int $page,
        float $x,
        float $y,
        float $width,
        float $height,
        array $options,
        ?FieldType $as = null,
    ): bool {
        if (trim($text) === '') {
            return false;
        }

        $plan->addField($id, $page, $as ?? $field->type, $x, $y, $width, $height, $text, $options + ['align' => 'left']);

        return true;
    }

    /**
     * Rodapé de verificação em todas as páginas do documento consolidado
     * (docs/juridico/declaracao-de-aceite.md §6): apenas a URL e o código.
     *
     * É desenhado **aqui**, na composição, para ficar coberto pela assinatura criptográfica
     * quando ela existir. O hash final **nunca** entra: ele é calculado depois de o arquivo
     * estar pronto e não pode estar dentro dele.
     */
    private function addFooters(ComposePlan $plan, Envelope $envelope, DocumentVersion $sentVersion): int
    {
        $footer = (array) $this->config->get('assinavelox.evidence.footer', []);

        if (($footer['enabled'] ?? true) !== true) {
            return 0;
        }

        $pages = max(1, (int) ($sentVersion->page_count ?? 1));
        $text = self::footerText($envelope);

        $x = (float) ($footer['x'] ?? 0.06);
        $y = (float) ($footer['y'] ?? 0.962);
        $width = (float) ($footer['width'] ?? 0.88);
        $height = (float) ($footer['height'] ?? 0.022);
        $fontSize = (float) ($footer['font_size'] ?? 7.0);
        $align = (string) ($footer['align'] ?? 'center');

        for ($page = 1; $page <= $pages; $page++) {
            $plan->addText("footer_p{$page}", $page, $x, $y, $width, $height, $text, [
                'font_size' => $fontSize,
                'align' => $align,
            ]);
        }

        return $pages;
    }

    /**
     * "Verifique em {url} · código XXXX-XXXX-XXXX" — sem nomes, e-mails, IPs ou hashes.
     */
    public static function footerText(Envelope $envelope): string
    {
        $url = preg_replace('#^https?://#i', '', rtrim((string) config('app.url'), '/').'/verificar') ?? '';

        return sprintf(
            'Verifique em %s · código %s',
            $url,
            $envelope->formatted_verification_code ?? '—',
        );
    }

    private function fontSize(SigningField $field): ?float
    {
        $size = ($field->options ?? [])['font_size'] ?? null;

        return is_numeric($size) && (float) $size > 0 ? (float) $size : null;
    }

    /**
     * Copia a imagem do disco `documents` para o diretório temporário exclusivo da
     * operação. Devolve null quando o arquivo não existe mais.
     */
    private function copyImage(string $storagePath, TemporaryDirectory $workDir, string $id): ?string
    {
        $disk = $this->storage->disk();

        if (! $disk->exists($storagePath)) {
            return null;
        }

        $contents = $disk->get($storagePath);

        if ($contents === null || $contents === '') {
            return null;
        }

        $target = $workDir->path(sprintf('img-%s.png', preg_replace('/[^A-Za-z0-9]/', '', $id) ?: 'field'));

        if (file_put_contents($target, $contents, LOCK_EX) === false) {
            return null;
        }

        return $target;
    }
}
