import { Form } from '@inertiajs/react';
import { useRef } from 'react';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

/** Zona de perigo do perfil: excluir a própria conta de usuário. */
export default function DeleteUser() {
    const passwordInput = useRef<HTMLInputElement>(null);

    return (
        <div className="flex flex-wrap items-center justify-between gap-4 rounded-xl border border-danger-border bg-card p-5 shadow-card">
            <div className="min-w-0">
                <h2 className="text-[15px] font-semibold text-danger">Excluir minha conta</h2>
                <p className="mt-1 text-[13px] leading-[1.5] text-muted-foreground">
                    Remove seu usuário e o acesso a todas as organizações. Organizações das quais você é o único
                    proprietário precisam ser transferidas ou excluídas antes.
                </p>
            </div>

            <Dialog>
                <DialogTrigger asChild>
                    <Button variant="destructive" size="sm" data-test="delete-user-button">
                        Excluir conta
                    </Button>
                </DialogTrigger>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Excluir sua conta?</DialogTitle>
                        <DialogDescription>
                            Esta ação é permanente. Confirme sua senha para excluir a conta e todos os dados pessoais
                            associados.
                        </DialogDescription>
                    </DialogHeader>

                    <Form {...ProfileController.destroy.form()} options={{ preserveScroll: true }} onError={() => passwordInput.current?.focus()} resetOnSuccess className="flex flex-col gap-4">
                        {({ resetAndClearErrors, processing, errors }) => (
                            <>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="password">Senha</Label>
                                    <PasswordInput id="password" name="password" ref={passwordInput} placeholder="Sua senha" autoComplete="current-password" aria-invalid={!!errors.password} />
                                    <InputError message={errors.password} />
                                </div>

                                <DialogFooter>
                                    <DialogClose asChild>
                                        <Button type="button" variant="outline" onClick={() => resetAndClearErrors()}>
                                            Cancelar
                                        </Button>
                                    </DialogClose>
                                    <Button type="submit" variant="destructive" disabled={processing} data-test="confirm-delete-user-button">
                                        {processing && <Spinner />}
                                        Excluir conta
                                    </Button>
                                </DialogFooter>
                            </>
                        )}
                    </Form>
                </DialogContent>
            </Dialog>
        </div>
    );
}
