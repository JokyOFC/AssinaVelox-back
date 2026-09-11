<?php

namespace App\Support;

use App\Enums\FolderAccessLevel;
use App\Enums\Permission;
use App\Models\Folder;
use App\Models\FolderPermission;
use App\Models\Membership;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Acesso por pasta (folder_permissions). Um grant alcança uma membership quando é
 * direto (membership_id), pela função efetiva (role_id — personalizada ou de sistema) ou
 * por um time de que ela participa (team_id). Vale o MAIOR nível entre eles.
 *
 * Toda consulta filtra `organization_id` da membership: nada de outra organização entra,
 * mesmo que um id seja forjado.
 */
final class PermissionsFolderAccess
{
    public const SUBJECT_ROLE = 'role';

    public const SUBJECT_TEAM = 'team';

    public const SUBJECT_MEMBERSHIP = 'membership';

    private const SUBJECT_COLUMNS = [
        self::SUBJECT_ROLE => 'role_id',
        self::SUBJECT_TEAM => 'team_id',
        self::SUBJECT_MEMBERSHIP => 'membership_id',
    ];

    /**
     * Grants que alcançam a membership (consulta base, sem select).
     */
    public static function grantsQuery(Membership $membership): Builder
    {
        // Flag `custom_roles` desligada (interruptor global ou plano rebaixado): o acesso por
        // pasta gravado não vale — Operador volta a ver só o que criou, como na Fase 1
        // (roadmap §1 T1/T8; pendência 2 do relatório I-2A).
        if (! $membership->customRolesEnabled()) {
            return DB::table('folder_permissions')->whereRaw('1 = 0');
        }

        $roleId = $membership->effectiveRoleId();
        $membershipId = $membership->getKey();

        return DB::table('folder_permissions')
            ->where('folder_permissions.organization_id', $membership->organization_id)
            ->where(function (Builder $query) use ($membershipId, $roleId): void {
                $query->where('folder_permissions.membership_id', $membershipId)
                    ->orWhereIn(
                        'folder_permissions.team_id',
                        DB::table('team_memberships')->select('team_memberships.team_id')->where('team_memberships.membership_id', $membershipId),
                    );

                if ($roleId !== null) {
                    $query->orWhere('folder_permissions.role_id', $roleId);
                }
            });
    }

    /**
     * Subconsulta `SELECT folder_id` das pastas acessíveis (para `whereIn`).
     */
    public static function folderIdsQuery(Membership $membership, FolderAccessLevel $minimum = FolderAccessLevel::View): Builder
    {
        $query = self::grantsQuery($membership)->select('folder_permissions.folder_id');

        if ($minimum === FolderAccessLevel::Manage) {
            $query->where('folder_permissions.level', FolderAccessLevel::Manage->value);
        }

        return $query;
    }

    /**
     * @return array<int, FolderAccessLevel> folder_id → maior nível
     */
    public static function levelsFor(Membership $membership): array
    {
        if (! $membership->isActive() || $membership->getKey() === null) {
            return [];
        }

        $levels = [];

        foreach (self::grantsQuery($membership)->get(['folder_permissions.folder_id', 'folder_permissions.level']) as $row) {
            $level = FolderAccessLevel::tryFrom((string) $row->level);

            if ($level !== null) {
                $folderId = (int) $row->folder_id;
                $levels[$folderId] = FolderAccessLevel::max($levels[$folderId] ?? null, $level);
            }
        }

        return $levels;
    }

    public static function levelFor(Membership $membership, ?int $folderId): ?FolderAccessLevel
    {
        return $folderId === null ? null : ($membership->folderLevels()[$folderId] ?? null);
    }

    /**
     * Nível que o ATOR efetivamente tem sobre a pasta, considerando também as permissões
     * de conta (ver todos → view; ver todos + editar de outros → manage).
     */
    public static function actorLevel(Membership $actor, int $folderId): ?FolderAccessLevel
    {
        $direct = self::levelFor($actor, $folderId);

        if ($actor->hasPermission(Permission::ViewAllEnvelopes)) {
            return $actor->hasPermission(Permission::ManageAnyEnvelope)
                ? FolderAccessLevel::Manage
                : FolderAccessLevel::max(FolderAccessLevel::View, $direct);
        }

        return $direct;
    }

