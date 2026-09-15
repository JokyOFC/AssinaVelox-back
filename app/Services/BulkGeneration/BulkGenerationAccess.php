<?php

namespace App\Services\BulkGeneration;

use App\Enums\Permission;
use App\Models\BulkGeneration;
use App\Models\Membership;
use App\Models\Template;
use App\Models\User;
use App\Support\CurrentOrganization;
use Illuminate\Support\Facades\Gate;

/**
 * Quem pode o quê no lote (docs/fase-3/geracao-em-lote.md §2). A flag só liga as rotas; isto
 * decide. O isolamento vem do binding escopado (lote de outra organização = 404) e da
 * membership da organização CORRENTE.
 *
 *  - gerar a partir de um modelo: a mesma regra de "Usar modelo" (`TemplatePolicy::use`:
 *    `create_envelopes` e modelo ativo);
 *  - ver um lote: quem o criou, ou `view_all_envelopes` / `manage_any_envelope`;
 *  - mapear, confirmar, descartar: quem o criou (ou `manage_any_envelope`), com `create_envelopes`;
 *  - enviar ou agendar ao gerar: também `send_envelopes`;
 *  - cancelar: quem o criou, ou `cancel_any_envelope` / `manage_any_envelope`.
 */
final class BulkGenerationAccess
{
    public static function membership(): ?Membership
    {
        return CurrentOrganization::instance()->membership();
    }

    public static function canCreateFrom(User $user, Template $template): bool
    {
        return Gate::forUser($user)->allows('use', $template);
    }

    public static function canList(): bool
    {
        return self::has(Permission::CreateEnvelopes) || self::seesAll();
    }

    public static function seesAll(): bool
    {
        return self::has(Permission::ViewAllEnvelopes) || self::has(Permission::ManageAnyEnvelope);
    }

    public static function canView(User $user, BulkGeneration $batch): bool
    {
        return self::sameOrganization($batch)
            && (self::isCreator($user, $batch) || self::seesAll());
    }

    public static function canManage(User $user, BulkGeneration $batch): bool
    {
        return self::sameOrganization($batch)
            && self::has(Permission::CreateEnvelopes)
            && (self::isCreator($user, $batch) || self::has(Permission::ManageAnyEnvelope));
    }

    public static function canCancel(User $user, BulkGeneration $batch): bool
    {
        return self::sameOrganization($batch)
            && (self::isCreator($user, $batch)
                || self::has(Permission::CancelAnyEnvelope)
                || self::has(Permission::ManageAnyEnvelope));
    }

    public static function canSend(): bool
    {
        return self::has(Permission::SendEnvelopes);
    }

    private static function isCreator(User $user, BulkGeneration $batch): bool
    {
        return $batch->created_by_user_id !== null && $batch->created_by_user_id === $user->getKey();
    }

    private static function sameOrganization(BulkGeneration $batch): bool
    {
        $membership = self::membership();

        return $membership !== null && (int) $membership->organization_id === (int) $batch->organization_id;
    }

    private static function has(Permission $permission): bool
    {
        return self::membership()?->hasPermission($permission) ?? false;
    }
}
