<?php

namespace App\Http\Controllers\Envelopes;

use App\Enums\EnvelopeStatus;
use App\Http\Controllers\Controller;
use App\Models\Envelope;
use App\Models\Organization;
use App\Services\Envelopes\Reminders\ReminderSettings;
use App\Services\Envelopes\Reminders\RemindersFeature;
use App\Services\Envelopes\Sending\Exceptions\SendingException;
use App\Services\Envelopes\Sending\ScheduledSend;
use App\Services\Envelopes\Sending\SendEnvelope;
use App\Services\Plans\Exceptions\SendingBlockedException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Enviar para assinatura — ROUTES §1.2 `envelopes.send` — e, na Fase 2 §2.5, agendar o
 * envio, cancelar o agendamento e definir os lembretes automáticos do envelope.
 *
 * O controller é fino de propósito: autoriza, delega ao serviço e traduz as exceções de
 * domínio em flash PT-BR. Toda a regra (lock, congelamento da versão, código de
 * verificação, prazo, consumo do plano, convites) está em
 * `App\Services\Envelopes\Sending\SendEnvelope`; o agendamento, em `ScheduledSend`.
 *
 * Sucesso do envio → redireciona para `envelopes.show?sent=1`, que o detalhe usa para mostrar
 * a confirmação "Enviado para assinatura" (ROUTES §2.6, DESIGN §6.5).
 *
 * As três ações da Fase 2 respondem 404 com a flag `features.reminders` desligada: para
 * quem não tem o recurso, ele não existe. A flag não substitui a Policy — as duas valem.
 */
class EnvelopeSendController extends Controller
{
    public function __construct(private readonly SendEnvelope $sender) {}

    public function store(Request $request, Envelope $envelope): RedirectResponse
    {
        Gate::authorize('send', $envelope);

        try {
            $result = $this->sender->handle($envelope);
        } catch (SendingBlockedException $exception) {
            // Plano inadimplente ou cota esgotada: o texto já vem pronto para o usuário.
            return back()->with('error', $exception->getMessage());
        } catch (SendingException $exception) {
            return back()->with($exception->errorCode === 'already_sent' ? 'info' : 'error', $exception->getMessage());
        }

        $count = $result['invitations'];

        // `?sent=1` na URL, e não flash de sessão: `EnvelopeController@show` lê `sent` da
        // ENTRADA da requisição (é o contrato de ROUTES §2.6, "redirect
        // envelopes.show?sent=1"). Com o flash, a prop chegava sempre `false` e a tela
        // "Enviado para assinatura" de DESIGN §6.5 era código morto. O parâmetro na URL
        // ainda deixa a confirmação recarregável e compartilhável.
        return redirect()
            ->route('envelopes.show', ['envelope' => $result['envelope'], 'sent' => 1])
            ->with('success', $count === 1
                ? 'Documento enviado. 1 convite foi despachado.'
                : "Documento enviado. {$count} convites foram despachados.");
    }

    /**
     * POST documentos/{envelope}/agendamento — `scheduled_for` no formato
     * `Y-m-d\TH:i` (`<input type="datetime-local">`), interpretado no FUSO DA ORGANIZAÇÃO.
     * Reagendar é chamar de novo.
     */
    public function schedule(Request $request, Envelope $envelope, ScheduledSend $scheduler, RemindersFeature $feature): RedirectResponse
    {
        Gate::authorize('send', $envelope);
        abort_unless($feature->enabledFor((int) $envelope->organization_id), 404);

        $validated = $request->validate(
            ['scheduled_for' => ['required', 'string', 'date_format:'.ScheduledSend::INPUT_FORMAT]],
            [],
            ['scheduled_for' => 'data e hora do envio'],
        );

        $at = $this->parseLocal((string) $validated['scheduled_for'], $this->timezoneOf($envelope));

        try {
            $scheduler->schedule($envelope, $at);
        } catch (SendingBlockedException $exception) {
            return back()->with('error', $exception->getMessage());
        } catch (SendingException $exception) {
            if (in_array($exception->errorCode, ['schedule_too_soon', 'schedule_too_far'], true)) {
                throw ValidationException::withMessages(['scheduled_for' => $exception->getMessage()]);
            }

            return back()->with($exception->errorCode === 'already_sent' ? 'info' : 'error', $exception->getMessage());
        }

        $present = ScheduledSend::present($envelope->refresh());

        return back()->with('success', $present !== null
            ? 'Envio agendado para '.$present['at_local'].', '.$present['timezone_label'].'.'
            : 'Envio agendado.');
    }

    /**
     * DELETE documentos/{envelope}/agendamento — o envelope continua `ready`.
     */
    public function cancelSchedule(Envelope $envelope, ScheduledSend $scheduler, RemindersFeature $feature): RedirectResponse
    {
        Gate::authorize('send', $envelope);
        abort_unless($feature->enabledFor((int) $envelope->organization_id), 404);

        return $scheduler->cancel($envelope, 'user')
            ? back()->with('success', 'Agendamento cancelado. O documento continua pronto para enviar.')
            : back()->with('info', 'Este documento não tinha envio agendado.');
    }

    /**
     * PUT documentos/{envelope}/lembretes — cadência deste envelope
     * (`{enabled, first_after_days, interval_days, max_count}`). Vale no rascunho (o wizard)
     * e também depois de enviado (desligar ou ajustar lembretes de um envelope em andamento).
     */
    public function reminders(Request $request, Envelope $envelope, RemindersFeature $feature): RedirectResponse
    {
        Gate::authorize('update', $envelope);
        abort_unless($feature->enabledFor((int) $envelope->organization_id), 404);

        if ($envelope->status->isTerminal() || $envelope->status === EnvelopeStatus::Finalizing) {
            return back()->with('error', 'Os lembretes deste documento não podem mais ser alterados.');
        }

        $settings = ReminderSettings::fromArray(
            $request->validate(ReminderSettings::rules(), [], ReminderSettings::attributes()),
        );

        DB::transaction(function () use ($envelope, $settings): void {
            /** @var Envelope $locked */
            $locked = Envelope::withoutOrganizationScope()
                ->whereKey($envelope->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $current = $locked->settings ?? [];
            $current['reminders'] = $settings->toArray();

            $locked->forceFill(['settings' => $current])->save();
        });

        return back()->with('success', $settings->enabled
            ? 'Lembretes automáticos: '.mb_strtolower($settings->summary()).'.'
            : 'Lembretes automáticos desativados para este documento.');
    }

    private function timezoneOf(Envelope $envelope): string
    {
        $timezone = $envelope->organization->timezone ?? '';

        return $timezone !== '' ? $timezone : Organization::DEFAULT_TIMEZONE;
    }

    private function parseLocal(string $value, string $timezone): Carbon
    {
        try {
            $at = Carbon::createFromFormat(ScheduledSend::INPUT_FORMAT, $value, $timezone);
        } catch (Throwable) {
            $at = null;
        }

        if (! $at instanceof Carbon) {
            throw ValidationException::withMessages(['scheduled_for' => 'Informe uma data e hora válidas.']);
        }

        return $at;
    }
}
