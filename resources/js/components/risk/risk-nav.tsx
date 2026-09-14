import { Link } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import {
    index as adminRiskIndex,
    precision as adminRiskPrecision,
} from '@/routes/admin/risk';

/**
 * Abas do painel interno › Antifraude. O item de menu lateral depende de
 * `app-sidebar.tsx` (fora da área P3-RISK); até lá, estas abas ligam as telas.
 */
export function RiskNav({ current }: { current: 'queue' | 'precision' }) {
    const items = [
        { key: 'queue', label: 'Fila de revisão', href: adminRiskIndex() },
        {
            key: 'precision',
            label: 'Precisão por regra',
            href: adminRiskPrecision(),
        },
    ] as const;

    return (
        <nav
            aria-label="Antifraude"
            className="border-border mb-4 flex gap-1 border-b"
        >
            {items.map((item) => (
                <Link
                    key={item.key}
                    href={item.href}
                    aria-current={current === item.key ? 'page' : undefined}
                    className={cn(
                        '-mb-px border-b-2 px-3 py-2 text-[13.5px] font-medium',
                        current === item.key
                            ? 'border-primary text-foreground'
                            : 'text-muted-foreground hover:text-foreground border-transparent',
                    )}
                >
                    {item.label}
                </Link>
            ))}
        </nav>
    );
}
