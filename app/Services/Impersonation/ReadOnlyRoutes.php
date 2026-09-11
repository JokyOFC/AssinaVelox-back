<?php

namespace App\Services\Impersonation;

/**
 * Lista FECHADA do que uma sessão de "acessar como" pode fazer.
 *
 * Por que lista de permitidos (e não de proibidos): qualquer rota nova — de outra onda, de
 * um pacote (Fortify, passkeys) — nasce BLOQUEADA durante a impersonation até alguém decidir
 * o contrário aqui. Ficam de fora, entre outras:
 *  - todo método diferente de GET/HEAD (criar, editar, enviar, cancelar, excluir...);
 *  - GETs que alteram estado (`envelopes.create` cria rascunho);
 *  - conteúdo de documento: preview, páginas, downloads, evidências (o suporte não vê
 *    conteúdo de documentos — Termos §4.5);
 *  - exportações CSV;
 *  - cobrança, planos e recibos;
 *  - credenciais (API, chaves), perfil, senha, 2FA, códigos de recuperação e passkeys.
 */
final class ReadOnlyRoutes
{
    /** GETs permitidos (páginas de consulta). */
    public const ALLOWED_GET = [
        'dashboard',
        'search.index',
        'notifications.index',
        'envelopes.index',
        'envelopes.show',
        'recipients.index',
        'templates.index',
        'integrations.index',
        'members.index',
        'settings.general',
        'settings.signing',
        'settings.notifications',
        'settings.tags',
        'settings.audit',
        'reports.index',
    ];

    /** Encerrar a própria impersonation é o único POST aceito. */
    public const STOP_ROUTE = 'admin.impersonation.stop';

    /** `POST /logout` durante a impersonation encerra a sessão de suporte por completo. */
    public const LOGOUT_ROUTE = 'logout';

    public static function allows(string $method, ?string $routeName): bool
    {
        if ($routeName === self::STOP_ROUTE) {
            return true;
        }

        if (! in_array(strtoupper($method), ['GET', 'HEAD'], true)) {
            return false;
        }

        return $routeName !== null && in_array($routeName, self::ALLOWED_GET, true);
    }
}
