<?php

namespace App\Http\Controllers\Anchors;

use App\Http\Controllers\Controller;
use App\Models\Envelope;
use App\Models\FieldAnchorRule;
use App\Models\FieldSuggestion;
use App\Models\Recipient;
use App\Services\Anchors\AnchorFeatures;
use App\Services\Anchors\AnchorPlacement;
use App\Services\Anchors\AnchorPresenter;
use App\Services\Anchors\AnchorQuery;
use App\Services\Anchors\AnchorScanner;
use App\Services\Anchors\SuggestionReview;
use App\Support\CurrentOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * "Detectar campos" no editor do envelope (Fase 3 §3.2 — docs/fase-3/ancoras-e-ocr.md §8).
 * Respostas JSON (fora do roteador do Inertia, para não cancelar a gravação automática do
 * wizard). Flag `field_anchors` desligada: 404 antes de qualquer outra coisa. Autorização:
 * `update` do envelope (quem pode preparar).
 */
class EnvelopeAnchorController extends Controller
{
    public function __construct(
        private readonly AnchorScanner $scanner,
        private readonly SuggestionReview $review,
    ) {}

    public function index(Envelope $envelope): JsonResponse
    {
        $this->guard($envelope);

        return response()->json(AnchorPresenter::envelope($envelope));
    }

    public function detect(Request $request, Envelope $envelope): JsonResponse
    {
        $this->guard($envelope);

        if (! $envelope->status->isDraftLike()) {
            return response()->json(['message' => 'A detecção só vale para documentos em preparo.'], 409);
        }

        $max = max(0, (int) config('assinavelox.field_anchors.max_literals', 10));

        $data = $request->validate([
            'markers' => ['sometimes', 'boolean'],
            'literals' => ['sometimes', 'array', 'max:'.$max],
            'literals.*.text' => ['required', 'string', 'max:240'],
            'literals.*.field_type' => ['required', 'string', Rule::in(FieldAnchorRule::FIELD_TYPES)],
            'literals.*.recipient_id' => ['nullable', 'string', 'size:26'],
            'literals.*.placement' => ['nullable', Rule::enum(AnchorPlacement::class)],
        ], [
            'literals.max' => 'Informe no máximo '.$max.' textos por busca.',
        ], [
            'literals.*.text' => 'texto',
            'literals.*.field_type' => 'tipo de campo',
            'literals.*.placement' => 'posição',
        ]);

        $recipients = Recipient::query()->where('envelope_id', $envelope->getKey())->pluck('ulid')->all();
        $literals = [];
        $errors = [];

        foreach (array_values($data['literals'] ?? []) as $index => $row) {
            if (! AnchorQuery::acceptableLiteral((string) $row['text'])) {
                $errors["literals.{$index}.text"] = 'Informe de 2 a 120 caracteres, com pelo menos uma letra ou um número.';

                continue;
            }

            $recipient = $row['recipient_id'] ?? null;

            if ($recipient !== null && ! in_array($recipient, $recipients, true)) {
                $errors["literals.{$index}.recipient_id"] = 'Este participante não pertence ao documento.';

                continue;
            }

            $literals[] = AnchorQuery::literal(
                id: 'm'.($index + 1),
                text: (string) $row['text'],
                fieldType: (string) $row['field_type'],
                recipient: $recipient,
                placement: (string) ($row['placement'] ?? AnchorPlacement::Below->value),
            );
        }

        $markers = (bool) ($data['markers'] ?? true);

        if (! $markers && $literals === [] && $errors === []) {
            $errors['markers'] = 'Ligue os marcadores ou informe um texto para procurar.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $this->scanner->requestManual($envelope, $request->user(), new AnchorQuery(
            markers: $markers,
            literals: $literals,
            maxMatches: max(1, (int) config('assinavelox.field_anchors.max_matches', 300)),
        ));

        return response()->json(AnchorPresenter::envelope($envelope->fresh() ?? $envelope), 202);
    }

    public function accept(Request $request, Envelope $envelope, string $suggestion): JsonResponse
    {
        $this->guard($envelope);

        $data = $request->validate(['recipient_id' => ['nullable', 'string', 'size:26']]);

        $result = $this->review->accept($envelope, $this->find($envelope, $suggestion), $request->user(), $data['recipient_id'] ?? null);

        return response()->json([
            'field' => $result['field'],
            'state' => AnchorPresenter::envelope($envelope->fresh() ?? $envelope),
        ]);
    }

    public function acceptAll(Request $request, Envelope $envelope): JsonResponse
    {
        $this->guard($envelope);

        $result = $this->review->acceptAllFromText($envelope, $request->user());

        return response()->json([
            'fields' => $result['fields'],
            'skipped' => $result['skipped'],
            'state' => AnchorPresenter::envelope($envelope->fresh() ?? $envelope),
        ]);
    }

    public function discard(Request $request, Envelope $envelope, string $suggestion): JsonResponse
    {
        $this->guard($envelope);

        $this->review->discard($envelope, $this->find($envelope, $suggestion), $request->user());

        return response()->json(['state' => AnchorPresenter::envelope($envelope->fresh() ?? $envelope)]);
    }

    private function guard(Envelope $envelope): void
    {
        AnchorFeatures::ensure(CurrentOrganization::instance()->get());
        Gate::authorize('update', $envelope);
    }

    private function find(Envelope $envelope, string $ulid): FieldSuggestion
    {
        return FieldSuggestion::query()
            ->where('envelope_id', $envelope->getKey())
            ->where('ulid', $ulid)
            ->firstOrFail();
    }
}
