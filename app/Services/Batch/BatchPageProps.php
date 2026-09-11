<?php

namespace App\Services\Batch;

use App\Enums\FieldType;
use App\Enums\RecipientRole;
use App\Models\Document;
use App\Models\Envelope;
use App\Models\Organization;
use App\Models\Recipient;
use App\Models\SigningField;
use App\Services\Batch\Models\BatchSigningItem;
use App\Services\Batch\Models\BatchSigningSession;
use App\Services\InPerson\ParticipantSigningProps;
use App\Services\Signing\ConsentText;
use App\Services\Signing\SignerContext;
use Illuminate\Http\Request;

/**
 * Props de `pages/sign-batch/show.tsx` (docs/fase-2/presencial-e-lote.md §3.5).
 *
 * | screen        | quando                                                   |
 * |---------------|----------------------------------------------------------|
 * | `unavailable` | interruptor global `batch_signing` desligado             |
 * | `none`        | nenhum link de lote aberto neste navegador               |
 * | `invalid`     | link revogado, vencido ou desconhecido (404)             |
 * | `identify`    | link válido, código ainda não confirmado neste navegador |
 * | `list`        | lista de documentos com o estado de cada um              |
 * | `item`        | um documento aberto para revisão e autorização           |
 *
 * Antes do código só vai o que explica o que é o link: organização, primeiro nome, e-mail
 * mascarado e a quantidade de documentos — nenhum título. Depois do código, a lista.
 */
final class BatchPageProps
{
    public function __construct(
        private readonly BatchItems $items,
        private readonly BatchChallenges $challenges,
        private readonly BatchBrowser $browser,
        private readonly ParticipantSigningProps $signing,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function empty(string $screen): array
    {
        return [
            'screen' => $screen,
            'sender' => [
                'organization_name' => (string) config('app.name', 'AssinaVelox'),
                'organization_initials' => 'AV',
                'logo_url' => null,
                'user_name' => '',
            ],
            'batch' => null,
            'recipient' => null,
            'otp' => null,
            'items' => [],
            'current' => null,
            'privacy' => null,
            'legal' => ParticipantSigningProps::legal(),
            'limits' => ParticipantSigningProps::limits(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function build(BatchSigningSession $batch, Request $request, ?string $itemUlid = null): array
    {
        /** @var Organization $organization */
        $organization = Organization::query()->whereKey($batch->organization_id)->firstOrFail();
        /** @var Recipient $anchor */
        $anchor = Recipient::withoutOrganizationScope()->whereKey($batch->anchor_recipient_id)->firstOrFail();

        $authenticated = $this->browser->isAuthenticated($batch, $request);
        $items = $this->items->itemsOf($batch);

        /** @var array<int, array{state: string, label: string, authorizable: bool, reason: string|null, context: SignerContext|null, recipient: Recipient|null, envelope: Envelope|null}> $described */
        $described = [];

        foreach ($items as $item) {
            $described[$item->getKey()] = $this->items->describe($item);
        }

        $noticeRecipient = self::noticeRecipient($described);

        $props = array_replace($this->empty($authenticated ? 'list' : 'identify'), [
            'sender' => [
                'organization_name' => $organization->name,
                'organization_initials' => $organization->initials,
                'logo_url' => null,
                'user_name' => $organization->name,
            ],
            'batch' => [
                'id' => $batch->ulid,
                'expires_at' => $batch->expires_at->toIso8601String(),
                'items_count' => $items->count(),
                'session_expires_at' => $authenticated ? $batch->session_expires_at?->toIso8601String() : null,
            ],
            'recipient' => [
                'first_name' => ParticipantSigningProps::firstName($anchor->name),
                'email_masked' => $anchor->masked_email,
            ],
            // Condicional ao que o lote de fato pede (revisão adversarial da onda B): com um item
            // autorizável que tem campo CPF, o aviso diz que o CPF é pedido (e, com `cpf_lookup`,
            // que é consultado) em vez de "Não pedimos senha, CPF…".
            'privacy' => [
                'version' => ConsentText::privacyNoticeVersion(RecipientRole::Signer, $noticeRecipient),
                'summary' => ConsentText::privacySummary($organization, RecipientRole::Signer, $noticeRecipient),
                'notice' => ConsentText::privacyNotice($organization, RecipientRole::Signer, $noticeRecipient),
            ],
        ]);

        if (! $authenticated) {
            $props['otp'] = $this->challenges->props($batch);

            return $props;
        }

        $list = [];
        $current = null;

        foreach ($items as $item) {
            $itemDescribed = $described[$item->getKey()];
            $envelope = $itemDescribed['envelope'];
            $context = $itemDescribed['context'];
            $open = $context !== null && $this->items->session($item, $context, $request) !== null;
            $action = $itemDescribed['recipient']?->role->acceptanceAction();

            $list[] = [
                'id' => $item->ulid,
                'position' => $item->position,
                'title' => $envelope?->title,
                'display_code' => $envelope?->display_code,
                'expires_at' => $envelope?->expires_at?->toIso8601String(),
                'action_label' => $action?->buttonLabel(),
                'action_type' => $action?->value,
                'state' => $itemDescribed['state'],
                'state_label' => $itemDescribed['label'],
                'authorizable' => $itemDescribed['authorizable'],
                'reason' => $itemDescribed['reason'],
                'open' => $open,
                'authorized_at' => $item->authorized_at?->toIso8601String(),
                'last_error_code' => $item->last_error_code,
            ];

            if ($itemUlid !== null && $item->ulid === $itemUlid && $itemDescribed['authorizable'] && $context !== null) {
                $session = $this->items->session($item, $context, $request);

                if ($session !== null) {
                    $signing = $this->signing->build(
                        $context,
                        $session,
                        static fn (Document $document): string => route('sign.batch.document', ['item' => $item->ulid, 'document' => $document->ulid]),
                    );

                    $current = $signing === null ? null : ['id' => $item->ulid] + $signing + [
                        'privacy' => ParticipantSigningProps::privacy($context),
                    ];
                }
            }
        }

        $props['items'] = $list;
        $props['current'] = $current;
        $props['screen'] = $current === null ? 'list' : 'item';

        return $props;
    }

    /**
     * Participante cujo aviso de privacidade descreve o que ESTE LOTE pede, ou null (aviso padrão).
     *
     * O lote só autoriza itens com código por e-mail, sem PIN e sem fotos
     * ({@see BatchItems::individualOnlyReason()}): os demais vão para o link individual, cujo
     * aviso por participante descreve o que eles pedem. Entre os autorizáveis, o único
     * complemento possível do aviso é o campo CPF (e a consulta cadastral, que depende da flag
     * da organização — a mesma para todos os itens). Por isso basta um item autorizável com CPF
     * para que o aviso de `ConsentText` desse participante seja exatamente o aviso do lote.
     *
     * @param  array<int, array{authorizable: bool, recipient: Recipient|null}>  $described
     */
    private static function noticeRecipient(array $described): ?Recipient
    {
        foreach ($described as $item) {
            $recipient = $item['recipient'];

            if (! $item['authorizable'] || $recipient === null) {
                continue;
            }

            $hasCpf = SigningField::withoutOrganizationScope()
                ->where('recipient_id', $recipient->getKey())
                ->where('type', FieldType::Cpf->value)
                ->exists();

            if ($hasCpf) {
                return $recipient;
            }
        }

        return null;
    }

    /**
     * Item aberto neste navegador para a rota do PDF (null = 404).
     */
    public function documentItem(BatchSigningSession $batch, string $itemUlid): ?BatchSigningItem
    {
        return $this->items->find($batch, $itemUlid);
    }
}
