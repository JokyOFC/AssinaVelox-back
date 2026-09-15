<?php

namespace App\Http\Controllers\Anchors;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Templates\Concerns\RequiresTemplatesFeature;
use App\Models\FieldAnchorRule;
use App\Models\Template;
use App\Services\Anchors\AnchorFeatures;
use App\Services\Anchors\AnchorPlacement;
use App\Services\Anchors\AnchorQuery;
use App\Services\Anchors\TemplateAnchorRules;
use App\Services\Templates\TemplateSourceType;
use App\Support\CurrentOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Regras de âncora do modelo (Fase 3 §3.2 — docs/fase-3/ancoras-e-ocr.md §4). JSON para a aba
 * "Âncoras" do editor de modelo. 404 com `templates` OU `field_anchors` desligada; `update` do
 * modelo para ver, salvar e testar.
 */
class TemplateAnchorRuleController extends Controller implements HasMiddleware
{
    use RequiresTemplatesFeature;

    public function __construct(private readonly TemplateAnchorRules $rules) {}

    public static function middleware(): array
    {
        return [self::templatesFeatureMiddleware()];
    }

    public function index(Template $template): JsonResponse
    {
        $this->guard($template);

        return response()->json($this->payload($template));
    }

    public function update(Request $request, Template $template): JsonResponse
    {
        $this->guard($template);

        $max = max(0, (int) config('assinavelox.field_anchors.max_rules_per_template', 30));
        $roleCount = count($this->rules->roles($template));

        $data = $request->validate([
            'rules' => ['present', 'array', 'max:'.$max],
            'rules.*.pattern' => ['required', 'string', 'max:240'],
            'rules.*.field_type' => ['required', 'string', Rule::in(FieldAnchorRule::FIELD_TYPES)],
            'rules.*.role_position' => ['nullable', 'integer', 'min:1', 'max:'.max(1, $roleCount)],
            'rules.*.placement' => ['required', Rule::enum(AnchorPlacement::class)],
            'rules.*.offset_x_pt' => ['nullable', 'numeric', 'between:-300,300'],
            'rules.*.offset_y_pt' => ['nullable', 'numeric', 'between:-300,300'],
            'rules.*.width_pt' => ['nullable', 'numeric', 'between:8,600'],
            'rules.*.height_pt' => ['nullable', 'numeric', 'between:8,600'],
            'rules.*.required' => ['sometimes', 'boolean'],
            'rules.*.occurrence' => ['nullable', Rule::in([FieldAnchorRule::OCCURRENCE_ALL, FieldAnchorRule::OCCURRENCE_FIRST])],
        ], [
            'rules.max' => 'O modelo aceita no máximo '.$max.' regras de âncora.',
            'rules.*.role_position.max' => 'Este participante não existe na lista do modelo.',
        ], [
            'rules.*.pattern' => 'texto da âncora',
            'rules.*.field_type' => 'tipo de campo',
            'rules.*.role_position' => 'participante',
            'rules.*.placement' => 'posição',
            'rules.*.offset_x_pt' => 'deslocamento horizontal',
            'rules.*.offset_y_pt' => 'deslocamento vertical',
            'rules.*.width_pt' => 'largura',
            'rules.*.height_pt' => 'altura',
        ]);

        $errors = [];

        foreach (array_values($data['rules']) as $index => $row) {
            if (! AnchorQuery::acceptableLiteral((string) $row['pattern'])) {
                $errors["rules.{$index}.pattern"] = 'Informe de 2 a 120 caracteres, com pelo menos uma letra ou um número.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $this->rules->replace($template, $request->user(), array_values($data['rules']));

        return response()->json($this->payload($template));
    }

    public function test(Template $template): JsonResponse
    {
        $this->guard($template);

        return response()->json($this->rules->test($template));
    }

    private function guard(Template $template): void
    {
        AnchorFeatures::ensure(CurrentOrganization::instance()->get());
        Gate::authorize('update', $template);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Template $template): array
    {
        $version = $template->currentVersion()->first();

        return [
            'rules' => $this->rules->rules($template)->map(fn (FieldAnchorRule $rule): array => TemplateAnchorRules::present($rule))->values()->all(),
            'roles' => $this->rules->roles($template),
            'field_types' => TemplateAnchorRules::fieldTypeOptions(),
            'placements' => AnchorPlacement::options(),
            'limits' => [
                'max_rules' => max(0, (int) config('assinavelox.field_anchors.max_rules_per_template', 30)),
                'pattern_max' => AnchorQuery::MAX_LITERAL_LENGTH,
            ],
            'can_test' => $version?->source_type === TemplateSourceType::Pdf,
        ];
    }
}
