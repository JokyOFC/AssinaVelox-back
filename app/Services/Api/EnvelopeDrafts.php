<?php

namespace App\Services\Api;

use App\Enums\AuditEventType;
use App\Enums\EnvelopeStatus;
use App\Enums\SigningOrder;
use App\Models\Envelope;
use App\Models\Folder;
use App\Models\Organization;
use App\Models\User;
use App\Services\Envelopes\EnvelopeAudit;
use App\Support\OrganizationSettings;

/**
 * "Novo documento" pela API: o MESMO rascunho que `EnvelopeController::create` abre na
 * interface (padrões da organização: ordem, prazo, rubrica em todas as páginas, código por
 * e-mail) e o mesmo evento `envelope.created` — com os metadados do corpo já aplicados, como o
 * autosave do passo 1 do wizard faria (`EnvelopeController::update`).
 *
 * A diferença é deliberada: a interface reaproveita um rascunho intocado para não abrir
 * buracos na numeração a cada clique num GET; na API a criação é um POST idempotente
 * (`Idempotency-Key`), então cada chave nova é, de fato, um documento novo.
 */
final class EnvelopeDrafts
{
    /**
     * @param  array{title: string, message?: string|null, signing_order?: string|null, expires_in_days?: int|string|null, folder_id?: string|null, send_copy_to_all?: bool|null}  $input
     */
    public function create(Organization $organization, User $user, array $input): Envelope
    {
        $settings = OrganizationSettings::of($organization);

        $folderId = null;

        if (! empty($input['folder_id'])) {
            $folderId = Folder::query()
                ->where('organization_id', $organization->getKey())
                ->where('ulid', $input['folder_id'])
                ->value('id');
        }

        $signingOrder = ! empty($input['signing_order'])
            ? SigningOrder::from((string) $input['signing_order'])
            : $settings->defaultSigningOrder();

        $expirationDays = isset($input['expires_in_days']) && $input['expires_in_days'] !== ''
            ? (int) $input['expires_in_days']
            : $settings->defaultExpirationDays();

        $envelope = Envelope::query()->create([
            'organization_id' => $organization->getKey(),
            'created_by_user_id' => $user->getKey(),
            'folder_id' => $folderId,
            'title' => trim((string) $input['title']),
            'message' => isset($input['message']) && trim((string) $input['message']) !== '' ? trim((string) $input['message']) : null,
            'status' => EnvelopeStatus::Draft,
            'signing_order' => $signingOrder,
            'terms_version' => (string) config('assinavelox.terms_version'),
            'settings' => [
                'otp_required' => true,
                'expiration_days' => $expirationDays,
                'initials_on_all_pages' => $settings->initialsOnAllPages(),
                'send_copy_to_all' => (bool) ($input['send_copy_to_all'] ?? false),
            ],
        ]);

        EnvelopeAudit::record($envelope, AuditEventType::EnvelopeCreated, ['channel' => 'api']);

        return $envelope;
    }
}
