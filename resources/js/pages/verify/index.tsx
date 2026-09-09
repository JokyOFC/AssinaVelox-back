import { Head, router } from '@inertiajs/react';
import { FileText, Mail, Printer, ShieldCheck } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import InputError from '@/components/input-error';
import {
    sanitizeVerificationCode,
    VerificationCodeInput,
} from '@/components/verification/verification-code';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { show as verifyShow } from '@/routes/verify';

export interface VerifyIndexProps {
    code?: string | null;
    error?: string | null;
}

/**
 * Verificação pública — formulário (ROUTES §2.19 e §4; arquitetura §6).
 *
 * Página leve e sem login: nenhuma requisição a terceiros, nenhum rastreador,
 * nenhum dado do documento antes de o código ser informado.
 */
export default function VerifyIndex({
    code = '',
    error = null,
}: VerifyIndexProps) {
    const [value, setValue] = useState(() =>
        sanitizeVerificationCode(code ?? ''),
    );
    const [localError, setLocalError] = useState<string | null>(null);

    const go = (candidate: string) => {
        if (candidate.length !== 12) {
            setLocalError(
                'Informe os 12 caracteres do código. O alfabeto não usa 0, 1, O nem I.',
            );

            return;
        }

        setLocalError(null);
        router.visit(verifyShow(candidate).url);
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        go(value);
    };

    const message = localError ?? error ?? undefined;

    return (
        <>
            <Head title="Verificar documento" />

            <div className="border-border bg-card shadow-card mx-auto flex w-full max-w-[560px] flex-col gap-5 rounded-xl border p-6 md:p-8">
                <span className="bg-primary-soft text-primary flex size-[52px] items-center justify-center rounded-[14px]">
                    <ShieldCheck className="size-6" />
                </span>

                <div>
                    <h1 className="text-[26px] leading-[1.15] font-bold tracking-[-.01em]">
                        Verificar documento
                    </h1>
                    <p className="text-text-secondary mt-2 text-[14px] leading-[1.55]">
                        Informe o código de verificação impresso no rodapé do
                        documento. Você verá o estado do documento, as datas, os
                        participantes com o nome abreviado e os resumos SHA-256
                        para conferir o arquivo — sem expor e-mails, IPs nem o
                        conteúdo do documento.
                    </p>
                </div>

                <form onSubmit={submit} className="flex flex-col gap-4">
                    <div className="grid gap-2">
                        <Label htmlFor="verification-code">
                            Código de verificação
                        </Label>
                        <VerificationCodeInput
                            value={value}
                            onChange={(next) => {
                                setValue(next);
                                setLocalError(null);
                            }}
                            onComplete={go}
                            invalid={Boolean(message)}
                            describedBy="verification-code-help"
                            autoFocus
                        />
                        <p
                            id="verification-code-help"
                            className="text-muted-foreground text-[12px]"
                        >
                            12 caracteres, em três blocos de quatro. Pode colar
                            o código inteiro em qualquer bloco.
                        </p>
                        <InputError message={message} />
                    </div>

                    <Button
                        type="submit"
                        size="lg"
                        disabled={value.length !== 12}
                    >
                        Verificar
                    </Button>
                </form>

                <div className="border-border rounded-[10px] border p-4">
                    <h2 className="text-[13.5px] font-semibold">
                        Onde encontrar o código
                    </h2>
                    <ul className="text-text-secondary mt-2.5 flex flex-col gap-2.5 text-[12.5px] leading-[1.5]">
                        <li className="flex items-start gap-2.5">
                            <Printer className="text-muted-foreground mt-0.5 size-4 shrink-0" />
                            <span>
                                No <b>rodapé de cada página</b> do PDF
                                concluído: “Verifique em
                                assinavelox.com.br/verificar · código
                                XXXX-XXXX-XXXX”.
                            </span>
                        </li>
                        <li className="flex items-start gap-2.5">
                            <FileText className="text-muted-foreground mt-0.5 size-4 shrink-0" />
                            <span>
                                No <b>relatório de evidências</b> anexado ao
                                final do documento, junto do QR de verificação.
                            </span>
                        </li>
                        <li className="flex items-start gap-2.5">
                            <Mail className="text-muted-foreground mt-0.5 size-4 shrink-0" />
                            <span>
                                No <b>e-mail de conclusão</b> enviado a quem
                                participou, e no comprovante mostrado ao
                                assinar.
                            </span>
                        </li>
                    </ul>
                </div>

                <p className="text-muted-foreground text-[12px] leading-[1.5]">
                    A verificação compara o código com o registro do documento
                    na AssinaVelox e informa o que foi registrado. Ela não é um
                    certificado emitido por autoridade certificadora, não julga
                    a validade jurídica do ato e não garante a aceitação do
                    documento por terceiros.
                </p>
            </div>
        </>
    );
}
