import { Head, useForm } from '@inertiajs/react';
import { Building2 } from 'lucide-react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatCpfCnpj } from '@/lib/format';
import { dashboard } from '@/routes';
import { store as storeOrganization } from '@/routes/organizations';

/** Criar organização (ROUTES §1.2 `organizations.store`) — página completa, além do dialog do switcher. */
export default function OrganizationsCreate() {
    const form = useForm({ name: '', legal_name: '', tax_id: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(storeOrganization.url());
    };

    return (
        <>
            <Head title="Criar organização" />
            <PageHeader
                title="Criar nova organização"
                subtitle="Você será o proprietário. A organização começa no plano Grátis e pode ser alterada depois."
            />
            <form
                onSubmit={submit}
                className="flex max-w-[560px] flex-col gap-4 rounded-xl border border-border bg-card p-5 shadow-card"
            >
                <div className="flex items-center gap-3">
                    <span className="flex size-11 items-center justify-center rounded-xl bg-primary-soft text-primary">
                        <Building2 className="size-5" />
                    </span>
                    <div>
                        <div className="text-[15px] font-semibold">Dados da organização</div>
                        <div className="text-[13px] text-muted-foreground">
                            Aparecem nos convites e no certificado de conclusão.
                        </div>
                    </div>
                </div>
                <div className="grid gap-1.5">
                    <Label htmlFor="name">Nome de exibição</Label>
                    <Input
                        id="name"
                        value={form.data.name}
                        onChange={(e) => form.setData('name', e.target.value)}
                        placeholder="Ex.: Imobiliária Horizonte"
                        required
                        autoFocus
                        aria-invalid={!!form.errors.name}
                    />
                    <InputError message={form.errors.name} />
                </div>
                <div className="grid gap-3 sm:grid-cols-2">
                    <div className="grid gap-1.5">
                        <Label htmlFor="legal_name">
                            Razão social <span className="font-normal text-muted-foreground">(opcional)</span>
                        </Label>
                        <Input
                            id="legal_name"
                            value={form.data.legal_name}
                            onChange={(e) => form.setData('legal_name', e.target.value)}
                            placeholder="Horizonte Negócios Imobiliários Ltda."
                            aria-invalid={!!form.errors.legal_name}
                        />
                        <InputError message={form.errors.legal_name} />
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="tax_id">
                            CNPJ ou CPF <span className="font-normal text-muted-foreground">(opcional)</span>
                        </Label>
                        <Input
                            id="tax_id"
                            inputMode="numeric"
                            value={form.data.tax_id}
                            onChange={(e) => form.setData('tax_id', formatCpfCnpj(e.target.value))}
                            placeholder="00.000.000/0000-00"
                            className="tabular"
                            aria-invalid={!!form.errors.tax_id}
                        />
                        <InputError message={form.errors.tax_id} />
                    </div>
                </div>
                <div className="flex justify-end gap-2 pt-1">
                    <Button type="button" variant="outline" onClick={() => window.history.back()}>
                        Cancelar
                    </Button>
                    <Button type="submit" disabled={form.processing || !form.data.name.trim()}>
                        {form.processing && <Spinner />}
                        Criar organização
                    </Button>
                </div>
            </form>
        </>
    );
}

OrganizationsCreate.layout = {
    breadcrumbs: [{ title: 'Nova organização', href: dashboard() }],
};
