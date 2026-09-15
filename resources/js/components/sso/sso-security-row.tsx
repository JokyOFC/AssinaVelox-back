import { Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { sso as settingsSso } from '@/routes/settings';

/**
 * Linha "Login único (SSO / SAML)" de Configurações › Geral quando `features.sso_oidc` ou
 * `features.sso_saml` está ligada: leva à tela de configuração (docs/fase-3/sso.md §10).
 * Com as flags desligadas, a página continua mostrando a chave desabilitada da Fase 1.
 */
export function SsoSecurityRow() {
    return (
        <div className="border-muted flex items-center justify-between gap-4 border-t py-3 first:border-t-0">
            <div className="min-w-0">
                <div className="text-[13.5px] font-semibold">
                    Login único (SSO / SAML)
                </div>
                <div className="text-muted-foreground mt-0.5 text-[12.5px]">
                    Sua equipe entra pelo provedor de identidade da empresa
                    (OIDC ou SAML 2.0)
                </div>
            </div>
            <Button asChild type="button" variant="outline" size="xs">
                <Link href={settingsSso()}>Configurar</Link>
            </Button>
        </div>
    );
}
