import { Head, router } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatVerificationCode } from '@/lib/format';
import { show as verifyShow } from '@/routes/verify';

export interface VerifyIndexProps {
    code?: string | null;
    error?: string | null;
}

const CODE_PATTERN = /^[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/;

/** Verificação pública — formulário (ROUTES §2.19; arquitetura §6). */
export default function VerifyIndex({ code = '', error = null }: VerifyIndexProps) {
    const [value, setValue] = useState(formatVerificationCode(code ?? '') === '—' ? '' : formatVerificationCode(code ?? ''));
    const [localError, setLocalError] = useState<string | null>(null);
    const clean = value.replace(/[^A-Z0-9]/gi, '').toUpperCase();

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const formatted = formatVerificationCode(clean);

        if (!CODE_PATTERN.test(formatted)) {
            setLocalError('Informe os 12 caracteres do código, no formato XXXX-XXXX-XXXX.');

            return;
        }

        setLocalError(null);
        router.visit(verifyShow(clean).url);
    };

    return (
        <>
            <Head title="Verificar documento" />
            <div className="mx-auto flex w-full max-w-[520px] flex-col gap-5 rounded-xl border border-border bg-card p-6 shadow-card md:p-8">
                <span className="flex size-[52px] items-center justify-center rounded-[14px] bg-primary-soft text-primary">
                    <ShieldCheck className="size-6" />
                </span>
                <div>
                    <h1 className="text-[26px] font-bold tracking-[-.01em]">Verificar documento</h1>
                    <p className="mt-2 text-[14px] leading-[1.55] text-text-secondary">
                        Informe o código de verificação impresso no rodapé do PDF assinado. Você verá o estado do
                        documento, a data de conclusão e os hashes para conferência — sem expor dados pessoais.
                    </p>
                </div>
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <div className="grid gap-1.5">
                        <Label htmlFor="code">Código de verificação</Label>
                        <Input
                            id="code"
                            value={value}
                            onChange={(e) => setValue(formatVerificationCode(e.target.value.replace(/[^A-Z0-9]/gi, '').slice(0, 12)))}
                            placeholder="XXXX-XXXX-XXXX"
                            autoFocus
                            autoComplete="off"
                            spellCheck={false}
                            className="h-12 text-center font-mono text-[20px] font-bold tracking-[.12em] uppercase tabular"
                            aria-invalid={!!(localError || error)}
                        />
                        <InputError message={localError ?? error ?? undefined} />
                    </div>
                    <Button type="submit" size="lg" disabled={clean.length !== 12}>
                        Verificar
                    </Button>
                </form>
                <p className="text-[12px] leading-[1.5] text-muted-foreground">
                    A verificação compara o código com o registro do documento na AssinaVelox. Para conferir um arquivo
                    local, calcule o SHA-256 e compare com os hashes exibidos no resultado.
                </p>
            </div>
        </>
    );
}
