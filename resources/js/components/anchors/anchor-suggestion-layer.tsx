import type { AnchorSuggestions } from '@/components/anchors/use-anchor-suggestions';
import type { RenderedPageSize } from '@/lib/pdf';
import { cn } from '@/lib/utils';

/**
 * Caixas tracejadas dos campos SUGERIDOS na página aberta (Fase 3 §3.2). Não
 * são campos: ficam por cima da camada de campos só como indicação, e um
 * clique leva à sugestão no painel "Detectar campos", onde o remetente
 * confirma ou descarta. Todo texto é renderizado como texto (nunca HTML).
 */
export function AnchorSuggestionLayer({
    anchors,
    documentId,
    firstDocumentId,
    page,
    size,
}: {
    anchors: AnchorSuggestions;
    documentId: string;
    firstDocumentId: string;
    page: number;
    size: RenderedPageSize;
}) {
    if (!anchors.enabled || !anchors.state) {
        return null;
    }

    const items = anchors.state.suggestions.filter(
        (suggestion) =>
            (suggestion.document_id ?? firstDocumentId) === documentId &&
            suggestion.page === page,
    );

    if (items.length === 0) {
        return null;
    }

    return (
        <div className="pointer-events-none absolute inset-0 z-20">
            {items.map((suggestion) => {
                const selected = anchors.selectedId === suggestion.id;

                return (
                    <button
                        key={suggestion.id}
                        type="button"
                        onClick={() => anchors.select(suggestion.id)}
                        aria-label={`Campo sugerido: ${suggestion.type_label}, página ${suggestion.page}. Revise no painel Detectar campos.`}
                        title="Campo sugerido — confirme ou descarte no painel Detectar campos"
                        className={cn(
                            'border-warning bg-warning-bg/45 pointer-events-auto absolute rounded-[4px] border-2 border-dashed',
                            selected && 'ring-warning ring-2 ring-offset-1',
                        )}
                        style={{
                            left: suggestion.x * size.width,
                            top: suggestion.y * size.height,
                            width: suggestion.w * size.width,
                            height: suggestion.h * size.height,
                        }}
                    >
                        <span className="bg-warning absolute -top-[18px] left-0 rounded-[4px] px-1.5 py-px text-[10.5px] leading-[15px] font-semibold whitespace-nowrap text-white">
                            Sugerido · {suggestion.type_label}
                            {suggestion.via === 'ocr' ? ' · OCR' : ''}
                        </span>
                    </button>
                );
            })}
        </div>
    );
}
