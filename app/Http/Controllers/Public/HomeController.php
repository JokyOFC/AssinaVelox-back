<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\LandingRoute;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Entrada do site. Não há página institucional: o visitante vai direto para o login, e quem
 * já está autenticado vai para onde o login o levaria (LandingRoute).
 *
 * A rota continua com o nome `home` porque o logo das cascas pública e de autenticação, as
 * páginas de erro e os redirecionamentos de logout e de exclusão de conta apontam para ela.
 */
class HomeController extends Controller
{
    public function index(Request $request): RedirectResponse
    {
        $user = $request->user();

        return redirect($user instanceof User ? LandingRoute::for($user) : route('login'));
    }
}
