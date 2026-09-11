import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
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
import { Spinner } from '@/components/ui/spinner';
import { FolderAccessPicker } from './folder-access-picker';
import type { FolderGrant, FolderOption } from './types';

/**
 * Diálogo "Pastas com acesso" para uma função, um time ou uma pessoa. Envia a
 * lista completa (PUT): o que for desmarcado deixa de valer na hora.
 */
export function FolderAccessDialog({
    open,
    onOpenChange,
    title,
    description,
    url,
    folders,
    initial,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    description: string;
    url: string;
    folders: FolderOption[];
    initial: FolderGrant[];
}) {
    const [value, setValue] = useState<FolderGrant[]>(initial);
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string | undefined>();

    useEffect(() => {
        if (open) {
            setValue(initial);
            setError(undefined);
        }
    }, [open, initial]);

    const submit = () => {
        setProcessing(true);
        router.put(
            url,
            {
                folders: value.map(({ folder, level }) => ({ folder, level })),
            },
            {
                preserveScroll: true,
                onSuccess: () => onOpenChange(false),
                onError: (errors) =>
                    setError(
                        Object.values(errors).filter(Boolean).join(' ') ||
                            undefined,
                    ),
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-[520px]">
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>{description}</DialogDescription>
                </DialogHeader>
                <FolderAccessPicker
                    folders={folders}
                    value={value}
                    onChange={setValue}
                    disabled={processing}
                />
                <InputError message={error} />
                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                        disabled={processing}
                    >
                        Cancelar
                    </Button>
                    <Button
                        type="button"
                        onClick={submit}
                        disabled={processing}
                    >
                        {processing && <Spinner />}
                        Salvar acesso
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
