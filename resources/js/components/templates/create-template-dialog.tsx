import { useForm } from '@inertiajs/react';
import {
    FileText,
    FileType2,
    LayoutTemplate,
    TriangleAlert,
} from 'lucide-react';
import { useEffect, type FormEvent } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { formatBytes } from '@/lib/format';
import { cn } from '@/lib/utils';
import { store } from '@/routes/templates';
import type { ConversionInfo, TemplateSourceType } from './types';

const SOURCES: {
    value: TemplateSourceType;
    title: string;
    description: string;
    icon: typeof FileText;
}[] = [
    {
        value: 'pdf',
        title: 'PDF fixo',
        description:
            'Um PDF que não muda. Você posiciona os campos de cada participante uma vez.',
        icon: FileText,
    },
    {
        value: 'docx',
        title: 'Word (DOCX)',
        description:
            'Arquivo do Word com variáveis no formato ${nome_da_variavel}.',
        icon: FileType2,
    },
    {
        value: 'html',
        title: 'Texto (HTML)',
        description:
            'Escreva o texto aqui mesmo, com variáveis no formato {{nome_da_variavel}}.',
        icon: LayoutTemplate,
    },
];

/** "Novo modelo" (mock App - Modelos): nome, categoria e fonte do conteúdo. */
export function CreateTemplateDialog({
    open,
    onOpenChange,
    categories,
    initialSource = 'pdf',
    maxUploadBytes,
    conversion,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    categories: string[];
    initialSource?: TemplateSourceType;
    maxUploadBytes: number;
    conversion: ConversionInfo;
}) {
    const form = useForm<{
        name: string;
        category: string;
        description: string;
        source_type: TemplateSourceType;
        file: File | null;
    }>({
        name: '',
        category: '',
        description: '',
        source_type: initialSource,
        file: null,
    });

    useEffect(() => {
        if (open) {
            form.reset();
            form.setData('source_type', initialSource);
            form.clearErrors();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, initialSource]);

    const needsFile = form.data.source_type !== 'html';
    const accept =
        form.data.source_type === 'pdf'
            ? 'application/pdf,.pdf'
            : '.docx,application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(store.url(), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-[600px]">
                <DialogHeader>
                    <DialogTitle>Novo modelo</DialogTitle>
                    <DialogDescription>
                        Defina variáveis, participantes e campos uma vez e gere
                        documentos preenchendo um formulário.
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <div
                        role="radiogroup"
                        aria-label="Tipo de modelo"
                        className="grid gap-2 sm:grid-cols-3"
                    >
                        {SOURCES.map((source) => {
                            const selected =
                                form.data.source_type === source.value;
                            const Icon = source.icon;

                            return (
                                <button
                                    key={source.value}
                                    type="button"
                                    role="radio"
                                    aria-checked={selected}
                                    onClick={() => {
                                        form.setData((data) => ({
                                            ...data,
                                            source_type: source.value,
                                            file: null,
                                        }));
                                    }}
                                    className={cn(
                                        'flex flex-col gap-1.5 rounded-lg border p-3 text-left transition-colors',
                                        selected
                                            ? 'border-primary bg-primary-soft'
                                            : 'border-border hover:bg-accent-subtle bg-white',
                                    )}
                                >
                                    <Icon
                                        className={cn(
                                            'size-4',
                                            selected
                                                ? 'text-primary'
                                                : 'text-muted-foreground',
                                        )}
                                    />
                                    <span className="text-foreground text-[13px] font-semibold">
                                        {source.title}
                                    </span>
                                    <span className="text-muted-foreground text-[11.5px] leading-snug">
                                        {source.description}
                                    </span>
                                </button>
                            );
                        })}
                    </div>
                    <InputError message={form.errors.source_type} />

                    {form.data.source_type === 'docx' &&
                        !conversion.available && (
                            <div className="border-warning-border bg-warning-bg text-warning flex gap-2 rounded-lg border p-3 text-[12.5px]">
                                <TriangleAlert className="mt-0.5 size-4 shrink-0" />
                                <span>{conversion.message}</span>
                            </div>
                        )}

                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="grid gap-1.5">
                            <Label htmlFor="template-name">Nome</Label>
                            <Input
                                id="template-name"
                                value={form.data.name}
                                onChange={(e) =>
                                    form.setData('name', e.target.value)
                                }
                                placeholder="Ex.: Contrato de locação residencial"
                                maxLength={160}
                                required
                                autoFocus
                            />
                            <InputError message={form.errors.name} />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="template-category">
                                Categoria (opcional)
                            </Label>
                            <Input
                                id="template-category"
                                list="template-categories"
                                value={form.data.category}
                                onChange={(e) =>
                                    form.setData('category', e.target.value)
                                }
                                placeholder="Ex.: Locação"
                                maxLength={60}
                            />
                            <datalist id="template-categories">
                                {categories.map((category) => (
                                    <option key={category} value={category} />
                                ))}
                            </datalist>
                            <InputError message={form.errors.category} />
                        </div>
                    </div>

                    <div className="grid gap-1.5">
                        <Label htmlFor="template-description">
                            Descrição (opcional)
                        </Label>
                        <Textarea
                            id="template-description"
                            rows={2}
                            value={form.data.description}
                            onChange={(e) =>
                                form.setData('description', e.target.value)
                            }
                            maxLength={500}
                            placeholder="Quando usar este modelo"
                        />
                        <InputError message={form.errors.description} />
                    </div>

                    {needsFile && (
                        <div className="grid gap-1.5">
                            <Label htmlFor="template-file">
                                Arquivo{' '}
                                {form.data.source_type === 'pdf'
                                    ? '(.pdf)'
                                    : '(.docx)'}
                            </Label>
                            <Input
                                id="template-file"
                                type="file"
                                accept={accept}
                                onChange={(e) =>
                                    form.setData(
                                        'file',
                                        e.target.files?.[0] ?? null,
                                    )
                                }
                                required
                            />
                            <p className="text-muted-foreground text-[12px]">
                                Até {formatBytes(maxUploadBytes)}. O arquivo é
                                inspecionado antes de ser aceito: DOCX com
                                macros, objetos incorporados ou conteúdo externo
                                e PDF protegido ou já assinado são recusados.
                            </p>
                            <InputError message={form.errors.file} />
                        </div>
                    )}

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => onOpenChange(false)}
                        >
                            Cancelar
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <Spinner />}
                            Criar modelo
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
