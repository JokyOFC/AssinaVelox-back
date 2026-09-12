import { Head, Link } from '@inertiajs/react';
import { ExternalLink, Info } from 'lucide-react';
import type { ReactNode } from 'react';
import { MethodBadge } from '@/components/integrations/badges';
import {
    CodeBlock,
    InlineCode,
    ResponseBlock,
} from '@/components/integrations/code-block';
import {
    IntegrationsCard,
    IntegrationsShell,
} from '@/components/integrations/integrations-shell';
import type {
    AbilityOption,
    ApiEndpointRow,
    ApiLimits,
    EventOption,
    IntegrationsNavigation,
    ProblemRow,
    SignatureInfo,
} from '@/components/integrations/types';
import { Button } from '@/components/ui/button';
import { formatNumber } from '@/lib/format';
import { cn } from '@/lib/utils';
import {
    index as integrationsIndex,
    keys as keysRoute,
} from '@/routes/integrations';
import { index as webhooksIndex } from '@/routes/integrations/webhooks';

interface DocsProps {
    navigation: IntegrationsNavigation;
    base_url: string;
    openapi: { ui_url: string; json_url: string } | null;
    endpoints: ApiEndpointRow[];
    abilities: AbilityOption[];
    problems: ProblemRow[];
    limits: ApiLimits;
    events: EventOption[];
    signature: SignatureInfo;
    sample_event: Record<string, unknown>;
}

/**
 * Integrações → Documentação (Fase 2 §2.15–§2.17; mock "App - API"). Guia
 * rápido de autenticação, idempotência, rotas, erros, webhooks e REST Hooks.
 * A referência completa é a OpenAPI (Scramble), aberta à parte.
 */
