<?php

namespace App\Http\Controllers\Envelopes;

use App\Enums\AuditEventType;
use App\Enums\EnvelopeStatus;
use App\Enums\Permission;
use App\Enums\RecipientRole;
use App\Enums\RecipientStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Templates\TemplateUseController;
use App\Http\Requests\Envelopes\UpdateEnvelopeRequest;
use App\Http\Resources\AuditEventResource;
use App\Http\Resources\DocumentResource;
use App\Http\Resources\EnvelopeDetailResource;
use App\Http\Resources\EnvelopeResource;
use App\Http\Resources\EnvelopeWizardResource;
use App\Http\Resources\FolderResource;
use App\Http\Resources\RecipientResource;
use App\Http\Resources\RecipientWizardResource;
use App\Http\Resources\SigningFieldResource;
use App\Http\Resources\UserRefResource;
use App\Models\AuditEvent;
use App\Models\Envelope;
use App\Models\Folder;
use App\Models\Membership;
use App\Models\Recipient;
use App\Models\SigningField;
use App\Models\User;
use App\Services\Documents\DocumentIntake;
use App\Services\Documents\EnvelopeDocuments;
use App\Services\Documents\Exceptions\UploadRejectedException;
use App\Services\Envelopes\DomainFeatures;
use App\Services\Envelopes\DuplicateEnvelope;
use App\Services\Envelopes\EnvelopeAudit;
use App\Services\Envelopes\EnvelopeReadiness;
use App\Services\Envelopes\FieldGeometry;
use App\Services\Envelopes\FieldSync;
use App\Services\Envelopes\PageBox;
use App\Services\Envelopes\RecipientSync;
use App\Services\Envelopes\Reminders\ReminderProps;
use App\Services\Envelopes\Sending\CancelEnvelope;
use App\Services\Identity\IdentityCaptures;
use App\Services\Identity\IdentityFeatures;
use App\Services\Organizations\EnvelopeVisibility;
use App\Services\Retention\LegalHolds;
use App\Services\Retention\RetentionPresenter;
use App\Services\Signing\Certificates\ParticipantSignatureViews;
use App\Services\Signing\Channels\ChannelAvailability;
use App\Services\Tags\EnvelopeTagIndex;
use App\Services\Templates\TemplatesFeature;
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
 * Documentos (envelopes): listagem (ROUTES §2.5), detalhe (§2.7) e o wizard de preparo
 * (§2.6 — create/edit/update/duplicate/move/cancel/destroy).
 *
 * A prontidão para envio é recalculada a cada alteração por
 * `App\Services\Envelopes\EnvelopeReadiness`; o envio em si é do `EnvelopeSendController`.
 */
class EnvelopeController extends Controller
{
    private const TABS = ['all', 'awaiting', 'in_progress', 'completed', 'drafts', 'refused_expired'];

    /** Título de um rascunho que ainda não foi tocado; identifica o que pode ser reaproveitado. */
    public const DEFAULT_DRAFT_TITLE = 'Novo documento';

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

        // Fase 2 §2.14 — etiquetas (flag `tags`): só estreita o que a visibilidade já liberou.
        // Desligada, `constrain()` não altera a consulta e `props()` devolve `enabled: false`.
        $tagging = EnvelopeTagIndex::for($membership, $request->query('tag'));

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

        $base = fn (): Builder => $tagging->constrain(
            $this->applyFilters(EnvelopeVisibility::envelopes($membership), $filters, $folder, withStatus: false)
        );

        $tabs = [];
        foreach (self::TABS as $tab) {
            $tabs[$tab] = $this->applyTab($base(), $tab)->count();
        }

