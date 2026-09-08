<?php

use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Conta do usuário: perfil e segurança (senha, 2FA).
|--------------------------------------------------------------------------
| URIs em PT-BR (ROUTES_AND_PAGES §1.2: `profile.edit` é GET /perfil); os NOMES de rota
| continuam em inglês (`profile.edit`, `security.edit`, `user-password.update`) porque são
| o contrato dos helpers Wayfinder e do front.
|
| Não passam pelo middleware `org` — o usuário pode gerenciar a própria conta mesmo sem
| organização ativa (ex.: convidado ainda sem membership).
*/

Route::middleware(['auth'])->group(function () {
    Route::get('perfil', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('perfil', [ProfileController::class, 'update'])->name('profile.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::delete('perfil', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('perfil/seguranca', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('perfil/senha', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');
});
