<?php

namespace App\Http\Controllers\Envelopes;

use App\Enums\AuditEventType;
use App\Enums\EnvelopeStatus;
use App\Enums\RecipientStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\AuditEventResource;
use App\Http\Resources\EnvelopeDetailResource;
use App\Http\Resources\EnvelopeResource;
use App\Http\Resources\FolderResource;
use App\Http\Resources\RecipientResource;
use App\Http\Resources\UserRefResource;
use App\Models\AuditEvent;
use App\Models\Envelope;
use App\Models\Folder;
use App\Models\Membership;
use App\Models\Recipient;
use App\Models\SigningField;
use App\Models\User;
use App\Services\Organizations\EnvelopeVisibility;
use App\Support\CurrentOrganization;
use App\Support\OrganizationSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Documentos (envelopes). `index` e `show` são reais (ROUTES §2.5 / §2.7); as demais ações
 * são esqueletos com o contrato de props/redirect correto — // TODO(Wave B).
 */
class EnvelopeController extends Controller
{
    private const TABS = ['all', 'awaiting', 'in_progress', 'completed', 'drafts', 'refused_expired'];

    private const SORTS = ['updated_desc', 'updated_asc', 'created_desc', 'title_asc', 'expires_asc'];

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Envelope::class);

        $current = CurrentOrganization::instance();
        $membership = $current->membership();

        $validated = $request->validate([
            'status' => ['nullable', Rule::in(self::TABS)],
            'folder' => ['nullable', 'string', 'size:26'],
            'q' => ['nullable', 'string', 'max:120'],
            'period_from' => ['nullable', 'date'],
            'period_to' => ['nullable', 'date', 'after_or_equal:period_from'],
            'recipient' => ['nullable', 'string', 'max:120'],
            'creator' => ['nullable', 'integer'],
            'sort' => ['nullable', Rule::in(self::SORTS)],
            'per_page' => ['nullable', 'integer', Rule::in([10, 25, 50])],
        ]);

        $folder = ! empty($validated['folder']) ? Folder::query()->where('ulid', $validated['folder'])->first() : null;

        $filters = [
            'status' => $validated['status'] ?? 'all',
            'folder' => $folder?->ulid,
            'q' => trim((string) ($validated['q'] ?? '')),
            'period_from' => $validated['period_from'] ?? null,
            'period_to' => $validated['period_to'] ?? null,
            'recipient' => trim((string) ($validated['recipient'] ?? '')),
            'creator' => isset($validated['creator']) ? (string) $validated['creator'] : null,
            'sort' => $validated['sort'] ?? 'updated_desc',
        ];

        $base = fn (): Builder => $this->applyFilters(EnvelopeVisibility::envelopes($membership), $filters, $folder, withStatus: false);

        $tabs = [];
        foreach (self::TABS as $tab) {
            $tabs[$tab] = $this->applyTab($base(), $tab)->count();
        }

        $envelopes = $this->applyTab($base(), $filters['status'])
            ->with(['folder', 'creator', 'recipients', 'document'])
            ->tap(fn (Builder $q) => $this->applySort($q, $filters['sort']))
            ->paginate((int) ($validated['per_page'] ?? 10))
            ->withQueryString();

        $folderRows = Folder::query()
            ->whereNull('parent_id')
            ->orderBy('name')
            ->get()
            ->map(fn (Folder $f): array => [
                'id' => $f->ulid,
                'name' => $f->name,
                'count' => EnvelopeVisibility::envelopes($membership)->where('folder_id', $f->getKey())->count(),
            ]);

        $all = EnvelopeVisibility::envelopes($membership);

        return Inertia::render('envelopes/index', [
            'filters' => $filters,
            'summary' => [
                'total' => (clone $all)->count(),
                'awaiting' => (clone $all)->where('status', EnvelopeStatus::InProgress->value)->count(),
                'storage_used_bytes' => EnvelopeVisibility::storageUsedBytes($membership),
            ],
            'tabs' => $tabs,
            'folders' => [['id' => null, 'name' => 'Todos', 'count' => $tabs['all']], ...$folderRows->all()],
            'creators' => $this->creators($membership),
            'envelopes' => EnvelopeResource::collection($envelopes),
            'can' => [
                'create_folder' => $request->user()->can('create', Folder::class),
                'bulk_cancel' => $membership->role->canManageMembers(),
            ],
        ]);
    }

    public function show(Request $request, Envelope $envelope): Response
    {
        Gate::authorize('view', $envelope);

        $validated = $request->validate([
            'tab' => ['nullable', Rule::in(['signers', 'audit', 'details'])],
            'sent' => ['nullable'],
        ]);

        $envelope->load([
            'folder', 'creator', 'organization',
            'recipients.acceptance',
            'document.currentVersion', 'document.originalVersion',
            'finalVersion',
        ]);

        $events = AuditEvent::query()
            ->with(['recipient', 'actorUser'])
            ->where('envelope_id', $envelope->getKey())
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        $viewedAt = $events
            ->where('event_type', AuditEventType::InvitationOpened)
            ->groupBy('recipient_id')
            ->map(fn ($group) => $group->first()->occurred_at);

        $resentAt = $events
            ->where('event_type', AuditEventType::InvitationResent)
            ->groupBy('recipient_id')
            ->map(fn ($group) => $group->last()->occurred_at);

        $recipients = $envelope->recipients->values()->map(function (Recipient $recipient, int $index) use ($envelope, $viewedAt, $resentAt, $request): array {
            $recipient->setRelation('envelope', $envelope);

            return RecipientResource::make($recipient)
                ->withColorIndex($index)
                ->withTimeline($viewedAt->get($recipient->getKey()), $resentAt->get($recipient->getKey()))
                ->resolve($request);
        });

        $fields = SigningField::query()
            ->with(['recipient', 'value'])
            ->where('envelope_id', $envelope->getKey())
            ->orderBy('page')
            ->orderBy('sort_order')
            ->get()
            ->map(fn (SigningField $field): array => [
                'id' => $field->ulid,
                'recipient_id' => $field->recipient->ulid ?? '',
                'type' => $field->type->value,
                'page' => (int) $field->page,
                'x' => (float) $field->x,
                'y' => (float) $field->y,
                'w' => (float) $field->width,
                'h' => (float) $field->height,
                'required' => (bool) $field->required,
                'label' => $field->label,
                'placeholder' => $field->options['placeholder'] ?? null,
                'value' => $field->relationLoaded('value') ? ($field->value->value_text ?? null) : null,
                'signed' => $field->recipient?->status === RecipientStatus::Signed,
            ]);

        return Inertia::render('envelopes/show', [
            'envelope' => EnvelopeDetailResource::make($envelope)->resolve($request),
            'recipients' => $recipients->all(),
            'fields' => $fields->all(),
            'events' => AuditEventResource::collection($events)->resolve($request),
            'folders' => FolderResource::collection(Folder::query()->whereNull('parent_id')->orderBy('name')->get())->resolve($request),
            'sent' => filter_var($validated['sent'] ?? false, FILTER_VALIDATE_BOOL),
            'tab' => $validated['tab'] ?? 'signers',
        ]);
    }

    // -- Esqueletos (Wave B) -----------------------------------------------------------

    /**
     * Nova solicitação: cria um Envelope(draft) vazio e redireciona para o wizard.
     * // TODO(Wave B): título padrão, eventos de auditoria, autosave.
     */
    public function create(Request $request): RedirectResponse
    {
        Gate::authorize('create', Envelope::class);

        $organization = CurrentOrganization::instance()->get();
        $settings = OrganizationSettings::of($organization);

        $envelope = Envelope::query()->create([
            'created_by_user_id' => $request->user()->getKey(),
            'title' => 'Novo documento',
            'status' => EnvelopeStatus::Draft,
            'signing_order' => $settings->defaultSigningOrder(),
            'terms_version' => (string) config('assinavelox.terms_version'),
            'settings' => [
                'otp_required' => true,
                'expiration_days' => $settings->defaultExpirationDays(),
                'initials_on_all_pages' => $settings->initialsOnAllPages(),
                'send_copy_to_all' => false,
            ],
        ]);

        return redirect()->route('envelopes.edit', ['envelope' => $envelope->ulid, 'step' => 1]);
    }

    /**
     * Wizard (ROUTES §2.6) — props no formato do contrato; documento/campos reais chegam na Wave B.
     */
    public function edit(Request $request, Envelope $envelope): Response|RedirectResponse
    {
        Gate::authorize('update', $envelope);

        if (! $envelope->status->isDraftLike()) {
            return redirect()->route('envelopes.show', $envelope);
        }

        $step = (int) $request->integer('step', 1);
        $step = max(1, min(4, $step));

        $organization = CurrentOrganization::instance()->get();
        $settings = OrganizationSettings::of($organization);
        $subscription = $organization->currentSubscription()->with('plan')->first();
        $envelope->load(['recipients', 'document']);

        // TODO(Wave B): documento processado, campos, completude real e rebaixamento de passo.
        return Inertia::render('envelopes/wizard', [
            'envelope' => [
                'id' => $envelope->ulid,
                'display_code' => $envelope->display_code,
                'status' => $envelope->status->value,
                'title' => $envelope->title,
                'folder_id' => $envelope->folder?->ulid,
                'expires_in_days' => (int) $envelope->setting('expiration_days', $settings->defaultExpirationDays()),
                'message' => (string) ($envelope->message ?? ''),
                'signing_order' => $envelope->signing_order->value,
                'send_copy_to_all' => (bool) $envelope->setting('send_copy_to_all', false),
                'initials_on_all_pages' => (bool) $envelope->setting('initials_on_all_pages', false),
                'updated_at' => $envelope->updated_at?->toIso8601String(),
            ],
            'step' => $step,
            'document' => null,
            'recipients' => $envelope->recipients->values()->map(fn (Recipient $r, int $i): array => [
                'id' => $r->ulid,
                'client_id' => $r->ulid,
                'name' => $r->name,
                'email' => $r->email,
                'role' => '',
                'order' => (int) $r->order_index,
                'color_index' => $i % 4,
                'channel' => 'email',
                'auth_methods' => ['email_otp'],
            ])->all(),
            'fields' => [],
            'folders' => FolderResource::collection(Folder::query()->whereNull('parent_id')->orderBy('name')->get())->resolve($request),
            'defaults' => [
                'expires_in_days' => $settings->defaultExpirationDays(),
                'signing_order' => $settings->defaultSigningOrder()->value,
                'initials_on_all_pages' => $settings->initialsOnAllPages(),
            ],
            'role_suggestions' => ['Parte', 'Locatária', 'Locatário', 'Fiador', 'Testemunha', 'Representante'],
            'plan' => [
                'envelopes_used' => (int) ($subscription->envelopes_used ?? 0),
                'envelopes_limit' => $subscription?->plan?->envelope_quota,
                'can_send' => (bool) ($subscription?->allowsSending() && $subscription->hasEnvelopeQuotaAvailable()),
                'reason' => $subscription === null ? 'Nenhuma assinatura ativa.' : null,
            ],
            'limits' => [
                'max_upload_bytes' => (int) config('assinavelox.upload.max_mb', 25) * 1024 * 1024,
                'accepted_mimes' => (array) config('assinavelox.upload.accepted_mimes', []),
            ],
            'completeness' => [
                'document' => $envelope->document?->isReady() ?? false,
                'recipients' => $envelope->recipients->isNotEmpty(),
                'fields' => false,
            ],
        ]);
    }

    /**
     * PATCH metadados do wizard. // TODO(Wave B): recomputar prontidão, auditoria envelope.updated.
     */
    public function update(Request $request, Envelope $envelope): RedirectResponse
    {
        Gate::authorize('update', $envelope);

        $validated = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'min:3', 'max:160'],
            'folder_id' => ['sometimes', 'nullable', 'string', 'size:26', Rule::exists('folders', 'ulid')->where('organization_id', $envelope->organization_id)],
            'expires_in_days' => ['sometimes', 'integer', 'min:'.(int) config('assinavelox.expiration_days.min', 1), 'max:'.(int) config('assinavelox.expiration_days.max', 90)],
            'message' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'signing_order' => ['sometimes', Rule::in(['sequential', 'parallel'])],
            'send_copy_to_all' => ['sometimes', 'boolean'],
        ], [], [
            'title' => 'título',
            'folder_id' => 'pasta',
            'expires_in_days' => 'prazo para assinatura',
            'message' => 'mensagem',
            'signing_order' => 'ordem de assinatura',
            'send_copy_to_all' => 'enviar cópia a todos',
        ]);

        if (! $envelope->status->isDraftLike()) {
            abort(409, 'Ação indisponível no status atual.');
        }

        $settings = $envelope->settings ?? [];

        if (array_key_exists('expires_in_days', $validated)) {
            $settings['expiration_days'] = (int) $validated['expires_in_days'];
        }

        if (array_key_exists('send_copy_to_all', $validated)) {
            $settings['send_copy_to_all'] = (bool) $validated['send_copy_to_all'];
        }

        $attributes = ['settings' => $settings];

        foreach (['title', 'message', 'signing_order'] as $key) {
            if (array_key_exists($key, $validated)) {
                $attributes[$key] = $validated[$key];
            }
        }

        if (array_key_exists('folder_id', $validated)) {
            $attributes['folder_id'] = $validated['folder_id']
                ? Folder::query()->where('ulid', $validated['folder_id'])->value('id')
                : null;
        }

        $envelope->forceFill($attributes)->save();

        return back();
    }

    /** // TODO(Wave B): notificar pendentes, revogar links, auditoria envelope.canceled. */
    public function cancel(Request $request, Envelope $envelope): RedirectResponse
    {
        Gate::authorize('cancel', $envelope);

        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:500']], [], ['reason' => 'motivo']);

        if (! $envelope->status->isCancelable()) {
            return back()->with('error', 'Ação indisponível no status atual.');
        }

        $envelope->transitionTo(EnvelopeStatus::Canceled);
        $settings = $envelope->settings ?? [];
        $settings['cancel_reason'] = $validated['reason'] ?? null;
        $envelope->settings = $settings;
        $envelope->save();

        return back()->with('success', 'Documento cancelado.');
    }

    /** // TODO(Wave B): remover arquivos do disco `documents`. */
    public function destroy(Request $request, Envelope $envelope): RedirectResponse
    {
        Gate::authorize('delete', $envelope);

        if (! $envelope->status->isDraftLike()) {
            return back()->with('error', 'Só rascunhos podem ser excluídos.');
        }

        $envelope->delete();

        return redirect()->route('envelopes.index')->with('success', 'Rascunho excluído.');
    }

    /** // TODO(Wave B): copiar documento original, recipients e campos. */
    public function duplicate(Request $request, Envelope $envelope): RedirectResponse
    {
        Gate::authorize('duplicate', $envelope);

        $copy = Envelope::query()->create([
            'created_by_user_id' => $request->user()->getKey(),
            'folder_id' => $envelope->folder_id,
            'title' => $envelope->title.' (cópia)',
            'message' => $envelope->message,
            'status' => EnvelopeStatus::Draft,
            'signing_order' => $envelope->signing_order,
            'terms_version' => (string) config('assinavelox.terms_version'),
            'settings' => $envelope->settings,
        ]);

        return redirect()->route('envelopes.edit', ['envelope' => $copy->ulid, 'step' => 1])
            ->with('success', 'Documento duplicado como rascunho.');
    }

    public function move(Request $request, Envelope $envelope): RedirectResponse
    {
        Gate::authorize('move', $envelope);

        $validated = $request->validate([
            'folder_id' => ['present', 'nullable', 'string', 'size:26', Rule::exists('folders', 'ulid')->where('organization_id', $envelope->organization_id)],
        ], [], ['folder_id' => 'pasta']);

        $folder = $validated['folder_id'] ? Folder::query()->where('ulid', $validated['folder_id'])->first() : null;

        $envelope->forceFill(['folder_id' => $folder?->getKey()])->save();

        // TODO(Wave B): auditoria envelope.moved.
        return back()->with('success', $folder ? 'Documento movido para '.$folder->name.'.' : 'Documento movido para "Todos".');
    }

    // -- Helpers -----------------------------------------------------------------------

    /**
     * @param  Builder<Envelope>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<Envelope>
     */
    protected function applyFilters(Builder $query, array $filters, ?Folder $folder, bool $withStatus): Builder
    {
        if ($folder !== null) {
            $query->where('folder_id', $folder->getKey());
        }

        if ($filters['q'] !== '') {
            $like = '%'.$filters['q'].'%';
            $numeric = ltrim(preg_replace('/^av-?/i', '', $filters['q']) ?? '', '0');

            $query->where(function (Builder $q) use ($like, $numeric): void {
                $q->where('title', 'like', $like);

                if ($numeric !== '' && ctype_digit($numeric)) {
                    $q->orWhere('number', (int) $numeric);
                }
            });
        }

        if ($filters['recipient'] !== '') {
            $like = '%'.$filters['recipient'].'%';
            $query->whereHas('recipients', fn (Builder $r) => $r->where('name', 'like', $like)->orWhere('email', 'like', $like));
        }

        if ($filters['creator'] !== null) {
            $query->where('created_by_user_id', (int) $filters['creator']);
        }

        if ($filters['period_from']) {
            $query->where('created_at', '>=', Carbon::parse($filters['period_from'])->startOfDay());
        }

        if ($filters['period_to']) {
            $query->where('created_at', '<=', Carbon::parse($filters['period_to'])->endOfDay());
        }

        if ($withStatus) {
            $this->applyTab($query, $filters['status']);
        }

        return $query;
    }

    /**
     * Abas → status (ROUTES §2.5 / Q1).
     *
     * @param  Builder<Envelope>  $query
     * @return Builder<Envelope>
     */
    protected function applyTab(Builder $query, string $tab): Builder
    {
        $signed = fn (Builder $q) => $q->where('status', RecipientStatus::Signed->value);

        return match ($tab) {
            'awaiting' => $query->where('status', EnvelopeStatus::InProgress->value)->whereDoesntHave('recipients', $signed),
            'in_progress' => $query->where(fn (Builder $q) => $q
                ->where(fn (Builder $inner) => $inner->where('status', EnvelopeStatus::InProgress->value)->whereHas('recipients', $signed))
                ->orWhere('status', EnvelopeStatus::Finalizing->value)),
            'completed' => $query->where('status', EnvelopeStatus::Completed->value),
            'drafts' => $query->whereIn('status', [EnvelopeStatus::Draft->value, EnvelopeStatus::Preparing->value, EnvelopeStatus::Ready->value]),
            'refused_expired' => $query->whereIn('status', [EnvelopeStatus::Refused->value, EnvelopeStatus::Expired->value, EnvelopeStatus::Canceled->value]),
            default => $query,
        };
    }

    /**
     * @param  Builder<Envelope>  $query
     */
    protected function applySort(Builder $query, string $sort): void
    {
        match ($sort) {
            'updated_asc' => $query->orderBy('updated_at'),
            'created_desc' => $query->orderByDesc('created_at'),
            'title_asc' => $query->orderBy('title'),
            'expires_asc' => $query->orderByRaw('expires_at is null')->orderBy('expires_at'),
            default => $query->orderByDesc('updated_at'),
        };

        $query->orderByDesc('id');
    }

    /**
     * @return array<int, array{id: string, name: string, initials: string, email?: string}>
     */
    protected function creators(Membership $membership): array
    {
        if (! EnvelopeVisibility::canViewAll($membership)) {
            return [UserRefResource::ref($membership->user)];
        }

        $ids = Envelope::query()->distinct()->pluck('created_by_user_id');

        return User::query()->whereIn('id', $ids)->orderBy('name')->get()
            ->map(fn (User $u) => UserRefResource::ref($u))
            ->values()
            ->all();
    }
}
