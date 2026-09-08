import { Toaster as Sonner, type ToasterProps } from 'sonner';

/** Toaster (DESIGN §4.15): tema claro, fundo branco, borda e sombra de popover. */
function Toaster({ ...props }: ToasterProps) {
    return (
        <Sonner
            theme="light"
            className="toaster group"
            position="bottom-right"
            // Sem isto o sonner anuncia a live region como "Notifications" (inglês).
            containerAriaLabel="Notificações"
            style={
                {
                    '--normal-bg': 'var(--popover)',
                    '--normal-text': 'var(--popover-foreground)',
                    '--normal-border': 'var(--border)',
                    '--success-bg': 'var(--success-bg)',
                    '--success-text': 'var(--success)',
                    '--success-border': 'var(--success-border)',
                    '--error-bg': 'var(--danger-bg)',
                    '--error-text': 'var(--danger)',
                    '--error-border': 'var(--danger-border)',
                    '--warning-bg': 'var(--warning-bg)',
                    '--warning-text': 'var(--warning)',
                    '--warning-border': 'var(--warning-border)',
                    '--info-bg': 'var(--info-bg)',
                    '--info-text': 'var(--info)',
                    '--info-border': 'var(--info-border)',
                } as React.CSSProperties
            }
            {...props}
        />
    );
}

export { Toaster };
