<?php

namespace App\Http\Controllers;

use App\Http\Requests\Folders\StoreFolderRequest;
use App\Http\Requests\Folders\UpdateFolderRequest;
use App\Models\Envelope;
use App\Models\Folder;
use App\Services\Retention\LegalHolds;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Pastas (ROUTES §1.2 folders.*): sem hierarquia na UI da Fase 1 (parent_id nulo).
 * Excluir devolve os envelopes para a raiz ("Todos") e move subpastas para a raiz.
 */
class FolderController extends Controller
{
    public function store(StoreFolderRequest $request): RedirectResponse
    {
        $folder = Folder::query()->create([
            'name' => trim($request->validated('name')),
            'parent_id' => null,
            'created_by_user_id' => $request->user()->getKey(),
        ]);

        return back()->with('success', 'Pasta "'.$folder->name.'" criada.');
    }

    public function update(UpdateFolderRequest $request, Folder $folder): RedirectResponse
    {
        $folder->forceFill(['name' => trim($request->validated('name'))])->save();

        return back()->with('success', 'Pasta renomeada para "'.$folder->name.'".');
    }

    public function destroy(Request $request, Folder $folder): RedirectResponse
    {
        Gate::authorize('delete', $folder);

        $name = $folder->name;

        // Fase 2 §2.19 (integração I-2C): excluir uma pasta preservada tiraria a proteção dos
        // documentos. Sem bloqueio, nada muda (LegalHoldActiveException volta com a mensagem).
        app(LegalHolds::class)->guardFolderDeletion($folder, 'folder_delete', $request->user());

        DB::transaction(function () use ($folder): void {
            Envelope::query()->where('folder_id', $folder->getKey())->update(['folder_id' => null]);
            Folder::query()->where('parent_id', $folder->getKey())->update(['parent_id' => null]);
            $folder->delete();
        });

        return redirect()
            ->route('envelopes.index')
            ->with('success', 'Pasta "'.$name.'" excluída. Os documentos voltaram para "Todos".');
    }
}
