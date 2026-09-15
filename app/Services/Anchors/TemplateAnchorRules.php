<?php

namespace App\Services\Anchors;

use App\Enums\FieldType;
use App\Models\Envelope;
use App\Models\FieldAnchorRule;
use App\Models\Recipient;
use App\Models\Template;
use App\Models\TemplateRole;
use App\Models\TemplateVersion;
use App\Models\User;
use App\Services\Pdf\PdfToolClient;
use App\Services\Templates\TemplateSourceType;
use App\Services\Templates\TemplateStorage;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Regras de âncora por modelo (Fase 3 §3.2 — docs/fase-3/ancoras-e-ocr.md §4).
 *
 * As regras pertencem ao MODELO e valem para a versão corrente no momento do uso; o
 * participante é localizado pela posição 1..N da lista de papéis. Salvar as regras não cria
 * versão do modelo (o conteúdo não muda) e não altera nenhum envelope já gerado.
 *
 * Ao gerar um envelope do modelo (gancho em `CreateEnvelopeFromTemplate`), com a flag ligada e
 * pelo menos uma regra, uma busca é agendada no documento gerado: marcadores + os textos das
 * regras. O resultado são SUGESTÕES que o remetente revisa no editor.
 */
class TemplateAnchorRules
{
    public function __construct(
        private readonly AnchorScanner $scanner,
        private readonly AnchorFinder $finder,
        private readonly TemplateStorage $templateStorage,
        private readonly PdfToolClient $pdftool,
    ) {}

