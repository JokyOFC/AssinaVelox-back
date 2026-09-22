import { Head, Link, useForm } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { logout } from '@/routes';
import { start } from '@/routes/sso/required';

type Props = {
    organization: { name: string };
    connection: { protocol_label: string };
};

/**
 * Organização com login corporativo obrigatório (docs/fase-3/sso.md §5): quem entrou por senha
 * é trazido aqui e segue para o provedor de identidade da organização. A conta continua valendo
 * nas outras organizações.
 */
export default function SsoRequired({ organization, connection }: Props) {
    const form = useForm({});

    return (
        <>
            <Head title="Login corporativo obrigatório" />

            <p className="text-text-secondary text-[13.5px] leading-[1.6]">
                A organização <b>{organization.name}</b> exige que a equipe
                entre pelo login corporativo ({connection.protocol_label}).
                Continue pelo provedor de identidade da empresa para acessar os
                documentos dela.
            </p>

            <div className="flex flex-col gap-2">
                <Button
                    type="button"
                    size="lg"
                    className="w-full"
                    disabled={form.processing}
                    onClick={() => form.post(start.url())}
                >
                    {form.processing && <Spinner />}
                    Entrar com o login corporativo
                </Button>
                <InputError
                    message={(form.errors as Record<string, string>).sso}
                />
            </div>

            <p className="text-muted-foreground text-center text-[13px]">
                <Link
                    href={logout()}
                    as="button"
                    viewTransition
                    className="underline-offset-4 hover:underline"
                >
                    Sair
                </Link>
            </p>
        </>
    );
}

SsoRequired.layout = {
    title: 'Login corporativo obrigatório',
    description: 'Esta organização usa o provedor de identidade da empresa.',
};