        $envelopes = $this->applyTab($base(), $filters['status'])
            ->with(['folder', 'creator', 'recipients', 'document', 'verificationRecord'])
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
            'filters' => [...$filters, 'tag' => $tagging->tagUlid()],
            'tagging' => $tagging->props($envelopes->getCollection()),
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
                // Papéis de sistema: owner/admin, como na Fase 1 (Permission::systemGrants).
                'bulk_cancel' => $membership->hasPermission(Permission::CancelAnyEnvelope),
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
            'verificationRecord.certificateReference',
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
            ->with(['recipient', 'value', 'documentVersion.document'])
            ->where('envelope_id', $envelope->getKey())
            ->orderBy('page')
            ->orderBy('sort_order')
            ->get();

        return Inertia::render('envelopes/show', [
            'envelope' => EnvelopeDetailResource::make($envelope)->resolve($request),
            'recipients' => $recipients->all(),
            'fields' => SigningFieldResource::collection($fields)->resolve($request),
            'events' => AuditEventResource::collection($events)->resolve($request),
            'folders' => FolderResource::collection(Folder::query()->whereNull('parent_id')->orderBy('name')->get())->resolve($request),
            'sent' => filter_var($validated['sent'] ?? false, FILTER_VALIDATE_BOOL),
            'tab' => $validated['tab'] ?? 'signers',
            // Fase 2 §2.5 (docs/fase-2/lembretes-e-agendamento.md): `available=false` com a
            // flag desligada — o front mantém a tela da Fase 1.
            'reminders' => app(ReminderProps::class)->forEnvelope($envelope),
            // Fase 2 §2.19 (K-RET, integração I-2C): selo "Preservado" e ações de preservar e
            // liberar. Com a flag desligada e sem bloqueio, o painel não renderiza nada.
            'legal_hold' => app(RetentionPresenter::class)->forEnvelope($envelope, CurrentOrganization::instance()->membership()),
            // Fase 2 §2.12 (K-A1, integração I-2C): só quando há pedidos de assinatura com o
            // certificado do próprio participante.
            ...ParticipantSignatureViews::evidenceProps($envelope),
        ]);
    }

    // -- Wizard e ações do documento ---------------------------------------------------

    /**
     * Nova solicitação: cria um Envelope(draft) vazio com os padrões da organização e
     * redireciona para o wizard (garante autosave e ID desde o primeiro clique).
     *
     * Fase 2 §2.1: com `?template={ulid}` e a flag `templates` ligada, mostra o formulário
     * "Usar modelo" (página templates/use) em vez de criar o rascunho vazio. Com a flag
     * desligada o parâmetro é ignorado — exatamente o comportamento da Fase 1.
     */
    public function create(Request $request): Response|RedirectResponse
    {
        Gate::authorize('create', Envelope::class);

        $organization = CurrentOrganization::instance()->get();

        $template = $request->query('template');

        if (is_string($template) && $template !== '' && TemplatesFeature::enabled($organization)) {
            return app(TemplateUseController::class)->form($request, $template);
        }
        $settings = OrganizationSettings::of($organization);

        // "Nova solicitação" está em três lugares e é um GET: cada clique (ou pré-busca do
        // navegador) criaria um rascunho vazio "Novo documento" e consumiria um número da
        // sequência que o cliente usa para se referir aos contratos, abrindo buracos nela.
        // Antes de criar, reaproveitamos o rascunho intocado que a própria pessoa deixou.
        $untouched = Envelope::query()
            ->where('created_by_user_id', $request->user()->getKey())
            ->where('status', EnvelopeStatus::Draft)
            ->where('title', self::DEFAULT_DRAFT_TITLE)
            ->whereNull('message')
            ->whereNull('folder_id')
            ->whereDoesntHave('document')
            ->whereDoesntHave('recipients')
            ->whereDoesntHave('fields')
            ->latest('id')
            ->first();

        if ($untouched !== null) {
            return redirect()->route('envelopes.edit', ['envelope' => $untouched->ulid, 'step' => 1]);
        }

        $envelope = Envelope::query()->create([
            'created_by_user_id' => $request->user()->getKey(),
            'title' => self::DEFAULT_DRAFT_TITLE,
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

        EnvelopeAudit::record($envelope, AuditEventType::EnvelopeCreated);

        return redirect()->route('envelopes.edit', ['envelope' => $envelope->ulid, 'step' => 1]);
    }

    /**
     * Wizard (ROUTES §2.6). O passo pedido é rebaixado quando o anterior está incompleto.
     */
    public function edit(Request $request, Envelope $envelope): Response|RedirectResponse
    {
        Gate::authorize('update', $envelope);

        if (! $envelope->status->isDraftLike()) {
            return redirect()->route('envelopes.show', $envelope);
        }

        $organization = CurrentOrganization::instance()->get();
        $settings = OrganizationSettings::of($organization);
        $subscription = $organization->currentSubscription()->with('plan')->first();

        $envelope->load([
            'organization', 'folder',
            'recipients',
            'document.currentVersion',
            'fields.recipient',
            'fields.documentVersion.document',
        ]);

        $completeness = EnvelopeReadiness::completeness($envelope);
        $step = $this->wizardStep((int) $request->integer('step', 1), $completeness);

        return Inertia::render('envelopes/wizard', [
            'envelope' => EnvelopeWizardResource::make($envelope)->resolve($request),
            'step' => $step,
            'document' => $this->documentProps($request, $envelope),
            // Fase 2 §2.3: todos os arquivos, na ordem de apresentação (o primeiro é o
            // mesmo de `document`). Contrato em docs/fase-2/multi-documento-e-papeis.md.
            'documents' => $this->documentsProps($request, $envelope),
            // Flags de domínio desta organização (derivadas de config + plano). Aditivo:
            // `features` compartilhado continua sendo a fonte do shell.
            'domain_features' => DomainFeatures::forOrganization($organization),
            'participant_roles' => array_map(
                static fn (RecipientRole $role): array => ['value' => $role->value, 'label' => $role->label()],
                RecipientRole::cases(),
            ),
            'recipients' => $envelope->recipients->values()
                ->map(fn (Recipient $recipient, int $index): array => RecipientWizardResource::make($recipient)
                    ->withColorIndex($index)
                    ->resolve($request))
                ->all(),
            'fields' => SigningFieldResource::collection($envelope->fields)->resolve($request),
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
                'max_fields' => FieldSync::MAX_FIELDS,
                'max_recipients' => 20,
                // 1 com a flag `multi_document` desligada (Fase 1).
                'max_documents' => DomainFeatures::maxDocuments($organization),
                // Mínimos por tipo, em pontos da página exibida: o editor divide pela
                // dimensão da página para obter a fração (docs/campos-e-geometria.md §3).
                'field_minimums' => FieldGeometry::minimumsForProps(),
            ],
            'completeness' => $completeness,
            // Pendências em PT-BR para o passo 4 (EnvelopeReadiness).
            'issues' => EnvelopeReadiness::issues($envelope),
            // Fase 2 §2.5: lembretes e envio agendado (`available=false` com a flag desligada).
            'reminders' => app(ReminderProps::class)->forEnvelope($envelope),
            // Fase 2 §2.9 (C-CAN): canais, métodos do código e PIN (`enabled=false` e
            // `pin.enabled=false` com as flags desligadas — o passo 2 é o da Fase 1).
            'channels' => app(ChannelAvailability::class)->wizardProps($organization),
            // Fase 2 §2.10 (C-ID): fotos exigidas por participante (`{ulid: kinds[]}`); só com
            // a flag `identity_capture`, senão null.
            'capture_requirements' => IdentityFeatures::identityCapture($organization)
                ? app(IdentityCaptures::class)->requirementsForEnvelope($envelope)
                : null,
        ]);
    }

    /**
     * PATCH dos metadados do wizard (autosave). Recalcula a prontidão a cada alteração.
     */
    public function update(UpdateEnvelopeRequest $request, Envelope $envelope): RedirectResponse
    {
        if (! $envelope->status->isDraftLike()) {
            abort(409, 'Ação indisponível no status atual.');
        }

        $validated = $request->validated();
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

        // Fase 2 §2.3: nova ordem dos arquivos. A coerência (cada documento do envelope
        // exatamente uma vez) e o lock ficam em DocumentIntake::reorder.
        if (array_key_exists('document_order', $validated)) {
            try {
                app(DocumentIntake::class)->reorder($envelope, array_values((array) $validated['document_order']), $request->user(), $request);
            } catch (UploadRejectedException $exception) {
                return back()->withErrors(['document_order' => $exception->getMessage()]);
            }
        }

        // A ordem de assinatura vive em dois lugares: `envelopes.signing_order` (aqui) e
        // `recipients.order_index` (RecipientSync, passo 2). Este autosave sai sozinho — o
        // wizard salva metadados e signatários em requisições separadas, e a de
        // signatários desiste quando algum ainda está incompleto. Sem reindexar, um
        // envelope ficava `sequential` com todo mundo na mesma vez: os convites saíam
        // todos de uma vez e a tela continuava anunciando "Assinatura em ordem".
        if (array_key_exists('signing_order', $validated)) {
            RecipientSync::reindex($envelope);
        }

        EnvelopeAudit::record($envelope, AuditEventType::EnvelopeUpdated, [
            'changed' => array_keys($validated),
        ]);

        EnvelopeReadiness::refresh($envelope);

        return back();
    }

    /**
     * Cancela o documento. A rotina completa (transição sob lock, pendentes para
     * `canceled`, revogação dos links, aviso a quem foi convidado e liberação do consumo
     * do plano quando ninguém assinou) fica em
     * `App\Services\Envelopes\Sending\CancelEnvelope`.
     */
    public function cancel(Request $request, Envelope $envelope, CancelEnvelope $cancellation): RedirectResponse
    {
        Gate::authorize('cancel', $envelope);

        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:500']], [], ['reason' => 'motivo']);

        if (! $envelope->status->isCancelable()) {
            return back()->with('error', 'Ação indisponível no status atual.');
        }

        $result = $cancellation->handle($envelope, $validated['reason'] ?? null);

        if (! $result['canceled']) {
            return back()->with('error', 'Ação indisponível no status atual.');
        }

        return back()->with('success', $result['notified'] > 0
            ? 'Documento cancelado. '.$result['notified'].' signatário(s) avisado(s).'
            : 'Documento cancelado.');
    }

    /**
     * Exclui o rascunho (soft delete). Os bytes no disco `documents` continuam até a
     * rotina de retenção — versões são imutáveis e podem ser referenciadas por cópias.
     */
    public function destroy(Request $request, Envelope $envelope): RedirectResponse
    {
        Gate::authorize('delete', $envelope);

        if (! $envelope->status->isDraftLike()) {
            return back()->with('error', 'Só rascunhos podem ser excluídos.');
        }

        // Fase 2 §2.19 (K-RET): a preservação legal vence a exclusão manual. Registra a tentativa
        // e volta com a mensagem (LegalHoldActiveException se renderiza). Sem bloqueio, nada muda.
        app(LegalHolds::class)->guardEnvelope($envelope, 'manual_delete', $request->user());

        // Sem evento próprio: RECONCILIACAO §3 não define `envelope.deleted` e a trilha do
        // rascunho continua acessível pelo soft delete.
        $envelope->delete();

        return redirect()->route('envelopes.index')->with('success', 'Rascunho excluído.');
    }

    /**
     * Duplica em um novo rascunho independente (documento original + signatários + campos).
     */
    public function duplicate(Request $request, Envelope $envelope, DuplicateEnvelope $duplicator): RedirectResponse
    {
        Gate::authorize('duplicate', $envelope);

        $copy = $duplicator->handle($envelope, $request->user());

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

        // Fase 2 §2.19 (integração I-2C): mover para fora de uma pasta preservada tiraria a
        // proteção. Sem bloqueio de pasta, nada muda (LegalHoldActiveException se renderiza).
        app(LegalHolds::class)->guardMove($envelope, $folder?->getKey(), 'move', $request->user());

        $envelope->forceFill(['folder_id' => $folder?->getKey()])->save();

        EnvelopeAudit::record($envelope, AuditEventType::EnvelopeMoved, ['folder' => $folder?->ulid]);

        return back()->with('success', $folder ? 'Documento movido para '.$folder->name.'.' : 'Documento movido para "Todos".');
    }

    // -- Props do wizard ---------------------------------------------------------------

    /**
     * Passo pedido, rebaixado quando um passo anterior está incompleto (ROUTES §2.6).
     *
     * @param  array{document: bool, recipients: bool, fields: bool}  $completeness
     * @return int<1, 4>
     */
    protected function wizardStep(int $requested, array $completeness): int
    {
        $step = max(1, min(4, $requested));

        if ($step >= 2 && ! $completeness['document']) {
            return 1;
        }

        if ($step >= 3 && ! $completeness['recipients']) {
            return 2;
        }

        if ($step >= 4 && ! $completeness['fields']) {
            return 3;
        }

        return $step;
    }

    /**
     * `WizardProps.document` — reaproveita o `DocumentResource` do pipeline documental
     * (B-DOC) e acrescenta `pages_meta`: a caixa exibida de cada página, que é o que o
     * editor precisa para converter pixels do canvas em frações [0,1]
     * (docs/campos-e-geometria.md). As dimensões saem SEMPRE do banco, nunca do navegador.
     *
     * @return array<string, mixed>|null
     */
    protected function documentProps(Request $request, Envelope $envelope): ?array
    {
        $document = $envelope->document;

        if ($document === null) {
            return null;
        }

        $document->setRelation('envelope', $envelope);

        $version = $document->currentVersion;
        $pagesMeta = [];

        foreach ($version === null ? [] : ($version->pages_meta ?? []) as $index => $meta) {
            $pagesMeta[] = ['page' => $index + 1] + PageBox::fromPageMeta($meta)->toArray();
        }

        return DocumentResource::make($document)->resolve($request) + ['pages_meta' => $pagesMeta];
    }

    /**
     * `WizardProps.documents` (Fase 2 §2.3): um item por arquivo, na ordem de apresentação,
     * com o mesmo formato de `document` (+ `position`).
     *
     * @return list<array<string, mixed>>
     */
    protected function documentsProps(Request $request, Envelope $envelope): array
    {
        $props = [];

        foreach (EnvelopeDocuments::ordered($envelope) as $document) {
            $document->setRelation('envelope', $envelope);

            $version = $document->currentVersion;
            $pagesMeta = [];

            foreach ($version === null ? [] : ($version->pages_meta ?? []) as $index => $meta) {
                $pagesMeta[] = ['page' => $index + 1] + PageBox::fromPageMeta($meta)->toArray();
            }

            $props[] = DocumentResource::make($document)->resolve($request) + ['pages_meta' => $pagesMeta];
        }

        return $props;
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
            // Fase 2 §2.14: com acesso por pasta, quem não vê tudo também vê documentos de
            // outros autores — o filtro lista os autores do que a pessoa já vê (e ela mesma).
            // Sem acesso por pasta o resultado é o da Fase 1: só a própria pessoa.
            $ids = EnvelopeVisibility::envelopes($membership)
                ->reorder()
                ->distinct()
                ->pluck('created_by_user_id')
                ->map(static fn ($id): int => (int) $id)
                ->reject(static fn (int $id): bool => $id === (int) $membership->user_id)
                ->values();

            if ($ids->isEmpty()) {
                return [UserRefResource::ref($membership->user)];
            }

            return User::query()->whereIn('id', [...$ids->all(), (int) $membership->user_id])->orderBy('name')->get()
                ->map(fn (User $u) => UserRefResource::ref($u))
                ->values()
                ->all();
        }

        $ids = Envelope::query()->distinct()->pluck('created_by_user_id');

        return User::query()->whereIn('id', $ids)->orderBy('name')->get()
            ->map(fn (User $u) => UserRefResource::ref($u))
            ->values()
            ->all();
    }
}