export default function IntegrationsDocs({
    navigation,
    base_url,
    openapi,
    endpoints,
    abilities,
    problems,
    limits,
    events,
    signature,
    sample_event,
}: DocsProps) {
    const api = navigation.keys;
    const hooks = navigation.webhooks;
    const restHooks = navigation.rest_hooks;

    const toc: { group: string; items: { id: string; label: string }[] }[] = [
        {
            group: 'Começando',
            items: [
                { id: 'introducao', label: 'Introdução' },
                ...(api
                    ? [
                          { id: 'autenticacao', label: 'Autenticação' },
                          { id: 'idempotencia', label: 'Idempotência' },
                          { id: 'erros', label: 'Erros e limites' },
                      ]
                    : []),
            ],
        },
        ...(api
            ? [
                  {
                      group: 'Documentos',
                      items: [
                          { id: 'criar', label: 'Criar documento' },
                          { id: 'rotas', label: 'Todas as rotas' },
                      ],
                  },
              ]
            : []),
        {
            group: 'Eventos',
            items: [
                ...(hooks
                    ? [
                          { id: 'webhooks', label: 'Webhooks' },
                          { id: 'assinatura', label: 'Validar a assinatura' },
                      ]
                    : []),
                ...(restHooks
                    ? [{ id: 'rest-hooks', label: 'REST Hooks (no-code)' }]
                    : []),
            ],
        },
    ].filter((section) => section.items.length > 0);

    return (
        <>
            <Head title="Documentação da API" />
            <IntegrationsShell
                active="docs"
                navigation={navigation}
                title="Documentação da API"
                subtitle="REST · JSON · HTTPS. Integre o envio e o acompanhamento de documentos ao seu sistema."
            >
                <div className="flex items-start gap-6">
                    <nav
                        aria-label="Nesta página"
                        className="sticky top-20 hidden w-[200px] shrink-0 flex-col gap-3.5 text-[13px] lg:flex"
                    >
                        {toc.map((section) => (
                            <div key={section.group}>
                                <p className="text-muted-foreground px-2.5 pb-1.5 text-[10.5px] font-bold tracking-[.14em] uppercase">
                                    {section.group}
                                </p>
                                {section.items.map((item) => (
                                    <a
                                        key={item.id}
                                        href={`#${item.id}`}
                                        className="text-text-secondary hover:bg-accent hover:text-foreground block rounded-md px-2.5 py-1.5"
                                    >
                                        {item.label}
                                    </a>
                                ))}
                            </div>
                        ))}
                    </nav>

                    <div className="flex min-w-0 flex-1 flex-col gap-7">
                        <section
                            id="introducao"
                            className="flex scroll-mt-20 flex-col gap-3.5"
                        >
                            <div className="grid grid-cols-[repeat(auto-fit,minmax(240px,1fr))] gap-3">
                                <InfoTile label="Base URL">
                                    <code className="font-mono text-[13px] break-all">
                                        {api ? base_url : '—'}
                                    </code>
                                    <span className="text-muted-foreground text-[12px]">
                                        {api
                                            ? 'Versão 1. Mudanças incompatíveis viram /v2.'
                                            : 'A API não está disponível no plano desta conta.'}
                                    </span>
                                </InfoTile>
                                {api && (
                                    <InfoTile label="Chaves">
                                        <span className="text-[13px] font-semibold">
                                            Bearer token por integração
                                        </span>
                                        <Link
                                            href={keysRoute.url()}
                                            className="text-primary text-[12px] font-semibold"
                                        >
                                            Gerenciar chaves →
                                        </Link>
                                    </InfoTile>
                                )}
                                {api && (
                                    <InfoTile label="Limites">
                                        <span className="text-[13px] font-semibold">
                                            {formatNumber(
                                                limits.per_token_per_minute,
                                            )}{' '}
                                            req/min por chave ·{' '}
                                            {formatNumber(
                                                limits.per_organization_per_minute,
                                            )}{' '}
                                            por conta
                                        </span>
                                        <span className="text-muted-foreground text-[12px]">
                                            Cabeçalhos{' '}
                                            <code className="font-mono">
                                                RateLimit-*
                                            </code>{' '}
                                            em toda resposta
                                        </span>
                                    </InfoTile>
                                )}
                                {openapi && (
                                    <InfoTile label="Referência completa">
                                        <span className="text-[13px] font-semibold">
                                            OpenAPI 3.1
                                        </span>
                                        <span className="flex flex-wrap gap-3 text-[12px] font-semibold">
                                            <a
                                                href={openapi.ui_url}
                                                target="_blank"
                                                rel="noreferrer"
                                                className="text-primary inline-flex items-center gap-1"
                                            >
                                                Abrir referência
                                                <ExternalLink className="size-3" />
                                            </a>
                                            <a
                                                href={openapi.json_url}
                                                target="_blank"
                                                rel="noreferrer"
                                                className="text-primary"
                                            >
                                                Baixar JSON
                                            </a>
                                        </span>
                                    </InfoTile>
                                )}
                            </div>
                            <Prose>
                                {api ? (
                                    <>
                                        A API segue REST: requisições e
                                        respostas em JSON (UTF-8), sempre por
                                        HTTPS. Os identificadores são ULIDs
                                        públicos de 26 caracteres e as datas vêm
                                        em ISO-8601 UTC (
                                        <InlineCode>
                                            2026-09-11T14:03:22Z
                                        </InlineCode>
                                        ). Um recurso vem em{' '}
                                        <InlineCode>data</InlineCode>; listas
                                        trazem <InlineCode>links</InlineCode> e{' '}
                                        <InlineCode>meta</InlineCode> com
                                        paginação por cursor. Ignore campos
                                        desconhecidos: a v1 só ganha campos
                                        novos, nunca perde.
                                    </>
                                ) : (
                                    <>
                                        Esta conta tem webhooks de saída: sua
                                        aplicação recebe um POST assinado a cada
                                        evento dos documentos. A API REST e as
                                        chaves de acesso não fazem parte do
                                        plano atual.
                                    </>
                                )}
                            </Prose>
                        </section>

                        {api && (
                            <TwoColumns
                                id="autenticacao"
                                title="Autenticação"
                                aside={
                                    <CodeBlock
                                        label="Requisição autenticada"
                                        samples={[
                                            {
                                                key: 'curl',
                                                label: 'cURL',
                                                code: `curl ${base_url}/envelopes \\\n  -H "Authorization: Bearer $ASSINAVELOX_TOKEN" \\\n  -H "Accept: application/json"`,
                                            },
                                        ]}
                                    />
                                }
                            >
                                <Prose>
                                    Envie a chave no cabeçalho{' '}
                                    <InlineCode>Authorization</InlineCode> como{' '}
                                    <InlineCode>Bearer</InlineCode>. Crie as
                                    chaves em{' '}
                                    <Link
                                        href={keysRoute.url()}
                                        className="text-primary font-semibold"
                                    >
                                        Chaves
                                    </Link>
                                    : cada uma pertence a esta conta e a quem a
                                    criou, tem permissões explícitas e é exibida
                                    uma única vez. Nunca exponha a chave no
                                    navegador — chame a API pelo seu servidor ou
                                    pelo cofre do conector.
                                </Prose>
                                <Callout>
                                    A chave faz só o que as permissões dela
                                    permitem <b>e</b> o que a pessoa que a criou
                                    pode fazer agora. Documentos fora da
                                    visibilidade dessa pessoa respondem 404.
                                    Revogar é imediato.
                                </Callout>
                                <div className="border-border bg-card divide-border divide-y rounded-xl border">
                                    {abilities.map((ability) => (
                                        <div
                                            key={ability.value}
                                            className="grid grid-cols-[minmax(130px,170px)_1fr] gap-3 px-4 py-2.5 text-[13px]"
                                        >
                                            <code className="font-mono text-[12.5px] font-semibold">
                                                {ability.value}
                                            </code>
                                            <span className="text-text-secondary">
                                                {ability.description}
                                            </span>
                                        </div>
                                    ))}
                                </div>
                            </TwoColumns>
                        )}

                        {api && (
                            <TwoColumns
                                id="idempotencia"
                                title="Idempotência"
                                aside={
                                    <ResponseBlock
                                        status="Idempotent-Replayed: true"
                                        label="Repetição com a mesma chave"
                                        code={`HTTP/1.1 201 Created\nIdempotent-Replayed: true\nLocation: ${base_url}/envelopes/01K…`}
                                    />
                                }
                            >
                                <Prose>
                                    Toda criação e todo envio exigem o cabeçalho{' '}
                                    <InlineCode>Idempotency-Key</InlineCode> com
                                    um valor único por operação (um UUID, por
                                    exemplo). Se a conexão cair e você repetir o
                                    pedido com a mesma chave e o mesmo corpo,
                                    recebe a mesma resposta em vez de criar
                                    outro documento — por{' '}
                                    {limits.idempotency_ttl_hours} horas. A
                                    mesma chave com outro corpo dá{' '}
                                    <InlineCode>409</InlineCode>.
                                </Prose>
                                <Prose>
                                    Tempo esgotado não significa que a operação
                                    falhou: repita com a mesma chave ou consulte
                                    o recurso antes de criar de novo.
                                </Prose>
                            </TwoColumns>
                        )}

                        {api && (
                            <section
                                id="criar"
                                className="flex scroll-mt-20 flex-col gap-3.5"
                            >
                                <div className="flex flex-wrap items-center gap-2.5">
                                    <MethodBadge method="POST" />
                                    <code className="font-mono text-[14px] font-semibold">
                                        /envelopes
                                    </code>
                                    <h2 className="ml-1.5 text-[18px] font-bold">
                                        Criar documento
                                    </h2>
                                </div>
                                <div className="flex flex-wrap items-start gap-5">
                                    <div className="flex min-w-0 flex-[1_1_300px] flex-col gap-3">
                                        <Prose>
                                            Cria um rascunho. Depois envie o
                                            arquivo (
                                            <InlineCode>
                                                POST
                                                /envelopes/&#123;id&#125;/documents
                                            </InlineCode>
                                            , só multipart, até{' '}
                                            {limits.max_upload_mb} MB), defina
                                            participantes e campos e envie (
                                            <InlineCode>
                                                POST
                                                /envelopes/&#123;id&#125;/send
                                            </InlineCode>
                                            ). O documento só consome o plano
                                            quando é enviado.
                                        </Prose>
                                        <ParamTable
                                            rows={[
                                                [
                                                    'title',
                                                    'string',
                                                    true,
                                                    'Título exibido aos participantes.',
                                                ],
                                                [
                                                    'message',
                                                    'string',
                                                    false,
                                                    'Mensagem incluída nos convites.',
                                                ],
                                                [
                                                    'signing_order',
                                                    'enum',
                                                    false,
                                                    'sequential ou parallel.',
                                                ],
                                                [
                                                    'expires_in_days',
                                                    'integer',
                                                    false,
                                                    'Prazo para concluir.',
                                                ],
                                                [
                                                    'folder_id',
                                                    'string',
                                                    false,
                                                    'Pasta de destino (ULID).',
                                                ],
                                            ]}
                                        />
                                    </div>
                                    <div className="flex min-w-0 flex-[1_1_360px] flex-col gap-3">
                                        <CodeBlock
                                            label="Exemplo"
                                            samples={createSamples(base_url)}
                                        />
                                        <ResponseBlock
                                            status="201 Created"
                                            code={`{\n  "data": {\n    "id": "01K7Q3N6T2S8R4P1M0L9K7J5H3",\n    "object": "envelope",\n    "display_code": "AV-000124",\n    "status": "draft",\n    "documents": [],\n    "recipients": []\n  }\n}`}
                                        />
                                    </div>
                                </div>
                            </section>
                        )}

                        {api && endpoints.length > 0 && (
                            <section
                                id="rotas"
                                className="flex scroll-mt-20 flex-col gap-3"
                            >
                                <h2 className="text-[18px] font-bold">
                                    Todas as rotas
                                </h2>
                                <div className="border-border bg-card overflow-x-auto rounded-xl border">
                                    <div className="min-w-[680px]">
                                        {endpoints.map((endpoint) => (
                                            <div
                                                key={`${endpoint.method}-${endpoint.name}`}
                                                className="border-border hover:bg-row-hover grid grid-cols-[70px_minmax(0,1.5fr)_minmax(0,2fr)] items-center gap-3 border-b px-4 py-2.5 text-[13px] last:border-b-0"
                                            >
                                                <MethodBadge
                                                    method={endpoint.method}
                                                />
                                                <span className="min-w-0">
                                                    <code className="block font-mono text-[12.5px] font-semibold break-all">
                                                        {endpoint.path}
                                                    </code>
                                                    <span className="text-muted-foreground text-[11.5px]">
                                                        {endpoint.abilities.join(
                                                            ', ',
                                                        )}
                                                        {endpoint.idempotency ===
                                                            'required' &&
                                                            ' · Idempotency-Key obrigatória'}
                                                        {endpoint.idempotency ===
                                                            'optional' &&
                                                            ' · Idempotency-Key opcional'}
                                                    </span>
                                                </span>
                                                <span className="text-text-secondary">
                                                    {endpoint.description}
                                                </span>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            </section>
                        )}

                        {hooks && (
                            <TwoColumns
                                id="webhooks"
                                title="Webhooks"
                                aside={
                                    <CodeBlock
                                        label={`Payload · ${typeof sample_event.type === 'string' ? sample_event.type : ''}`}
                                        samples={[
                                            {
                                                key: 'json',
                                                label: 'JSON',
                                                code: JSON.stringify(
                                                    sample_event,
                                                    null,
                                                    2,
                                                ),
                                            },
                                        ]}
                                    />
                                }
                            >
                                <Prose>
                                    Cadastre uma URL HTTPS em{' '}
                                    <Link
                                        href={webhooksIndex.url()}
                                        className="text-primary font-semibold"
                                    >
                                        Webhooks
                                    </Link>
                                    . Cada evento chega como POST com o corpo
                                    mínimo: só identificadores e status — nunca
                                    nome, e-mail, telefone, CPF, título ou
                                    arquivo. Para detalhes, consulte a API com o
                                    id recebido.
                                </Prose>
                                <div className="border-border bg-card divide-border divide-y rounded-xl border">
                                    {events.map((event) => (
                                        <div
                                            key={event.value}
                                            className="grid grid-cols-[minmax(150px,190px)_1fr] gap-3 px-4 py-2.5 text-[13px]"
                                        >
                                            <code className="text-primary font-mono text-[12.5px] font-semibold">
                                                {event.value}
                                            </code>
                                            <span className="text-text-secondary">
                                                {event.description}
                                            </span>
                                        </div>
                                    ))}
                                </div>
                                <Callout>
                                    <InlineCode>recipient.signed</InlineCode> é
                                    o aceite eletrônico registrado, não uma
                                    assinatura com certificado;{' '}
                                    <InlineCode>recipient.viewed</InlineCode> é
                                    a abertura detectada do convite, não prova
                                    de leitura.
                                </Callout>
                            </TwoColumns>
                        )}

                        {hooks && (
                            <TwoColumns
                                id="assinatura"
                                title="Validar a assinatura"
                                aside={
                                    <CodeBlock
                                        label="Validação no receptor"
                                        samples={signatureSamples}
                                    />
                                }
                            >
                                <Prose>
                                    O cabeçalho{' '}
                                    <InlineCode>
                                        {signature.signature}
                                    </InlineCode>{' '}
                                    traz{' '}
                                    <InlineCode>
                                        v1=HMAC-SHA256(segredo,
                                        "timestamp.corpo")
                                    </InlineCode>
                                    , com o timestamp de{' '}
                                    <InlineCode>
                                        {signature.timestamp}
                                    </InlineCode>
                                    . Calcule sobre o corpo bruto, antes de
                                    interpretar o JSON, e compare em tempo
                                    constante.
                                </Prose>
                                <ul className="text-text-secondary flex list-disc flex-col gap-1.5 pl-5 text-[14px] leading-[1.65]">
                                    <li>
                                        Recuse se o timestamp estiver a mais de{' '}
                                        {signature.tolerance_seconds} segundos
                                        do seu relógio.
                                    </li>
                                    <li>
                                        Durante a rotação do segredo chegam duas
                                        assinaturas{' '}
                                        <InlineCode>v1=…</InlineCode>: aceite se
                                        qualquer uma conferir.
                                    </li>
                                    <li>
                                        Deduplique por{' '}
                                        <InlineCode>
                                            {signature.delivery_id}
                                        </InlineCode>
                                        : ele se repete nas novas tentativas.
                                    </li>
                                    <li>
                                        Responda 2xx rápido e processe depois;
                                        redirecionamentos não são seguidos.
                                    </li>
                                </ul>
                            </TwoColumns>
                        )}

                        {restHooks && (
                            <TwoColumns
                                id="rest-hooks"
                                title="REST Hooks para n8n, Zapier e Make"
                                aside={
                                    <CodeBlock
                                        label="Assinar e remover"
                                        samples={restHookSamples(base_url)}
                                    />
                                }
                            >
                                <Prose>
                                    Conectores no-code assinam eventos pela
                                    própria API: um{' '}
                                    <InlineCode>
                                        POST /webhook-subscriptions
                                    </InlineCode>{' '}
                                    com <InlineCode>target_url</InlineCode> e{' '}
                                    <InlineCode>event</InlineCode> cria um
                                    endpoint ligado à chave (com a mesma
                                    proteção de rede e a mesma assinatura) e
                                    devolve o segredo uma única vez; um{' '}
                                    <InlineCode>DELETE</InlineCode> remove. Use
                                    uma chave com a permissão{' '}
                                    <InlineCode>webhooks:manage</InlineCode>.
                                </Prose>
                                <Prose>
                                    Para mapear campos antes do primeiro evento
                                    real,{' '}
                                    <InlineCode>
                                        GET
                                        /webhook-events/&#123;evento&#125;/sample
                                    </InlineCode>{' '}
                                    devolve um exemplo com identificadores
                                    fictícios. Cada chave pode ter até{' '}
                                    {limits.rest_hooks_per_token} assinaturas;
                                    revogar a chave remove todas.
                                </Prose>
                                <Callout>
                                    Os apps publicados nos marketplaces do
                                    Zapier e do Make ainda não existem: a
                                    publicação exige conta de desenvolvedor e
                                    revisão dessas plataformas. Hoje a
                                    integração é feita com os módulos HTTP e de
                                    webhook de cada uma, e os planos delas têm
                                    custo próprio.
                                </Callout>
                            </TwoColumns>
                        )}

                        {api && (
                            <section
                                id="erros"
                                className="flex scroll-mt-20 flex-col gap-3"
                            >
                                <h2 className="text-[18px] font-bold">
                                    Erros e limites
                                </h2>
                                <Prose>
                                    Todo erro sai como{' '}
                                    <InlineCode>
                                        application/problem+json
                                    </InlineCode>{' '}
                                    (RFC 9457) com <InlineCode>type</InlineCode>{' '}
                                    estável e{' '}
                                    <InlineCode>correlation_id</InlineCode> —
                                    informe-o ao suporte. Trate{' '}
                                    <InlineCode>type</InlineCode> desconhecido
                                    pelo status.
                                </Prose>
                                <div className="border-border bg-card overflow-x-auto rounded-xl border">
                                    <div className="min-w-[560px]">
                                        {problems.map((problem) => (
                                            <div
                                                key={problem.type}
                                                className="border-border grid grid-cols-[60px_minmax(0,1fr)_minmax(0,2fr)] items-center gap-3 border-b px-4 py-2.5 text-[13px] last:border-b-0"
                                            >
                                                <code
                                                    className={cn(
                                                        'font-mono text-[12.5px] font-bold',
                                                        problem.status >= 500
                                                            ? 'text-danger'
                                                            : problem.status ===
                                                                    409 ||
                                                                problem.status ===
                                                                    429
                                                              ? 'text-warning'
                                                              : problem.status ===
                                                                  404
                                                                ? 'text-text-secondary'
                                                                : 'text-danger',
                                                    )}
                                                >
                                                    {problem.status}
                                                </code>
                                                <code className="font-mono text-[12.5px] font-semibold">
                                                    {problem.type}
                                                </code>
                                                <span className="text-text-secondary">
                                                    {problem.description}
                                                </span>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                                <ResponseBlock
                                    status="422"
                                    tone="danger"
                                    label="Exemplo de erro"
                                    code={`{\n  "type": "urn:assinavelox:problem:validation-failed",\n  "title": "Dados inválidos",\n  "status": 422,\n  "detail": "Um ou mais campos não passaram na validação.",\n  "instance": "/api/v1/envelopes",\n  "errors": { "title": ["O campo título é obrigatório."] },\n  "correlation_id": "01K7Q3N6X4W9ZB2C5D8E1F0G3H"\n}`}
                                />
                                {openapi && (
                                    <div>
                                        <Button asChild variant="outline">
                                            <a
                                                href={openapi.ui_url}
                                                target="_blank"
                                                rel="noreferrer"
                                            >
                                                Abrir a referência OpenAPI
                                                completa
                                                <ExternalLink className="size-3.5" />
                                            </a>
                                        </Button>
                                    </div>
                                )}
                            </section>
                        )}
                    </div>
                </div>
            </IntegrationsShell>
        </>
    );
}

IntegrationsDocs.layout = {
    breadcrumbs: [{ title: 'API e integrações', href: integrationsIndex() }],
};

function InfoTile({ label, children }: { label: string; children: ReactNode }) {
    return (
        <IntegrationsCard className="flex flex-col gap-1.5 px-[18px] py-4">
            <span className="text-muted-foreground text-[12px] font-semibold">
                {label}
            </span>
            {children}
        </IntegrationsCard>
    );
}

function Prose({ children }: { children: ReactNode }) {
    return (
        <p className="text-text-secondary max-w-[760px] text-[14px] leading-[1.65]">
            {children}
        </p>
    );
}

function Callout({ children }: { children: ReactNode }) {
    return (
        <div className="bg-warning-bg border-warning-border text-warning flex items-start gap-2.5 rounded-[10px] border px-3.5 py-3 text-[13px] leading-[1.5]">
            <Info className="mt-0.5 size-4 shrink-0" />
            <div>{children}</div>
        </div>
    );
}

function TwoColumns({
    id,
    title,
    aside,
    children,
}: {
    id: string;
    title: string;
    aside: ReactNode;
    children: ReactNode;
}) {
    return (
        <section
            id={id}
            className="flex scroll-mt-20 flex-wrap items-start gap-5"
        >
            <div className="flex min-w-0 flex-[1_1_300px] flex-col gap-2.5">
                <h2 className="text-[18px] font-bold">{title}</h2>
                {children}
            </div>
            <div className="min-w-0 flex-[1_1_360px]">{aside}</div>
        </section>
    );
}

function ParamTable({ rows }: { rows: [string, string, boolean, string][] }) {
    return (
        <div className="border-border bg-card rounded-xl border">
            <div className="text-muted-foreground border-border border-b px-4 py-2.5 text-[12px] font-semibold">
                Parâmetros do corpo
            </div>
            {rows.map(([name, type, required, description]) => (
                <div
                    key={name}
                    className="border-border grid grid-cols-[minmax(120px,160px)_1fr] gap-3 border-b px-4 py-2.5 text-[13px] last:border-b-0"
                >
                    <span>
                        <code className="font-mono text-[12.5px] font-semibold">
                            {name}
                        </code>
                        <span
                            className={cn(
                                'mt-0.5 block text-[11px]',
                                required
                                    ? 'text-danger'
                                    : 'text-muted-foreground',
                            )}
                        >
                            {type} · {required ? 'obrigatório' : 'opcional'}
                        </span>
                    </span>
                    <span className="text-text-secondary leading-[1.5]">
                        {description}
                    </span>
                </div>
            ))}
        </div>
    );
}

function createSamples(base: string) {
    return [
        {
            key: 'curl',
            label: 'cURL',
            code: `curl -X POST ${base}/envelopes \\
  -H "Authorization: Bearer $ASSINAVELOX_TOKEN" \\
  -H "Content-Type: application/json" \\
  -H "Accept: application/json" \\
  -H "Idempotency-Key: 6f1c0e0a-2d4b-4c1e-9a5e-1d2f3b4c5d6e" \\
  -d '{"title": "Contrato de prestação de serviços", "expires_in_days": 15}'`,
        },
        {
            key: 'php',
            label: 'PHP',
            code: `// Laravel · Illuminate\\Support\\Facades\\Http
$response = Http::withToken(config('services.assinavelox.token'))
    ->acceptJson()
    ->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
    ->post('${base}/envelopes', [
        'title' => 'Contrato de prestação de serviços',
        'expires_in_days' => 15,
    ]);

$envelopeId = $response->json('data.id');`,
        },
        {
            key: 'js',
            label: 'JavaScript',
            code: `// Node.js (servidor — nunca no navegador)
const res = await fetch('${base}/envelopes', {
  method: 'POST',
  headers: {
    Authorization: \`Bearer \${process.env.ASSINAVELOX_TOKEN}\`,
    'Content-Type': 'application/json',
    Accept: 'application/json',
    'Idempotency-Key': crypto.randomUUID(),
  },
  body: JSON.stringify({ title: 'Contrato de prestação de serviços', expires_in_days: 15 }),
});
const { data } = await res.json(); // data.id, data.status === 'draft'`,
        },
    ];
}

function restHookSamples(base: string) {
    return [
        {
            key: 'subscribe',
            label: 'Assinar',
            code: `curl -X POST ${base}/webhook-subscriptions \\
  -H "Authorization: Bearer $ASSINAVELOX_TOKEN" \\
  -H "Content-Type: application/json" \\
  -H "Idempotency-Key: <valor único por assinatura>" \\
  -d '{"target_url": "https://seu-receptor.exemplo.com/hooks/123", "event": "envelope.completed"}'

# 201 Created
# { "data": { "id": "01K…", "object": "webhook_subscription",
#   "target_url": "https://…", "events": ["envelope.completed"],
#   "status": "active", "secret": "whsec_…" } }`,
        },
        {
            key: 'unsubscribe',
            label: 'Remover',
            code: `curl -X DELETE ${base}/webhook-subscriptions/01K… \\
  -H "Authorization: Bearer $ASSINAVELOX_TOKEN"

# 204 No Content`,
        },
        {
            key: 'sample',
            label: 'Exemplo',
            code: `curl ${base}/webhook-events/envelope.completed/sample \\
  -H "Authorization: Bearer $ASSINAVELOX_TOKEN"

# { "data": [ { "id": "01SAMP…", "type": "envelope.completed", … } ],
#   "meta": { "sample": true } }`,
        },
    ];
}

const signatureSamples = [
    {
        key: 'node',
        label: 'Node.js',
        code: `const crypto = require('node:crypto');

function webhookValido(segredo, corpoBruto, timestamp, assinaturas, tolerancia = 300) {
  if (!/^\\d+$/.test(timestamp ?? '')) return false;
  if (Math.abs(Math.floor(Date.now() / 1000) - Number(timestamp)) > tolerancia) return false;

  const esperado = crypto.createHmac('sha256', segredo)
    .update(\`\${timestamp}.\`).update(corpoBruto).digest();

  return (assinaturas ?? '').split(',').some((parte) => {
    const [versao, valor = ''] = parte.trim().split('=');
    if (versao !== 'v1' || !/^[0-9a-f]{64}$/.test(valor)) return false;
    return crypto.timingSafeEqual(esperado, Buffer.from(valor, 'hex'));
  });
}`,
    },
    {
        key: 'php',
        label: 'PHP',
        code: `function assinaveloxWebhookValido(string $segredo, string $corpoBruto, string $timestamp, string $assinaturas, int $tolerancia = 300): bool
{
    if (! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > $tolerancia) {
        return false;
    }

    $esperado = hash_hmac('sha256', $timestamp.'.'.$corpoBruto, $segredo);

    foreach (explode(',', $assinaturas) as $parte) {
        [$versao, $valor] = array_pad(explode('=', trim($parte), 2), 2, '');

        if ($versao === 'v1' && hash_equals($esperado, $valor)) {
            return true;
        }
    }

    return false;
}`,
    },
    {
        key: 'python',
        label: 'Python',
        code: `import hashlib, hmac, time

def webhook_valido(segredo: str, corpo_bruto: bytes, timestamp: str, assinaturas: str, tolerancia: int = 300) -> bool:
    if not timestamp.isdigit() or abs(int(time.time()) - int(timestamp)) > tolerancia:
        return False
    esperado = hmac.new(segredo.encode(), timestamp.encode() + b"." + corpo_bruto, hashlib.sha256).hexdigest()
    for parte in assinaturas.split(","):
        versao, _, valor = parte.strip().partition("=")
        if versao == "v1" and hmac.compare_digest(esperado, valor):
            return True
    return False`,
    },
];
