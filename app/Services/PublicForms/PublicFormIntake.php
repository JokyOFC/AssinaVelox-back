<?php

namespace App\Services\PublicForms;

use App\Models\PublicForm;
use App\Models\PublicFormSubmission;
use App\Models\TemplateVariable;
use App\Services\Plans\Exceptions\SendingBlockedException;
use App\Services\Plans\PlanLedger;
use App\Services\PublicForms\Notifications\PublicFormConfirmationNotification;
use App\Services\Templates\VariableValues;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Recebe o envio do formulário público (docs/fase-2/formulario-publico.md §5 e §6).
 *
 * Ordem deliberada — o que é barato e não depende do conteúdo vem primeiro:
 *
 *  1. limites de tentativa (IP no formulário, IP na plataforma, volume do formulário);
 *  2. campo-isca (honeypot) e tempo mínimo de preenchimento;
 *  3. forma e tamanho de cada campo; valores pela validação tipada do modelo;
 *  4. limite de respostas do período e cota do plano (aviso antecipado — a reserva de verdade
 *     só acontece no envio do envelope, depois da confirmação);
 *  5. links pendentes para o mesmo e-mail;
 *  6. consumo do carimbo de tempo (uso único — {@see FillTimer::consume()}).
 *
 * Só então grava o envio (payload cifrado) e manda o link de confirmação. NENHUM envelope é
 * criado aqui e NENHUMA cota é reservada: isso só acontece em {@see PublicFormConfirmation}.
 *
 * Dados não confiáveis (T6): só as chaves das variáveis marcadas como públicas são lidas;
 * qualquer outra chave enviada é ignorada — inclusive a de uma variável com valor fixo.
 */
final class PublicFormIntake
{
    /** Nome do campo-isca. Parece um campo real para robôs; é invisível para pessoas. */
    public const HONEYPOT = 'website';

    public const TIMER = 'started';

    public const TIMER_USED_MESSAGE = 'Esta página já foi usada para um envio. Para mandar outra resposta, recarregue a página e preencha de novo.';

    public function __construct(
        private readonly FillTimer $timer,
        private readonly VariableValues $values,
        private readonly PlanLedger $ledger,
        private readonly SubmissionPurge $purge,
    ) {}

    /**
     * @return array{submission: PublicFormSubmission, email_hint: string}
     *
     * @throws ValidationException
     */
    public function submit(PublicForm $form, Request $request): array
    {
        $this->purge->run($form);

        $this->throttle($form, $request);

        if (trim((string) $request->input(self::HONEYPOT, '')) !== '') {
            Log::info('Formulário público: envio recusado pelo campo-isca.', ['form' => $form->ulid]);

            throw ValidationException::withMessages(['form' => 'Não foi possível registrar o envio. Recarregue a página e tente de novo.']);
        }

        $timing = $this->timer->check($form, $request->input(self::TIMER));

        if ($timing !== null) {
            throw ValidationException::withMessages(['form' => match ($timing) {
                FillTimer::TOO_FAST => 'O envio foi rápido demais. Confira as respostas e envie de novo.',
                FillTimer::STALE => 'Esta página ficou aberta por muito tempo. Recarregue-a e preencha de novo.',
                FillTimer::USED => self::TIMER_USED_MESSAGE,
                default => 'Não foi possível registrar o envio. Recarregue a página e tente de novo.',
            }]);
        }

        [$name, $email, $values] = $this->validated($form, $request);

        $this->assertWithinPeriodLimit($form);
        $this->assertQuota($form);

        $emailDigest = self::emailDigest($email);

        $pending = PublicFormSubmission::withoutOrganizationScope()
            ->where('public_form_id', $form->getKey())
            ->where('email_digest', $emailDigest)
            ->where('status', SubmissionStatus::PendingConfirmation->value)
            ->where('created_at', '>=', Carbon::now()->subHour())
            ->count();

        if ($pending >= PublicFormsConfig::pendingPerEmail()) {
            throw ValidationException::withMessages(['email' => 'Já enviamos links de confirmação para este e-mail. Confira a caixa de entrada e o spam antes de enviar de novo.']);
        }

        // O carimbo de tempo é de uso único: só o envio que passou por todas as barreiras o
        // consome (atômico — de dois envios simultâneos com o mesmo carimbo, só um grava).
        if (! $this->timer->consume($form, $request->input(self::TIMER))) {
            throw ValidationException::withMessages(['form' => self::TIMER_USED_MESSAGE]);
        }

        $token = Str::random(48);
        $ttl = PublicFormsConfig::confirmationTtlMinutes();

        $submission = PublicFormSubmission::query()->create([
            'organization_id' => $form->organization_id,
            'public_form_id' => $form->getKey(),
            'status' => SubmissionStatus::PendingConfirmation,
            'payload' => ['name' => $name, 'email' => $email, 'values' => $values],
            'email_digest' => $emailDigest,
            'confirmation_digest' => self::confirmationDigest($token),
            'confirmation_expires_at' => Carbon::now()->addMinutes($ttl),
            'privacy_notice_version' => PrivacyNotice::VERSION,
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 255, ''),
        ]);

        Notification::route('mail', $email)->notify(new PublicFormConfirmationNotification(
            route('form_fill.confirm.show', ['token' => $form->public_token, 'confirmation' => $token]),
            $form->title,
            $form->organization->name,
            $name,
            $ttl,
        ));

