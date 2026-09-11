import { createInertiaApp } from '@inertiajs/react';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';
import PublicLayout from '@/layouts/public-layout';
import SettingsLayout from '@/layouts/settings/layout';
import SignerLayout from '@/layouts/signer-layout';

const appName = import.meta.env.VITE_APP_NAME || 'AssinaVelox';

void createInertiaApp({
    title: (title) => (title ? `${title} · ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name.startsWith('auth/'):
            case name.startsWith('invitations/'):
                return AuthLayout;
            case name.startsWith('sign/'):
                return SignerLayout;
            case name.startsWith('verify/'):
            case name.startsWith('legal/'):
            case name.startsWith('marketing/'):
            case name.startsWith('errors/'):
                return PublicLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            // Fase 2, onda B (C-FORM): páginas públicas do formulário, com casca própria
            // (`PublicFormShell`). Sem esta regra, o `layout = (page) => page` da página é lido
            // pelo Inertia 3 como resolvedor de props e cai no AppLayout (topbar da conta).
            case name === 'public-forms/fill':
            case name === 'public-forms/confirm':
                return null;
            default:
                return AppLayout;
        }
    },
    strictMode: true,
    withApp(app) {
        return (
            <TooltipProvider delayDuration={0}>
                {app}
                <Toaster
                    position="bottom-right"
                    toastOptions={{
                        classNames: {
                            toast: 'rounded-[10px] border-border shadow-popover text-[13.5px]',
                        },
                    }}
                />
            </TooltipProvider>
        );
    },
    progress: {
        color: '#1257c9',
    },
});
