<?php

namespace App\Http\Controllers\Envelopes;

use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Models\Envelope;
use App\Models\Recipient;
use App\Services\Envelopes\EnvelopeAudit;
use App\Support\CurrentOrganization;
use App\Support\Locale\MultilingualFeature;
use App\Support\Locale\SignerLocale;
use App\Support\Locale\SignerLocales;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Idioma (e fuso opcional) de cada participante, definido por quem envia (Fase 3 §3.3 — F-I18N,
 * docs/fase-3/multilingue.md §3). JSON, para o seletor do passo de participantes do wizard.
 *
 * - `GET documentos/{envelope}/idiomas` (`envelopes.recipients.locales`): idiomas disponíveis e o
 *   de cada participante;
 * - `PUT documentos/{envelope}/participantes/{recipient}/idioma` (`envelopes.recipients.locale`):
 *   `locale` (lista fechada) e `timezone` (identificador IANA ou nulo = fuso da organização). Só
 *   antes do envio, como as demais escolhas do wizard.
 *
 * 404 com a flag `multilingual` desligada; envelope/participante de outra organização: 404 pelo
 * binding.
 */
class RecipientLocaleController extends Controller
{
    public function index(Envelope $envelope): JsonResponse
    {
        abort_unless(MultilingualFeature::enabled(CurrentOrganization::instance()->get()), 404);

        Gate::authorize('view', $envelope);

        $recipients = [];

        foreach ($envelope->recipients()->get() as $recipient) {
            $recipients[$recipient->ulid] = [
                'locale' => SignerLocales::stored($recipient)->value,
                'timezone' => $recipient->timezone,
            ];
        }

        return response()->json([
            'reference_locale' => SignerLocale::reference()->value,
            'locales' => SignerLocale::options(),
            'editable' => $envelope->status->isDraftLike(),
            'recipients' => (object) $recipients,
        ]);
    }

    public function update(Request $request, Envelope $envelope, Recipient $recipient): JsonResponse
    {
        abort_unless(MultilingualFeature::enabled(CurrentOrganization::instance()->get()), 404);

        Gate::authorize('update', $envelope);

        abort_unless($recipient->envelope_id === $envelope->getKey(), 404);

        $validated = $request->validate([
            'locale' => ['required', 'string', Rule::in(SignerLocale::values())],
            'timezone' => ['nullable', 'string', 'timezone:all'],
        ], [
            'locale.required' => __('signer_ui.locale.invalid'),
            'locale.in' => __('signer_ui.locale.invalid'),
        ]);

        if (! $envelope->status->isDraftLike()) {
            $message = __('signer_ui.locale.draft_only');

            return response()->json(['message' => $message, 'errors' => ['locale' => [$message]]], 422);
        }

        $before = SignerLocales::stored($recipient);
        $locale = SignerLocale::tryFromInput($validated['locale']) ?? SignerLocale::reference();
        $timezone = isset($validated['timezone']) && $validated['timezone'] !== '' ? (string) $validated['timezone'] : null;

        $recipient->forceFill(['locale' => $locale->value, 'timezone' => $timezone])->save();

        EnvelopeAudit::record($envelope, AuditEventType::RecipientLocaleUpdated, [
            'recipient_ulid' => $recipient->ulid,
            'from' => $before->value,
            'to' => $locale->value,
            'timezone' => $timezone,
        ], $recipient);

        return response()->json([
            'recipient_id' => $recipient->ulid,
            'locale' => $locale->value,
            'timezone' => $timezone,
            'message' => __('signer_ui.locale.saved'),
        ]);
    }
}
