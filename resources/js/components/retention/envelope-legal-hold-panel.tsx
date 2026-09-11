import { ShieldCheck } from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { formatDate } from '@/lib/format';
import { LegalHoldList } from './legal-hold-list';
import { PlaceHoldDialog } from './place-hold-dialog';
import { PreservedBadge } from './preserved-badge';
import { ReleaseHoldDialog } from './release-hold-dialog';
import type { EnvelopeLegalHold, LegalHoldRow } from './types';

/**
 * Bloco "Preservação" do detalhe do documento — contrato para
 * `resources/js/pages/envelopes/show.tsx` (docs/fase-2/retencao-e-preservacao.md §10).
 *
 * Recebe a resposta de `GET envelopes.legal_hold.show` (ou a mesma estrutura
 * como prop do detalhe). Mostra o selo "Preservado", as preservações que cobrem
 * o documento, quando a política de retenção o alcança e as ações de preservar
 * e liberar conforme `can`. Sem a flag e sem preservação, não renderiza nada.
 */
export function EnvelopeLegalHoldPanel({
    legalHold,
}: {
    legalHold: EnvelopeLegalHold;
}) {
    const [placing, setPlacing] = useState(false);
    const [releasing, setReleasing] = useState<LegalHoldRow | null>(null);

    if (!legalHold.feature_enabled && !legalHold.preserved) {
        return null;
    }

    const first = legalHold.holds[0];
    const { retention } = legalHold;

    return (
        <section className="border-border bg-card shadow-card flex flex-col gap-3 rounded-xl border p-5">
            <Heading
                variant="small"
                title={
                    <span className="flex items-center gap-2">
                        <ShieldCheck aria-hidden className="size-4" />
                        Preservação
                        {legalHold.preserved && (
                            <PreservedBadge
                                since={first?.starts_at}
                                until={first?.ends_at}
                            />
                        )}
                    </span>
                }
                description={
                    legalHold.preserved
                        ? 'Este documento não pode ser excluído por ninguém — nem pela política de retenção, nem manualmente, nem pela exclusão da conta — até que todas as preservações abaixo sejam liberadas.'
                        : retention.policy_active && retention.eligible_at
                          ? `Pela política de retenção (${retention.category_label}), este documento pode ser apagado a partir de ${formatDate(retention.eligible_at)}. Preserve-o se ele precisar ser mantido.`
                          : 'Preservar impede qualquer exclusão deste documento até a liberação.'
                }
                action={
                    legalHold.can.place ? (
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            onClick={() => setPlacing(true)}
                        >
                            Preservar documento
                        </Button>
                    ) : undefined
                }
            />

            {legalHold.holds.length > 0 && (
                <LegalHoldList
                    holds={legalHold.holds}
                    onRelease={legalHold.can.release ? setReleasing : undefined}
                />
            )}

            <PlaceHoldDialog
                open={placing}
                onOpenChange={setPlacing}
                action={legalHold.endpoints.store}
            />
            <ReleaseHoldDialog
                hold={releasing}
                onClose={() => setReleasing(null)}
            />
        </section>
    );
}
