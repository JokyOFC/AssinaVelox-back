<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Home institucional mínima (ROUTES §1.3 home): hero + CTA login/cadastro.
 */
class HomeController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('marketing/home', [
            'help_url' => (string) config('assinavelox.help_url'),
            'support_email' => (string) config('assinavelox.support_email'),
        ]);
    }
}
