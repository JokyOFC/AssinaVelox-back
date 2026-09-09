import { useForm } from '@inertiajs/react';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { refuse as signRefuse } from '@/routes/sign';

/**
 * Recusa da assinatura (arquitetura §4.6; ROUTES §3.3): motivo **obrigatório**,
 * enviado ao remetente. Não é reversível pelo signatário — o texto do diálogo
 * diz isso antes de confirmar.
 */
export function RefusalDialog({
    token,
    open,
    onOpenChange,
    organizationName,
    minReason = 10,
    maxReason = 500,
}: {
    token: string;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    organizationName: string;
    /** `limits.refusal_reason` das props. */
    minReason?: number;
    maxReason?: number;
}) {
    const { data, setData, post, processing, errors, reset } = useForm({
        reason: '',
    });

    const tooShort = data.reason.trim().length < minReason;

    const submit = () => {
        post(signRefuse(token).url, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                reset();
                onOpenChange(false);
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-[440px]">
                <DialogHeader>
                    <DialogTitle>Recusar a assinatura?</DialogTitle>
                    <DialogDescription>
                        {organizationName} receberá o motivo informado. A recusa
                        encerra este documento e não pode ser desfeita por você.
                    </DialogDescription>
                </DialogHeader>

                <div className="grid gap-1.5">
                    <Label htmlFor="refusal-reason">
                        Motivo da recusa <span className="text-danger">*</span>
                    </Label>
                    <Textarea
                        id="refusal-reason"
                        rows={4}
                        value={data.reason}
                        maxLength={maxReason}
                        aria-invalid={Boolean(errors.reason)}
                        onChange={(event) =>
                            setData('reason', event.target.value)
                        }
                        placeholder="Ex.: o valor do aluguel está diferente do combinado."
                    />
                    <p className="text-muted-foreground flex justify-between text-[12px]">
                        <span>Mínimo de {minReason} caracteres.</span>
                        <span className="tabular">
                            {data.reason.length}/{maxReason}
                        </span>
                    </p>
                    {errors.reason && (
                        <p role="alert" className="text-danger text-[12.5px]">
                            {errors.reason}
                        </p>
                    )}
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                    >
                        Voltar ao documento
                    </Button>
                    <Button
                        type="button"
                        variant="destructive"
                        disabled={tooShort || processing}
                        onClick={submit}
                    >
                        {processing && <Spinner className="size-4" />}
                        Recusar assinatura
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
