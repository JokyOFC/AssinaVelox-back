<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Itens do painel interno ainda sem escopo (ROUTES §1.5 / §2.21): renderiza
 * `admin/placeholder` com a feature derivada da rota.
 *
 * Fase 2: `admin.users.index` e `admin.audit.index` têm controllers próprios
 * (UserController, AuditController) que caem aqui enquanto as flags `admin_users` /
 * `admin_audit` estão desligadas.
 */
class PlaceholderController extends Controller
{
    /** @var array<string, array{feature: string, title: string, subtitle: string}> */
    private const PAGES = [
        'admin.billing.index' => [
            'feature' => 'admin_billing',
            'title' => 'Planos e faturamento',
            'subtitle' => 'Receita, planos ativos e conciliação com o Mercado Pago.',
        ],
        'admin.users.index' => [
            'feature' => 'admin_users',
            'title' => 'Usuários da plataforma',
            'subtitle' => 'Contas internas e permissões da equipe AssinaVelox.',
        ],
        'admin.audit.index' => [
            'feature' => 'admin_audit',
            'title' => 'Logs e auditoria',
            'subtitle' => 'Trilha de ações da equipe e exportação com hash.',
        ],
        'admin.settings.index' => [
            'feature' => 'admin_settings',
            'title' => 'Configurações globais',
            'subtitle' => 'Parâmetros da plataforma, certificado da operadora e integrações.',
        ],
    ];

    public function __invoke(Request $request): Response
    {
        return self::render($request->route()?->getName() ?? '');
    }

    public static function render(string $routeName): Response
    {
        return Inertia::render('admin/placeholder', self::PAGES[$routeName] ?? self::PAGES['admin.settings.index']);
    }
}
