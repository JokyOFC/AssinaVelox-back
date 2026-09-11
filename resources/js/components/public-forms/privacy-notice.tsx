import { ChevronDown, ShieldCheck } from 'lucide-react';
import { useState } from 'react';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import type { PrivacyNoticeContent } from './types';

/**
 * Aviso de privacidade do formulário público: linha-resumo sempre visível e o
 * texto completo recolhível. Texto vindo do servidor (`PrivacyNotice`),
 * pendente de revisão jurídica.
 */
export function PrivacyNotice({ notice }: { notice: PrivacyNoticeContent }) {
    const [open, setOpen] = useState(false);

    return (
        <Collapsible
            open={open}
            onOpenChange={setOpen}
            className="border-border bg-muted/40 rounded-[10px] border p-4"
        >
            <div className="flex items-start gap-2.5">
                <ShieldCheck className="text-muted-foreground mt-0.5 size-4 shrink-0" />
                <div className="min-w-0 flex-1">
                    <p className="text-text-secondary text-[12.5px] leading-[1.5]">
                        {notice.summary}
                    </p>
                    <CollapsibleTrigger className="text-primary mt-1.5 inline-flex items-center gap-1 text-[12.5px] font-semibold">
                        {open ? 'Ocultar aviso completo' : 'Ver aviso completo'}
                        <ChevronDown
                            className={`size-3.5 transition-transform ${open ? 'rotate-180' : ''}`}
                        />
                    </CollapsibleTrigger>
                </div>
            </div>
            <CollapsibleContent className="mt-3 flex flex-col gap-3 pl-[26px]">
                {notice.sections.map((section) => (
                    <div key={section.title}>
                        <h3 className="text-[12.5px] font-semibold">
                            {section.title}
                        </h3>
                        <p className="text-text-secondary mt-0.5 text-[12.5px] leading-[1.5]">
                            {section.body}
                        </p>
                    </div>
                ))}
                <p className="text-muted-foreground text-[11px]">
                    Versão do aviso: {notice.version}
                </p>
            </CollapsibleContent>
        </Collapsible>
    );
}
