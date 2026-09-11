<?php

namespace App\Http\Controllers\Members;

use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Requests\Members\SyncFolderAccessRequest;
use App\Models\Membership;
use App\Support\PermissionsFolderAccess;
use App\Support\PermissionsTrail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * PUT /usuarios/{membership}/pastas — acesso DIRETO de uma pessoa a pastas (Fase 2 §2.14).
 * Exige `manage_folders`; ninguém altera o próprio acesso (MembershipPolicy::manageFolders).
 */
class MembershipFolderAccessController extends Controller
{
    public function update(SyncFolderAccessRequest $request, Membership $membership): RedirectResponse
    {
        Gate::authorize('manageFolders', $membership);

        $grants = $request->grants();

        $changed = PermissionsFolderAccess::sync(
            $membership->organization_id,
            PermissionsFolderAccess::SUBJECT_MEMBERSHIP,
            (int) $membership->getKey(),
            $grants,
            $request->user()->getKey(),
        );

        if ($changed) {
            PermissionsTrail::record($membership->organization_id, AuditEventType::FolderAccessUpdated, [
                'subject' => PermissionsFolderAccess::SUBJECT_MEMBERSHIP,
                'membership' => (int) $membership->getKey(),
                'folders' => PermissionsFolderAccess::folderUlids($membership->organization_id, array_keys($grants)),
            ]);

            HandleInertiaRequests::forgetCounts($membership->organization_id, $membership->user_id);
        }

        return back()->with('success', 'Pastas com acesso de '.$membership->user->name.' atualizadas.');
    }
}
