import { Form, Head, Link, usePage } from '@inertiajs/react';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import DeleteUser from '@/components/delete-user';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { edit as profileEdit } from '@/routes/profile';
import { send as sendVerification } from '@/routes/verification';

type Props = {
    mustVerifyEmail: boolean;
    status?: string | null;
};

/** Perfil e preferências (menu "Minha conta"). Layout de Configurações, seção "Minha conta › Perfil". */
export default function Profile({ mustVerifyEmail, status }: Props) {
    const { auth } = usePage().props;
    const user = auth.user;

    if (!user) {
        return null;
    }

    return (
        <>
            <Head title="Perfil" />

            <div className="border-border bg-card shadow-card flex flex-col gap-4 rounded-xl border p-5">
                <Heading
                    variant="small"
                    title="Dados pessoais"
                    description="Seu nome aparece nos convites enviados e na trilha de auditoria."
                />

                <Form
                    {...ProfileController.update.form()}
                    options={{ preserveScroll: true }}
                    className="flex flex-col gap-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <div
                                className="grid gap-3"
                                style={{
                                    gridTemplateColumns:
                                        'repeat(auto-fit, minmax(220px, 1fr))',
                                }}
                            >
                                <div className="grid gap-1.5">
                                    <Label htmlFor="name">Nome completo</Label>
                                    <Input
                                        id="name"
                                        defaultValue={user.name}
                                        name="name"
                                        required
                                        autoComplete="name"
                                        placeholder="Seu nome"
                                        aria-invalid={!!errors.name}
                                    />
                                    <InputError message={errors.name} />
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="email">E-mail</Label>
                                    <Input
                                        id="email"
                                        type="email"
                                        defaultValue={user.email}
                                        name="email"
                                        required
                                        autoComplete="username"
                                        placeholder="voce@empresa.com.br"
                                        aria-invalid={!!errors.email}
                                    />
                                    <InputError message={errors.email} />
                                </div>
                            </div>

                            {mustVerifyEmail &&
                                user.email_verified_at === null && (
                                    <div className="border-warning-border bg-warning-bg text-warning rounded-[10px] border p-3 text-[12.5px] leading-[1.5]">
                                        Seu e-mail ainda não foi verificado.{' '}
                                        <Link
                                            href={sendVerification()}
                                            as="button"
                                            className="font-semibold underline underline-offset-2"
                                        >
                                            Reenviar e-mail de verificação
                                        </Link>
                                        {status ===
                                            'verification-link-sent' && (
                                            <span className="text-success mt-1 block font-semibold">
                                                Um novo link de verificação foi
                                                enviado para o seu e-mail.
                                            </span>
                                        )}
                                    </div>
                                )}

                            <div className="flex items-center justify-between gap-3">
                                <span className="text-muted-foreground text-[12.5px]">
                                    Fuso horário: {user.timezone} · Idioma:
                                    Português (Brasil)
                                </span>
                                <Button
                                    type="submit"
                                    disabled={processing}
                                    data-test="update-profile-button"
                                >
                                    {processing && <Spinner />}
                                    Salvar alterações
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>

            <DeleteUser />
        </>
    );
}

Profile.layout = {
    breadcrumbs: [{ title: 'Perfil e preferências', href: profileEdit() }],
};