    /**
     * @return Collection<int, FieldAnchorRule>
     */
    public function rules(Template $template): Collection
    {
        return FieldAnchorRule::withoutOrganizationScope()
            ->where('template_id', $template->getKey())
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * Papéis da versão corrente, na ordem (posição 1..N).
     *
     * @return list<array{position: int, name: string, participant_role: string}>
     */
    public function roles(Template $template): array
    {
        $version = $template->currentVersion()->with('roles')->first();

        if (! $version instanceof TemplateVersion) {
            return [];
        }

        return array_values($version->roles
            ->sortBy('position')
            ->values()
            ->map(fn (TemplateRole $role, int $index): array => [
                'position' => $index + 1,
                'name' => $role->name,
                'participant_role' => $role->participant_role->value,
            ])
            ->all());
    }

    /**
     * Substitui a lista inteira (entrada já validada pelo controller).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return Collection<int, FieldAnchorRule>
     */
    public function replace(Template $template, User $user, array $rows): Collection
    {
        $roles = collect($this->roles($template))->keyBy('position');

        DB::transaction(function () use ($template, $user, $rows, $roles): void {
            FieldAnchorRule::withoutOrganizationScope()->where('template_id', $template->getKey())->delete();

            foreach ($rows as $index => $row) {
                $position = isset($row['role_position']) ? (int) $row['role_position'] : null;

                $rule = new FieldAnchorRule;
                $rule->forceFill([
                    'organization_id' => $template->organization_id,
                    'template_id' => $template->getKey(),
                    'pattern' => AnchorQuery::normalizeLiteral((string) $row['pattern']),
                    'field_type' => (string) $row['field_type'],
                    'role_position' => $position,
                    'role_name' => $position !== null ? mb_substr((string) ($roles->get($position)['name'] ?? ''), 0, 120) ?: null : null,
                    'placement' => AnchorPlacement::tryFrom((string) ($row['placement'] ?? 'below')) ?? AnchorPlacement::Below,
                    'offset_x_pt' => (float) ($row['offset_x_pt'] ?? 0),
                    'offset_y_pt' => (float) ($row['offset_y_pt'] ?? 0),
                    'width_pt' => isset($row['width_pt']) ? (float) $row['width_pt'] : null,
                    'height_pt' => isset($row['height_pt']) ? (float) $row['height_pt'] : null,
                    'required' => (bool) ($row['required'] ?? true),
                    'occurrence' => ($row['occurrence'] ?? 'all') === 'first' ? FieldAnchorRule::OCCURRENCE_FIRST : FieldAnchorRule::OCCURRENCE_ALL,
                    'sort_order' => $index + 1,
                    'created_by_user_id' => $user->getKey(),
                    'updated_by_user_id' => $user->getKey(),
                ])->save();
            }
        });

        return $this->rules($template);
    }

    /**
     * Consulta de uma busca a partir das regras: marcadores + um literal por regra.
     *
     * @param  array<int, string|null>  $recipientsByPosition  ULID do destinatário por posição
     */
    public function query(Template $template, array $recipientsByPosition = []): AnchorQuery
    {
        $literals = [];

        foreach ($this->rules($template) as $rule) {
            $literals[] = AnchorQuery::literal(
                id: $rule->literalId(),
                text: $rule->pattern,
                fieldType: $rule->field_type->value,
                recipient: $rule->role_position !== null ? ($recipientsByPosition[$rule->role_position] ?? null) : null,
                placement: $rule->placement->value,
                offsetX: (float) $rule->offset_x_pt,
                offsetY: (float) $rule->offset_y_pt,
                width: $rule->width_pt !== null ? (float) $rule->width_pt : null,
                height: $rule->height_pt !== null ? (float) $rule->height_pt : null,
                required: $rule->required,
                occurrence: $rule->occurrence,
                rule: (int) $rule->getKey(),
            );
        }

        return new AnchorQuery(
            markers: true,
            literals: $literals,
            maxMatches: max(1, (int) config('assinavelox.field_anchors.max_matches', 300)),
        );
    }

    /**
     * Gancho de `CreateEnvelopeFromTemplate` (dentro da transação; o job sai depois do commit).
     */
    public function onTemplateUsed(Template $template, TemplateVersion $version, Envelope $envelope, ?User $user): void
    {
        if (! AnchorFeatures::fieldAnchors($template->organization)) {
            return;
        }

        if ($this->rules($template)->isEmpty()) {
            return;
        }

        // Papel da posição N → destinatário criado para ele (rótulo = nome do papel).
        $recipients = Recipient::withoutOrganizationScope()
            ->where('envelope_id', $envelope->getKey())
            ->orderBy('order_index')
            ->orderBy('id')
            ->get();

        $used = [];
        $map = [];

        foreach ($version->roles->sortBy('position')->values() as $index => $role) {
            $match = $recipients->first(fn (Recipient $recipient): bool => ! isset($used[$recipient->getKey()])
                && $recipient->role_label === $role->name);

            if ($match instanceof Recipient) {
                $used[$match->getKey()] = true;
                $map[$index + 1] = $match->ulid;
            }
        }

        $this->scanner->scheduleForTemplate($envelope, $template, $this->query($template, $map), $user);
    }

    /**
     * "Testar regras" no editor do modelo — só modelos em PDF (os demais só existem como PDF
     * depois de gerados). Conta quantas vezes cada regra e os marcadores aparecem.
     *
     * @return array{pages: int, pages_without_text: list<int>, markers: int, rules: array<string, int>, truncated: bool}
     *
     * @throws ValidationException
     */
    public function test(Template $template): array
    {
        $version = $template->currentVersion()->first();

        if (! $version instanceof TemplateVersion || $version->source_type !== TemplateSourceType::Pdf) {
            throw ValidationException::withMessages([
                'rules' => 'O teste está disponível só para modelos em PDF. Nos demais, as regras valem no documento gerado.',
            ]);
        }

        $query = $this->query($template);
        $directory = $this->pdftool->temporaryDirectory('anchors-tpl-');

        try {
            try {
                $path = $this->templateStorage->copyToTemporary($version, $directory, 'modelo.pdf');
            } catch (Throwable) {
                throw ValidationException::withMessages(['rules' => AnchorDetectionException::messageFor('file_missing')]);
            }

            try {
                // Dentro da requisição HTTP: orçamento curto (a detecção completa roda na fila).
                $detection = $this->finder->find($path, $query, null, max(1, (int) config('assinavelox.field_anchors.test_time_budget_seconds', 15)));
            } catch (AnchorDetectionException $exception) {
                throw ValidationException::withMessages(['rules' => $exception->getMessage()]);
            }
        } finally {
            $directory->delete();
        }

        $counts = [];

        foreach ($this->rules($template) as $rule) {
            $counts[$rule->ulid] = 0;
        }

        $byLiteral = $this->rules($template)->keyBy(fn (FieldAnchorRule $rule): string => $rule->literalId());
        $markers = 0;

        foreach ($detection->matches as $match) {
            if ($match->isMarker()) {
                $markers++;

                continue;
            }

            $rule = $match->literalId !== null ? $byLiteral->get($match->literalId) : null;

            if ($rule instanceof FieldAnchorRule) {
                $counts[$rule->ulid] = ($counts[$rule->ulid] ?? 0) + 1;
            }
        }

        return [
            'pages' => $detection->pageCount,
            'pages_without_text' => $detection->pagesWithoutText,
            'markers' => $markers,
            'rules' => $counts,
            'truncated' => $detection->truncated,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(FieldAnchorRule $rule): array
    {
        return [
            'id' => $rule->ulid,
            'pattern' => $rule->pattern,
            'field_type' => $rule->field_type->value,
            'field_type_label' => $rule->field_type->label(),
            'role_position' => $rule->role_position,
            'role_name' => $rule->role_name,
            'placement' => $rule->placement->value,
            'offset_x_pt' => (float) $rule->offset_x_pt,
            'offset_y_pt' => (float) $rule->offset_y_pt,
            'width_pt' => $rule->width_pt !== null ? (float) $rule->width_pt : null,
            'height_pt' => $rule->height_pt !== null ? (float) $rule->height_pt : null,
            'required' => $rule->required,
            'occurrence' => $rule->occurrence,
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function fieldTypeOptions(): array
    {
        return array_map(
            static fn (string $type): array => ['value' => $type, 'label' => FieldType::from($type)->label()],
            FieldAnchorRule::FIELD_TYPES,
        );
    }
}
