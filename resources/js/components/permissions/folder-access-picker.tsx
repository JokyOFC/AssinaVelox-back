import { Check, Folder as FolderIcon } from 'lucide-react';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';
import {
    accessLevelDescriptions,
    accessLevelLabels,
    type AccessLevel,
    type FolderGrant,
    type FolderOption,
} from './types';

/**
 * Seleção de "Pastas com acesso".
 * - `chips` (convite, fiel ao mock): pastas como pílulas; nível "Visualizar".
 * - `list` (diálogos): marcação + nível por pasta.
 * `allFolders` mostra a pílula travada "Todas as pastas" (a função já vê tudo).
 */
export function FolderAccessPicker({
    folders,
    value,
    onChange,
    variant = 'list',
    allFolders = false,
    disabled = false,
}: {
    folders: FolderOption[];
    value: FolderGrant[];
    onChange: (next: FolderGrant[]) => void;
    variant?: 'chips' | 'list';
    allFolders?: boolean;
    disabled?: boolean;
}) {
    const selected = new Map(value.map((grant) => [grant.folder, grant]));

    const toggle = (folder: FolderOption, on: boolean) => {
        if (on) {
            onChange([
                ...value.filter((g) => g.folder !== folder.id),
                { folder: folder.id, name: folder.name, level: 'view' },
            ]);
        } else {
            onChange(value.filter((g) => g.folder !== folder.id));
        }
    };

    const setLevel = (folderId: string, level: AccessLevel) => {
        onChange(
            value.map((g) => (g.folder === folderId ? { ...g, level } : g)),
        );
    };

    if (allFolders) {
        return (
            <div className="flex flex-col gap-1.5">
                <div className="flex flex-wrap gap-1.5">
                    <span className="bg-primary-soft text-primary inline-flex h-7 items-center gap-1 rounded-full px-2.5 text-[12.5px] font-semibold">
                        <Check className="size-3.5" />
                        Todas as pastas
                    </span>
                </div>
                <span className="text-muted-foreground text-[12px]">
                    Esta função já vê todos os documentos da conta.
                </span>
            </div>
        );
    }

    if (folders.length === 0) {
        return (
            <p className="text-muted-foreground text-[12.5px]">
                Nenhuma pasta criada ainda. Crie pastas em Documentos para
                liberar o acesso por pasta.
            </p>
        );
    }

    if (variant === 'chips') {
        return (
            <div className="flex flex-col gap-1.5">
                <div className="flex flex-wrap gap-1.5">
                    {folders.map((folder) => {
                        const on = selected.has(folder.id);

                        return (
                            <button
                                key={folder.id}
                                type="button"
                                disabled={disabled}
                                aria-pressed={on}
                                onClick={() => toggle(folder, !on)}
                                className={cn(
                                    'inline-flex h-7 items-center gap-1 rounded-full px-2.5 text-[12.5px] font-semibold transition-colors disabled:opacity-60',
                                    on
                                        ? 'bg-primary-soft text-primary'
                                        : 'border-border text-text-secondary hover:bg-accent-subtle border bg-white',
                                )}
                            >
                                {on && <Check className="size-3.5" />}
                                {folder.name}
                            </button>
                        );
                    })}
                </div>
                <span className="text-muted-foreground text-[12px]">
                    Além dos próprios documentos, a pessoa verá os documentos
                    das pastas marcadas.
                </span>
            </div>
        );
    }

    return (
        <div className="border-border divide-muted max-h-72 divide-y overflow-y-auto rounded-lg border">
            {folders.map((folder) => {
                const grant = selected.get(folder.id);
                const id = `folder-${folder.id}`;

                return (
                    <div
                        key={folder.id}
                        className="flex items-center gap-3 px-3 py-2"
                    >
                        <Checkbox
                            id={id}
                            checked={!!grant}
                            disabled={disabled}
                            onCheckedChange={(v) => toggle(folder, v === true)}
                        />
                        <label
                            htmlFor={id}
                            className="flex min-w-0 flex-1 cursor-pointer items-center gap-2 text-[13.5px] font-medium"
                        >
                            <FolderIcon className="text-muted-foreground size-4 shrink-0" />
                            <span className="truncate">{folder.name}</span>
                        </label>
                        {grant && (
                            <Select
                                value={grant.level}
                                disabled={disabled}
                                onValueChange={(v) =>
                                    setLevel(folder.id, v as AccessLevel)
                                }
                            >
                                <SelectTrigger
                                    size="sm"
                                    className="h-7 w-[124px] text-[12px]"
                                    aria-label={`Nível de acesso a ${folder.name}`}
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {(['view', 'manage'] as AccessLevel[]).map(
                                        (level) => (
                                            <SelectItem
                                                key={level}
                                                value={level}
                                                title={
                                                    accessLevelDescriptions[
                                                        level
                                                    ]
                                                }
                                            >
                                                {accessLevelLabels[level]}
                                            </SelectItem>
                                        ),
                                    )}
                                </SelectContent>
                            </Select>
                        )}
                    </div>
                );
            })}
        </div>
    );
}
