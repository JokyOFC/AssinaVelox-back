import { Head, Link, usePage } from '@inertiajs/react';
import { FileCheck2, Mail, ShieldCheck } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { dashboard, login, register } from '@/routes';
import { index as verifyIndex } from '@/routes/verify';

const FEATURES = [
    {
        icon: Mail,
        title: 'Convite por e-mail e código de confirmação',
        text: 'O signatário recebe um link seguro e confirma a identidade com um código de uso único antes de assinar.',
    },
    {
        icon: FileCheck2,
        title: 'PDF final com trilha de auditoria',
        text: 'Cada aceite registra data do servidor, IP, dispositivo e o hash do documento apresentado.',
    },
    {
        icon: ShieldCheck,
        title: 'Assinatura criptográfica da operadora',
        text: 'O documento concluído é assinado com o certificado A1 da AssinaVelox e pode ser verificado publicamente.',
    },
];

/** Home institucional mínima (ROUTES §1.3: hero + CTA login/cadastro). */
export default function Home() {
    const { auth } = usePage().props;

    return (
        <>
            <Head title="Assinatura eletrônica de documentos" />
            <section className="bg-navy relative overflow-hidden px-6 py-16 text-white md:py-24">
                <div
                    aria-hidden
                    className="pointer-events-none absolute -top-[160px] -right-[120px] size-[520px] rounded-full"
                    style={{
                        background:
                            'radial-gradient(circle, rgba(46,123,239,.35), rgba(46,123,239,0) 70%)',
                    }}
                />
                <div className="relative mx-auto flex max-w-[1100px] flex-col items-start gap-6">
                    <p className="text-on-navy-muted text-[11px] font-semibold tracking-[.24em] uppercase">
                        Plataforma de assinatura eletrônica
                    </p>
                    <h1
                        className="font-extrabold uppercase italic"
                        style={{
                            fontSize: 'clamp(36px, 5vw, 64px)',
                            lineHeight: 0.95,
                            letterSpacing: '-.015em',
                        }}
                    >
                        Assine documentos
                        <br />
                        em <span className="text-primary-bright">minutos</span>
                    </h1>
                    <p className="text-on-navy-secondary max-w-[560px] text-[15px] leading-[1.6]">
                        Envie contratos, propostas e termos para assinatura,
                        acompanhe cada signatário e receba o PDF final com
                        evidências e verificação pública.
                    </p>
                    <div className="flex flex-wrap gap-3">
                        {auth.user ? (
                            <Button asChild size="xl" variant="onNavy">
                                <Link href={dashboard()}>Ir para o painel</Link>
                            </Button>
                        ) : (
                            <>
                                <Button asChild size="xl" variant="onNavy">
                                    <Link href={register()}>
                                        Criar conta grátis
                                    </Link>
                                </Button>
                                <Button asChild size="xl" variant="ghostOnNavy">
                                    <Link href={login()}>Entrar</Link>
                                </Button>
                            </>
                        )}
                    </div>
                    <div className="text-on-navy-secondary flex flex-wrap gap-x-6 gap-y-2 text-[12.5px] font-semibold">
                        {/* Ver a nota de TRUST_BADGES em layouts/auth-layout.tsx: nenhum selo
                            aqui pode prometer aceitação jurídica universal nem assinatura com
                            certificado: os Termos de Uso §3.5 negam a primeira, e a segunda
                            depende de haver certificado configurado. */}
                        <span>✓ Aceite eletrônico com evidências</span>
                        <span>✓ Conforme LGPD</span>
                        <span>✓ Verificação pública por código</span>
                        <span>✓ Trilha de auditoria</span>
                    </div>
                </div>
            </section>

            <section className="mx-auto grid w-full max-w-[1100px] gap-4 px-6 py-12 md:grid-cols-3">
                {FEATURES.map((feature) => (
                    <div
                        key={feature.title}
                        className="border-border bg-card shadow-card rounded-xl border p-5"
                    >
                        <span className="bg-primary-soft text-primary flex size-11 items-center justify-center rounded-xl">
                            <feature.icon className="size-5" />
                        </span>
                        <h2 className="mt-4 text-[15px] font-semibold">
                            {feature.title}
                        </h2>
                        <p className="text-text-secondary mt-1.5 text-[13.5px] leading-[1.55]">
                            {feature.text}
                        </p>
                    </div>
                ))}
            </section>

            <section className="mx-auto w-full max-w-[1100px] px-6 pb-16">
                <div className="border-border bg-card shadow-card flex flex-wrap items-center justify-between gap-4 rounded-xl border p-6">
                    <div>
                        <h2 className="text-[18px] font-bold">
                            Recebeu um documento assinado?
                        </h2>
                        <p className="text-text-secondary mt-1 text-[13.5px]">
                            Confira a autenticidade pelo código de verificação
                            impresso no PDF.
                        </p>
                    </div>
                    <Button asChild variant="outline">
                        <Link href={verifyIndex()}>Verificar documento</Link>
                    </Button>
                </div>
            </section>
        </>
    );
}

Home.layout = { fullBleed: true };