        return ['submission' => $submission, 'email_hint' => self::maskEmail($email)];
    }

    // -- Etapas ---------------------------------------------------------------------------

    /**
     * @throws ValidationException
     */
    private function throttle(PublicForm $form, Request $request): void
    {
        $limits = PublicFormsConfig::rateLimits();
        $ip = (string) $request->ip();

        $buckets = [
            ['public-form:'.$form->getKey().':ip:'.$ip, $limits['per_ip_form']],
            ['public-form-ip:'.$ip, $limits['per_ip']],
            ['public-form:'.$form->getKey(), $limits['per_form']],
        ];

        foreach ($buckets as [$key, [$max, $minutes]]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                $wait = max(1, (int) ceil(RateLimiter::availableIn($key) / 60));

                throw ValidationException::withMessages([
                    'form' => "Muitas tentativas de envio. Tente de novo em {$wait} ".($wait === 1 ? 'minuto' : 'minutos').'.',
                ]);
            }
        }

        foreach ($buckets as [$key, [$max, $minutes]]) {
            RateLimiter::hit($key, $minutes * 60);
        }
    }

    /**
     * @return array{0: string, 1: string, 2: array<string, string>}
     *
     * @throws ValidationException
     */
    private function validated(PublicForm $form, Request $request): array
    {
        $max = PublicFormsConfig::maxFieldLength();

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'privacy' => ['accepted'],
            'values' => ['nullable', 'array'],
            'values.*' => ['nullable', 'string', 'max:'.$max],
        ], [
            'privacy.accepted' => 'Confirme que leu o aviso de privacidade.',
            'values.*.string' => 'Resposta inválida.',
            'values.*.max' => "Cada resposta aceita no máximo {$max} caracteres.",
        ], ['name' => 'nome', 'email' => 'e-mail']);

        $validator->validate();

        $name = trim((string) $request->input('name'));
        $email = mb_strtolower(trim((string) $request->input('email')));
        $input = is_array($request->input('values')) ? $request->input('values') : [];

        $version = $form->templateVersion()->with('variables')->firstOrFail();
        $public = $form->publicVariables();

        /** @var list<TemplateVariable> $variables */
        $variables = $version->variables->filter(fn (TemplateVariable $variable): bool => in_array($variable->key, $public, true))->values()->all();

        $values = [];

        foreach ($variables as $variable) {
            $raw = $input[$variable->key] ?? null;

            if (is_string($raw)) {
                $values[$variable->key] = $raw;
            }
        }

        // Mesma validação tipada (e mesmas mensagens) do "Usar modelo"; erros em values.{chave}.
        $this->values->validate($variables, $values);

        if (in_array($email, array_column($form->fixedParticipants(), 'email'), true)) {
            throw ValidationException::withMessages(['email' => 'Use outro e-mail: este já participa do documento por outro papel.']);
        }

        return [$name, $email, $values];
    }

    /**
     * @throws ValidationException
     */
    private function assertWithinPeriodLimit(PublicForm $form): void
    {
        if (self::confirmedInPeriod($form) >= $form->submissionsLimit()) {
            throw ValidationException::withMessages(['form' => PublicFormRefusal::limit()->getMessage()]);
        }
    }

    /**
     * Aviso antecipado de cota: sem reservar nada. A reserva (idempotente, sob lock) é a do
     * envio do envelope, depois da confirmação.
     *
     * @throws ValidationException
     */
    private function assertQuota(PublicForm $form): void
    {
        $message = self::quotaMessage($this->ledger, $form);

        if ($message !== null) {
            throw ValidationException::withMessages(['form' => $message]);
        }
    }

    // -- Utilidades compartilhadas com a confirmação --------------------------------------

    public static function confirmedInPeriod(PublicForm $form): int
    {
        return PublicFormSubmission::withoutOrganizationScope()
            ->where('public_form_id', $form->getKey())
            ->whereNotNull('confirmed_at')
            ->where('confirmed_at', '>=', $form->submissionsPeriod()->windowStart())
            ->count();
    }

    /**
     * Mensagem para o PÚBLICO quando o plano da organização não permite gerar e enviar agora.
     */
    public static function quotaMessage(PlanLedger $ledger, PublicForm $form): ?string
    {
        $subscription = $ledger->subscriptionFor((int) $form->organization_id);

        try {
            if ($subscription === null) {
                throw SendingBlockedException::noSubscription();
            }

            $ledger->assertCanSend($subscription);
        } catch (SendingBlockedException $exception) {
            return $exception->errorCode === 'quota_exhausted'
                ? 'Este formulário não está aceitando respostas no momento: a organização atingiu o limite de documentos do plano. Tente mais tarde ou avise quem enviou o link.'
                : 'Este formulário não está aceitando respostas no momento por uma pendência no plano da organização. Tente mais tarde ou avise quem enviou o link.';
        }

        return null;
    }

    public static function emailDigest(string $email): string
    {
        return hash_hmac('sha256', 'public-form-email|'.mb_strtolower(trim($email)), (string) config('app.key'));
    }

    public static function confirmationDigest(string $token): string
    {
        return hash_hmac('sha256', 'public-form-confirmation|'.$token, (string) config('app.key'));
    }

    /** "an***@example.com" — só para a tela "confira seu e-mail". */
    public static function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 2).'***@'.$domain;
    }
}
