import { Head, useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import type { FormEvent } from 'react';
import { IntegrationsCard } from '@/components/integrations/integrations-shell';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { index as integrationsIndex } from '@/routes/integrations';
import {
    edit as embedEdit,
    update as embedUpdate,
} from '@/routes/integrations/embed';

/**
 * API e integrações → Widget de assinatura (Fase 3 §3.9, docs/fase-3/widget-embutido.md §4).
 * Origens EXATAS que podem exibir o documento para assinatura dentro de um iframe.
 */
interface EmbedOriginsProps {
    origins: string[];
    max_origins: number;
    allows_loopback: boolean;
    api_enabled: boolean;
    script_url: string;
    protocol_version: number;
}

export default function EmbedOrigins({
    origins,
    max_origins,
    allows_loopback,
    api_enabled,
    script_url,
}: EmbedOriginsProps) {
    const form = useForm<{ origins: string[] }>({
        origins: origins.length > 0 ? origins : [''],
    });
    const errors = form.errors as Record<string, string | undefined>;

    const setOrigin = (index: number, value: string) =>
        form.setData(
            'origins',
            form.data.origins.map((item, position) =>
                position === index ? value : item,
            ),
        );

    const removeOrigin = (index: number) =>
        form.setData(
            'origins',
            form.data.origins.filter((_, position) => position !== index),
        );

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.put(embedUpdate.url(), { preserveScroll: true });
    };

    const snippet = [
        '<div id="assinatura"></div>',
        `<script src="${script_url}"></script>`,
        '<script>',
        '  // `url` vem do SEU servidor: POST /api/v1/envelopes/{id}/recipients/{id}/embedded-sessions',
        '  AssinaVelox.mount({',
        '    url: urlDaSessao,',
        "    container: '#assinatura',",
        "    onReady: (e) => console.log('widget pronto', e.screen),",
        "    onCompleted: (e) => console.log('aceite registrado', e.session),",
        "    onRefused: (e) => console.log('recusado', e.session),",
        '    onError: (e) => console.error(e.code, e.message),',
        '  });',
        '</script>',
    ].join('\n');

    return (
        <>
            <Head title="Widget de assinatura" />
            <PageHeader
                title="Widget de assinatura"
                subtitle="Sites que podem exibir o documento para o participante assinar dentro de um iframe."
            />

            <div className="flex flex-col gap-4">
                {!api_enabled && (
                    <p className="border-border bg-sidebar text-text-secondary rounded-[10px] border p-3 text-[13px] leading-[1.5]">
                        A sessão do widget é criada pela API v1, que ainda não
                        está disponível para esta organização. Você pode
                        cadastrar as origens agora; o widget só abre quando a
                        API estiver ligada.
                    </p>
                )}

                <IntegrationsCard
                    title="Origens permitidas"
                    description={`Endereço exato de cada site, no formato https://app.seusite.com.br (com porta, se houver). Sem caminho e sem curinga. Até ${max_origins} origens.${allows_loopback ? ' Fora de produção, http://localhost também é aceito.' : ''}`}
                >
                    <form
                        onSubmit={submit}
                        className="flex flex-col gap-3 px-5 pb-5"
                    >
                        {form.data.origins.map((origin, index) => (
                            <div key={index} className="flex flex-col gap-1">
                                <div className="flex items-center gap-2">
                                    <Input
                                        value={origin}
                                        onChange={(event) =>
                                            setOrigin(index, event.target.value)
                                        }
                                        placeholder="https://app.seusite.com.br"
                                        aria-label={`Origem ${index + 1}`}
                                        aria-invalid={
                                            errors[`origins.${index}`]
                                                ? true
                                                : undefined
                                        }
                                        inputMode="url"
                                        autoComplete="off"
                                        spellCheck={false}
                                    />
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="icon"
                                        aria-label={`Remover origem ${index + 1}`}
                                        onClick={() => removeOrigin(index)}
                                    >
                                        <Trash2 className="size-4" />
                                    </Button>
                                </div>
                                {errors[`origins.${index}`] && (
                                    <p className="text-danger text-[12.5px]">
                                        {errors[`origins.${index}`]}
                                    </p>
                                )}
                            </div>
                        ))}

                        {errors.origins && (
                            <p className="text-danger text-[12.5px]">
                                {errors.origins}
                            </p>
                        )}

                        <div className="flex flex-wrap items-center gap-2">
                            <Button
                                type="button"
                                variant="dashed"
                                size="sm"
                                disabled={
                                    form.data.origins.length >= max_origins
                                }
                                onClick={() =>
                                    form.setData('origins', [
                                        ...form.data.origins,
                                        '',
                                    ])
                                }
                            >
                                <Plus className="size-4" />
                                Adicionar origem
                            </Button>
                            <Button
                                type="submit"
                                size="sm"
                                disabled={form.processing}
                            >
                                Salvar origens
                            </Button>
                        </div>
                    </form>
                </IntegrationsCard>

                <IntegrationsCard
                    title="Como usar"
                    description="O seu servidor cria a sessão pela API (o token da API nunca vai para o navegador) e a página usa o embed.js para abrir o widget."
                >
                    <div className="flex flex-col gap-3 px-5 pb-5 text-[13px] leading-[1.55]">
                        <ol className="text-text-secondary list-decimal space-y-1 pl-5">
                            <li>
                                Crie a sessão do participante com uma chave que
                                tenha a permissão{' '}
                                <code>embedded_signing:manage</code>, informando
                                o endereço (origem) do site.
                            </li>
                            <li>
                                Entregue à página o endereço devolvido (
                                <code>url</code>): ele vale uma única vez e por
                                poucos minutos.
                            </li>
                            <li>
                                A pessoa confirma o código recebido e registra o
                                aceite dentro do widget; o site é avisado por
                                mensagem, e os webhooks continuam valendo.
                            </li>
                        </ol>
                        <pre className="bg-sidebar border-border overflow-x-auto rounded-[10px] border p-3 text-[12px] leading-[1.5]">
                            <code>{snippet}</code>
                        </pre>
                    </div>
                </IntegrationsCard>
            </div>
        </>
    );
}

EmbedOrigins.layout = {
    breadcrumbs: [
        { title: 'API e integrações', href: integrationsIndex() },
        { title: 'Widget de assinatura', href: embedEdit() },
    ],
};
