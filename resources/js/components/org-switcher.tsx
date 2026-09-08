import { router, useForm, usePage } from '@inertiajs/react';
import { Check, ChevronsUpDown, Plus } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { AvatarInitials } from '@/components/avatar-initials';
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
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import {
    store as storeOrganization,
    switchMethod as switchOrganization,
} from '@/routes/organizations';

/**
 * Switcher de organização (DESIGN §3.1 item 2; ROUTES §5.3): botão branco
 * com avatar navy + nome + plano; DropdownMenu com as organizações do usuário
 * (check na ativa) e rodapé "Criar nova organização" → Dialog → POST organizations.store.
 */
export function OrgSwitcher() {
    const { organization, organizations } = usePage().props;
    const [createOpen, setCreateOpen] = useState(false);

    if (!organization) {
        return null;
    }

    const handleSwitch = (id: string) => {
        if (id === organization.id) {
            return;
        }

        router.post(switchOrganization(id).url, {}, { preserveScroll: false });
    };

    return (
        <>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <button
                        type="button"
                        className="border-border hover:bg-accent-subtle focus-visible:ring-primary/18 flex w-full items-center gap-2.5 rounded-lg border bg-white px-2 py-[7px] text-left transition-colors focus-visible:ring-[3px] focus-visible:outline-none"
                    >
                        <AvatarInitials
                            initials={organization.initials}
                            tone="organization"
                            size="sm"
                            className="text-[12px]"
                        />
                        <span className="min-w-0 flex-1">
                            <span className="text-foreground block truncate text-[13px] font-semibold">
                                {organization.name}
                            </span>
                            <span className="text-muted-foreground block truncate text-[11.5px]">
                                Plano {organization.plan.name}
                            </span>
                        </span>
                        <ChevronsUpDown className="text-muted-foreground size-3.5 shrink-0" />
                    </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    align="start"
                    sideOffset={6}
                    className="shadow-popover w-(--radix-dropdown-menu-trigger-width) min-w-[232px] rounded-[10px] p-1.5"
                >
                    <DropdownMenuLabel className="text-muted-foreground px-2.5 pt-2 pb-1.5 text-[11px] font-bold tracking-[.12em] uppercase">
                        Organizações
                    </DropdownMenuLabel>
                    {organizations.map((org, index) => (
                        <DropdownMenuItem
                            key={org.id}
                            onSelect={() => handleSwitch(org.id)}
                            className="focus:bg-accent-subtle gap-2.5 rounded-md px-2.5 py-2 text-[13.5px]"
                        >
                            <AvatarInitials
                                initials={org.initials}
                                index={index}
                                tone={
                                    org.is_current ? 'organization' : 'palette'
                                }
                                size="sm"
                                className="size-7 text-[11px]"
                            />
                            <span className="min-w-0 flex-1">
                                <span className="text-foreground block truncate font-semibold">
                                    {org.name}
                                </span>
                                <span className="text-muted-foreground block truncate text-[11.5px]">
                                    {org.plan_name}
                                </span>
                            </span>
                            {org.is_current && (
                                <Check className="text-primary size-4" />
                            )}
                        </DropdownMenuItem>
                    ))}
                    <DropdownMenuSeparator />
                    <DropdownMenuItem
                        onSelect={() => setCreateOpen(true)}
                        className="text-text-secondary focus:bg-accent-subtle focus:text-foreground gap-2.5 rounded-md px-2.5 py-2 text-[13.5px] font-medium"
                    >
                        <Plus className="size-4" />
                        Criar nova organização
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>

            <CreateOrganizationDialog
                open={createOpen}
                onOpenChange={setCreateOpen}
            />
        </>
    );
}

export function CreateOrganizationDialog({
    open,
    onOpenChange,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const form = useForm({ name: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(storeOrganization.url(), {
            onSuccess: () => {
                form.reset();
                onOpenChange(false);
            },
        });
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (!next) {
                    form.reset();
                    form.clearErrors();
                }

                onOpenChange(next);
            }}
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Criar nova organização</DialogTitle>
                    <DialogDescription>
                        Você será o proprietário da nova organização. Ela começa
                        no plano Grátis e pode ser alterada depois.
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <div className="grid gap-1.5">
                        <Label htmlFor="new-org-name">
                            Nome da organização
                        </Label>
                        <Input
                            id="new-org-name"
                            name="name"
                            value={form.data.name}
                            onChange={(e) =>
                                form.setData('name', e.target.value)
                            }
                            placeholder="Ex.: Imobiliária Horizonte"
                            autoFocus
                            required
                            minLength={2}
                            maxLength={120}
                            aria-invalid={!!form.errors.name}
                        />
                        <InputError message={form.errors.name} />
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => onOpenChange(false)}
                            disabled={form.processing}
                        >
                            Cancelar
                        </Button>
                        <Button
                            type="submit"
                            disabled={form.processing || !form.data.name.trim()}
                        >
                            {form.processing && <Spinner />}
                            Criar organização
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