    /**
     * O ator já tem, em cada pasta, pelo menos o nível que está concedendo? Exigido quando
     * o grant alcança o próprio ator (sua função, um time de que participa) — ninguém
     * amplia o próprio acesso.
     *
     * @param  array<int, FolderAccessLevel>  $grants
     */
    public static function actorCovers(Membership $actor, array $grants): bool
    {
        foreach ($grants as $folderId => $level) {
            $held = self::actorLevel($actor, (int) $folderId);

            if ($held === null || ! $held->covers($level)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<int, FolderAccessLevel> folder_id → nível, do sujeito informado
     */
    public static function grantsFor(int $organizationId, string $subject, int $subjectId): array
    {
        $column = self::column($subject);

        return FolderPermission::forOrganization($organizationId)
            ->where($column, $subjectId)
            ->get(['folder_id', 'level'])
            ->mapWithKeys(fn (FolderPermission $grant): array => [(int) $grant->folder_id => $grant->level])
            ->all();
    }

    /**
     * Substitui os grants do sujeito. Pastas de outra organização são ignoradas (nunca
     * confiar em ids vindos do navegador). Devolve true se algo mudou.
     *
     * @param  array<int, FolderAccessLevel>  $grants  folder_id → nível
     */
    public static function sync(int $organizationId, string $subject, int $subjectId, array $grants, ?int $grantedByUserId = null): bool
    {
        $column = self::column($subject);

        $validFolderIds = $grants === [] ? [] : Folder::forOrganization($organizationId)
            ->whereIn('id', array_map(intval(...), array_keys($grants)))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $desired = [];

        foreach ($validFolderIds as $folderId) {
            $desired[$folderId] = $grants[$folderId];
        }

        return DB::transaction(function () use ($organizationId, $column, $subjectId, $desired, $grantedByUserId): bool {
            $current = FolderPermission::forOrganization($organizationId)
                ->where($column, $subjectId)
                ->lockForUpdate()
                ->get();

            $changed = false;

            foreach ($current as $grant) {
                $wanted = $desired[(int) $grant->folder_id] ?? null;

                if ($wanted === null) {
                    $grant->delete();
                    $changed = true;

                    continue;
                }

                if ($grant->level !== $wanted) {
                    $grant->forceFill(['level' => $wanted, 'granted_by_user_id' => $grantedByUserId])->save();
                    $changed = true;
                }

                unset($desired[(int) $grant->folder_id]);
            }

            foreach ($desired as $folderId => $level) {
                $grant = new FolderPermission;
                $grant->forceFill([
                    'organization_id' => $organizationId,
                    'folder_id' => $folderId,
                    'role_id' => null,
                    'team_id' => null,
                    'membership_id' => null,
                    $column => $subjectId,
                    'level' => $level,
                    'granted_by_user_id' => $grantedByUserId,
                ])->save();
                $changed = true;
            }

            return $changed;
        });
    }

    /**
     * Converte a lista vinda do cliente ([{folder: ulid, level}]) em folder_id → nível,
     * só com pastas da organização informada.
     *
     * @param  array<int, mixed>  $items
     * @return array<int, FolderAccessLevel>
     */
    public static function parse(int $organizationId, array $items): array
    {
        $byUlid = [];

        foreach ($items as $item) {
            if (! is_array($item) || ! is_string($item['folder'] ?? null)) {
                continue;
            }

            $level = FolderAccessLevel::tryFrom((string) ($item['level'] ?? FolderAccessLevel::View->value));

            if ($level !== null) {
                $byUlid[$item['folder']] = FolderAccessLevel::max($byUlid[$item['folder']] ?? null, $level);
            }
        }

        if ($byUlid === []) {
            return [];
        }

        $ids = Folder::forOrganization($organizationId)
            ->whereIn('ulid', array_keys($byUlid))
            ->pluck('id', 'ulid');

        $grants = [];

        foreach ($ids as $ulid => $id) {
            $level = $byUlid[$ulid] ?? null;

            if ($level !== null) {
                $grants[(int) $id] = $level;
            }
        }

        return $grants;
    }

    /**
     * Forma pública (ulid da pasta) dos grants de um sujeito, para a UI.
     *
     * @return array<int, array{folder: string, name: string, level: string}>
     */
    public static function present(int $organizationId, string $subject, int $subjectId): array
    {
        $column = self::column($subject);

        return FolderPermission::forOrganization($organizationId)
            ->with('folder:id,ulid,name')
            ->where($column, $subjectId)
            ->get()
            ->sortBy(fn (FolderPermission $grant): string => $grant->folder->name)
            ->map(fn (FolderPermission $grant): array => [
                'folder' => $grant->folder->ulid,
                'name' => $grant->folder->name,
                'level' => $grant->level->value,
            ])
            ->values()
            ->all();
    }

    /**
     * Ulids (identificadores públicos) das pastas, para a trilha.
     *
     * @param  array<int, int>  $folderIds
     * @return list<string>
     */
    public static function folderUlids(int $organizationId, array $folderIds): array
    {
        if ($folderIds === []) {
            return [];
        }

        return array_values(Folder::forOrganization($organizationId)
            ->whereIn('id', $folderIds)
            ->orderBy('id')
            ->pluck('ulid')
            ->map(fn ($ulid): string => (string) $ulid)
            ->all());
    }

    private static function column(string $subject): string
    {
        return self::SUBJECT_COLUMNS[$subject] ?? throw new InvalidArgumentException("Sujeito de acesso por pasta inválido: {$subject}.");
    }
}
