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
import { useI18n } from '@/i18n';
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
    noun = 'assinatura',
}: {
    token: string;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    organizationName: string;
    /** `limits.refusal_reason` das props. */
    minReason?: number;
    maxReason?: number;
    /** O que se recusa (Fase 2 §2.4): "assinatura" (padrão) ou "aprovação". */
    noun?: 'assinatura' | 'aprovação';
}) {
    const { t } = useI18n();
    const approval = noun === 'aprovação';
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
                    <DialogTitle>
                        {approval
                            ? t('refusal.title_approval')
                            : t('refusal.title_signature')}
                    </DialogTitle>
                    <DialogDescription>
                        {t('refusal.description', {
                            organization: organizationName,
                        })}
                    </DialogDescription>
                </DialogHeader>

                <div className="grid gap-1.5">
                    <Label htmlFor="refusal-reason">
                        {t('refusal.reason_label')}{' '}
                        <span className="text-danger">*</span>
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
                        placeholder={t('refusal.placeholder')}
                    />
                    <p className="text-muted-foreground flex justify-between text-[12px]">
                        <span>{t('refusal.min', { min: minReason })}</span>
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
                        {t('common.back_to_document')}
                    </Button>
                    <Button
                        type="button"
                        variant="destructive"
                        disabled={tooShort || processing}
                        onClick={submit}
                    >
                        {processing && <Spinner className="size-4" />}
                        {approval
                            ? t('refusal.submit_approval')
                            : t('refusal.submit_signature')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
