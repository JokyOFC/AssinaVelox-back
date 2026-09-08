import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatCpfCnpj } from '@/lib/format';
import { cn } from '@/lib/utils';
import { login } from '@/routes';
import { privacy, terms } from '@/routes/legal';
import { store } from '@/routes/register';

type Props = {
    invitation?: { email: string; organization_name: string } | null;
};

/** Medidor de força local (ROUTES §2.2): comprimento ≥ 8, letras, números, símbolo. */
function passwordStrength(password: string): number {
    let score = 0;

    if (password.length >= 8) {
        score += 1;
    }

    if (/[a-zA-Z]/.test(password)) {
        score += 1;
    }

    if (/\d/.test(password)) {
        score += 1;
    }

    if (/[^a-zA-Z0-9]/.test(password)) {
        score += 1;
    }

    return score;
}

/** Criar conta (ROUTES §2.2; DESIGN §6.1 view=cadastro). */
export default function Register({ invitation = null }: Props) {
    const form = useForm({
        name: '',
        email: invitation?.email ?? '',
        organization_name: invitation?.organization_name ?? '',
        organization_tax_id: '',
        password: '',
        password_confirmation: '',
        terms: false as boolean,
    });

    const strength = passwordStrength(form.data.password);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(store.url(), {
            onFinish: () => form.reset('password', 'password_confirmation'),
        });
    };

    return (
        <>
            <Head title="Criar conta" />

            <form onSubmit={submit} className="flex flex-col gap-3.5">
                <div className="grid gap-1.5">
                    <Label htmlFor="name">Nome completo</Label>
                    <Input
                        id="name"
                        name="name"
                        required
                        autoFocus
                        autoComplete="name"
                        placeholder="Seu nome"
                        className="h-10"
                        value={form.data.name}
                        onChange={(e) => form.setData('name', e.target.value)}
                        aria-invalid={!!form.errors.name}
                    />
                    <InputError message={form.errors.name} />
                </div>

                <div className="grid gap-1.5">
                    <Label htmlFor="email">E-mail corporativo</Label>
                    <Input
                        id="email"
                        type="email"
                        name="email"
                        required
                        autoComplete="email"
                        placeholder="voce@empresa.com.br"
                        className="h-10"
                        value={form.data.email}
                        readOnly={!!invitation}
                        onChange={(e) => form.setData('email', e.target.value)}
                        aria-invalid={!!form.errors.email}
                    />
                    <InputError message={form.errors.email} />
                </div>

                {!invitation && (
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div className="grid gap-1.5">
                            <Label htmlFor="organization_name">Empresa</Label>
                            <Input
                                id="organization_name"
                                name="organization_name"
                                required
                                autoComplete="organization"
                                placeholder="Razão social"
                                className="h-10"
                                value={form.data.organization_name}
                                onChange={(e) =>
                                    form.setData(
                                        'organization_name',
                                        e.target.value,
                                    )
                                }
                                aria-invalid={!!form.errors.organization_name}
                            />
                            <InputError
                                message={form.errors.organization_name}
                            />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="organization_tax_id">
                                CNPJ ou CPF{' '}
                                <span className="text-muted-foreground font-normal">
                                    (opcional)
                                </span>
                            </Label>
                            <Input
                                id="organization_tax_id"
                                name="organization_tax_id"
                                inputMode="numeric"
                                placeholder="00.000.000/0000-00"
                                className="tabular h-10"
                                value={form.data.organization_tax_id}
                                onChange={(e) =>
                                    form.setData(
                                        'organization_tax_id',
                                        formatCpfCnpj(e.target.value),
                                    )
                                }
                                aria-invalid={!!form.errors.organization_tax_id}
                            />
                            <InputError
                                message={form.errors.organization_tax_id}
                            />
                        </div>
                    </div>
                )}

                <div className="grid gap-1.5">
                    <Label htmlFor="password">Senha</Label>
                    <PasswordInput
                        id="password"
                        name="password"
                        required
                        autoComplete="new-password"
                        placeholder="Mínimo de 8 caracteres"
                        className="h-10"
                        value={form.data.password}
                        onChange={(e) =>
                            form.setData('password', e.target.value)
                        }
                        aria-invalid={!!form.errors.password}
                    />
                    <div className="mt-0.5 flex gap-1" aria-hidden>
                        {[0, 1, 2, 3].map((index) => (
                            <span
                                key={index}
                                className={cn(
                                    'h-1 flex-1 rounded-full transition-colors',
                                    index < strength
                                        ? 'bg-success-solid'
                                        : 'bg-accent',
                                )}
                            />
                        ))}
                    </div>
                    <span className="text-muted-foreground text-[12px]">
                        Use letras, números e um símbolo.
                    </span>
                    <InputError message={form.errors.password} />
                </div>

                <div className="grid gap-1.5">
                    <Label htmlFor="password_confirmation">
                        Confirmar senha
                    </Label>
                    <PasswordInput
                        id="password_confirmation"
                        name="password_confirmation"
                        required
                        autoComplete="new-password"
                        placeholder="Repita a senha"
                        className="h-10"
                        value={form.data.password_confirmation}
                        onChange={(e) =>
                            form.setData(
                                'password_confirmation',
                                e.target.value,
                            )
                        }
                        aria-invalid={!!form.errors.password_confirmation}
                    />
                    <InputError message={form.errors.password_confirmation} />
                </div>

                <div className="grid gap-1.5">
                    <label className="text-text-secondary flex cursor-pointer items-start gap-2.5 text-[13px] leading-[1.5]">
                        <Checkbox
                            id="terms"
                            name="terms"
                            className="mt-0.5"
                            checked={form.data.terms}
                            onCheckedChange={(checked) =>
                                form.setData('terms', checked === true)
                            }
                            aria-invalid={!!form.errors.terms}
                        />
                        <span>
                            Li e aceito os{' '}
                            <Link
                                href={terms()}
                                className="text-primary font-semibold hover:underline"
                            >
                                Termos de uso
                            </Link>{' '}
                            e a{' '}
                            <Link
                                href={privacy()}
                                className="text-primary font-semibold hover:underline"
                            >
                                Política de Privacidade
                            </Link>
                            .
                        </span>
                    </label>
                    <InputError message={form.errors.terms} />
                </div>

                <Button
                    type="submit"
                    size="lg"
                    className="mt-1 w-full"
                    disabled={form.processing}
                    data-test="register-user-button"
                >
                    {form.processing && <Spinner />}
                    {invitation
                        ? 'Criar conta e entrar na organização'
                        : 'Criar conta grátis'}
                </Button>
            </form>

            <p className="text-text-secondary text-center text-[13.5px]">
                Já tem conta? <TextLink href={login()}>Entrar</TextLink>
            </p>
        </>
    );
}

Register.layout = {
    title: 'Criar conta',
    description: 'Grátis, sem cartão de crédito. Leva menos de 2 minutos.',
};
