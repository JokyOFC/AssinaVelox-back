import type { EnvelopeStatus, SignatureStatus } from '@/types/enums';

/**
 * Selo de estado da verificação pública.
 *
 * Regras que este módulo existe para não deixar escapar (arquitetura §2 e
 * declaracao-de-aceite.md §7):
 *
 * 1. **Verde só quando concluído.** Em andamento é âmbar; recusado é vermelho;
 *    expirado e cancelado são cinza.
 * 2. O texto do selo concluído depende de `signature_status`. Com certificado
 *    da operadora: "Concluído e assinado digitalmente pela operadora". Sem
 *    certificado: "Concluído com aceite eletrônico e evidências" — **nunca**
 *    "assinado digitalmente", que é o que o mock original dizia.
 */
export type SealTone = 'success' | 'warning' | 'info' | 'danger' | 'neutral';

export interface SealPresentation {
    tone: SealTone;
    title: string;
    description: string;
    /** Ícone semântico esperado pela UI. */
    icon: 'shield-check' | 'shield' | 'clock' | 'x' | 'calendar' | 'ban';
}

export function verificationSeal(
    status: EnvelopeStatus,
    signatureStatus: SignatureStatus | null | undefined,
    options: {
        policy?: string | null;
        /** Fase 3 §3.4: alguma assinatura externa veio do SIMULADOR. */
        simulated?: boolean;
        /**
         * Fase 3 §3.5: algum participante devolveu o arquivo assinado no portal (com mais de um
         * meio externo o `signature_status` é o genérico `participant_external`).
         */
        portal?: boolean;
        /** Fase 3 §3.4: a operadora assinou por último (há certificado da operadora). */
        operator?: boolean;
    } = {},
): SealPresentation {
    const policy = options.policy ? ` (perfil ${options.policy})` : '';

    switch (status) {
        case 'completed':
            // Fase 3 §3.5: documento devolvido depois de assinado no portal gov.br. "gov.br" só
            // com a cadeia validada até a âncora fixada; sem isso, "assinatura de terceiro".
            if (
                signatureStatus === 'participant_govbr' ||
                signatureStatus === 'participant_external_unverified'
            ) {
                const trusted = signatureStatus === 'participant_govbr';
                const operatorText = options.operator
                    ? ' Por último, a operadora aplicou a sua própria assinatura, que não é a assinatura pessoal de nenhum participante.'
                    : '';

                return {
                    tone: 'success',
                    icon: 'shield-check',
                    title: trusted
                        ? 'Concluído · assinatura gov.br (avançada) de participante'
                        : 'Concluído · documento devolvido com assinatura de terceiro (cadeia não verificada)',
                    description: `O participante baixou a versão reservada pela plataforma${trusted ? ', assinou-a no portal gov.br e devolveu o arquivo' : ' e a devolveu com uma assinatura digital acrescentada'}${policy}; a plataforma conferiu que ele começa pela versão entregue e só acrescenta uma assinatura, sem que a chave passasse por ela. ${trusted ? 'A cadeia do certificado foi conferida contra a âncora gov.br fixada nesta plataforma; não é assinatura com certificado ICP-Brasil.' : 'A cadeia do certificado não foi verificada: não se afirma que seja assinatura gov.br.'} A assinatura se soma ao aceite eletrônico de cada participante, sem substituí-lo.${operatorText} A revogação não foi consultada.`,
                };
            }

            // Fase 3 §3.4: assinatura feita FORA da plataforma. A3 só quando o servidor diz A3
            // (componente real + política declarada); o simulador é sempre dito simulado.
            if (
                signatureStatus === 'participant_a3' ||
                signatureStatus === 'participant_external'
            ) {
                const a3 = signatureStatus === 'participant_a3';
                const operatorText = options.operator
                    ? ' Por último, a operadora aplicou a sua própria assinatura, que não é a assinatura pessoal de nenhum participante.'
                    : '';
                const simulatedText = options.simulated
                    ? ' Atenção: ao menos uma assinatura foi produzida pelo simulador de componente local — simulado, nenhum token foi usado — e não tem valor para uso real.'
                    : '';

                return {
                    tone: 'success',
                    icon: 'shield-check',
                    title: a3
                        ? 'Concluído e assinado com certificado A3 de participante'
                        : options.simulated
                          ? 'Concluído · assinatura de participante por componente externo (simulada)'
                          : 'Concluído e assinado por participante com componente externo',
                    description: `O arquivo final recebeu assinatura criptográfica feita pelo participante fora da plataforma${policy}${a3 ? ', com certificado A3 (token ou cartão) por um componente local' : ', por um componente externo'}: a plataforma preparou um resumo, o componente assinou e a assinatura foi incorporada ao arquivo, sem que a chave passasse pela plataforma. Ela identifica o titular do certificado e se soma ao aceite eletrônico de cada participante, sem substituí-lo.${operatorText}${simulatedText}${options.portal ? ' Além disso, um participante devolveu a versão reservada pela plataforma com uma assinatura digital acrescentada; sem a cadeia validada até a âncora fixada, não se afirma que seja assinatura gov.br.' : ''} A cadeia não foi validada até uma raiz da ICP-Brasil e a revogação não foi consultada.`,
                };
            }

            // Fase 2 §2.12: assinatura com o certificado do PRÓPRIO participante — com ou
            // sem a da operadora por último. Nunca "ICP-Brasil"; o aceite continua existindo.
            if (signatureStatus === 'participants_a1') {
                return {
                    tone: 'success',
                    icon: 'shield-check',
                    title: 'Concluído e assinado com certificado dos participantes',
                    description: `O arquivo final recebeu assinaturas criptográficas feitas com o certificado digital do próprio participante${policy}, acrescentadas depois da consolidação e do relatório de evidências. Elas identificam o titular de cada certificado e se somam ao aceite eletrônico de cada participante, sem substituí-lo. A operadora não aplicou assinatura própria.`,
                };
            }

            if (signatureStatus === 'mixed') {
                return {
                    tone: 'success',
                    icon: 'shield-check',
                    title: 'Concluído e assinado com certificados dos participantes e da operadora',
                    description: `O arquivo final recebeu assinaturas criptográficas feitas com o certificado digital do próprio participante e, por último, a assinatura da AssinaVelox com certificado de sua própria titularidade${policy}. A assinatura da operadora não é a assinatura pessoal de nenhum participante; o aceite eletrônico de cada um continua registrado com as evidências.`,
                };
            }

            return signatureStatus === 'company_a1'
                ? {
                      tone: 'success',
                      icon: 'shield-check',
                      title: 'Concluído e assinado digitalmente pela operadora',
                      description: `O arquivo final foi lacrado pela AssinaVelox com certificado digital de sua própria titularidade${policy}. Essa assinatura identifica a operadora e permite detectar alterações posteriores no arquivo; ela não é a assinatura pessoal de nenhum dos participantes nem um certificado emitido em nome deles.`,
                  }
                : {
                      tone: 'success',
                      icon: 'shield-check',
                      title: 'Concluído com aceite eletrônico e evidências',
                      description:
                          'Este documento foi concluído sem assinatura criptográfica. A manifestação de vontade de cada participante está registrada com data, IP, navegador, autenticação por código enviado ao e-mail e a versão exata do documento. A integridade do arquivo é conferida pelo resumo SHA-256 final publicado abaixo.',
                  };

        /*
         * Ramo defensivo: hoje o servidor **não** publica `finalizing` — ele o reporta
         * como `in_progress`, de propósito, porque o estágio interno do pipeline não é
         * assunto de quem consulta de fora (docs/verificacao-publica.md §1). Fica aqui
         * para o caso de o contrato mudar, e é por isso que o texto de `in_progress`
         * abaixo não pode afirmar que faltam participantes.
         */
        case 'finalizing':
            return {
                tone: 'info',
                icon: 'clock',
                title: 'Em andamento · finalizando',
                description:
                    'Todos os aceites exigidos foram registrados e o arquivo final está sendo preparado. Os resumos serão publicados na conclusão.',
            };

        case 'refused':
            return {
                tone: 'danger',
                icon: 'x',
                title: 'Recusado',
                description:
                    'Um participante recusou a assinatura e o documento foi encerrado sem conclusão.',
            };

        case 'expired':
            return {
                tone: 'neutral',
                icon: 'calendar',
                title: 'Expirado',
                description:
                    'O prazo de assinatura terminou antes de todos os aceites. O documento não foi concluído.',
            };

        case 'canceled':
            return {
                tone: 'neutral',
                icon: 'ban',
                title: 'Cancelado',
                description:
                    'A organização remetente cancelou este documento antes da conclusão.',
            };

        default:
            return {
                tone: 'warning',
                icon: 'clock',
                title: 'Em andamento',
                /*
                 * Não diz "ainda há participantes pendentes": um envelope em `finalizing`
                 * chega aqui como `in_progress` com TODO mundo já assinado, e a frase
                 * seria falsa. A lista de participantes logo abaixo mostra quem já
                 * assinou e quem não — é lá que o detalhe existe, e corretamente.
                 */
                description:
                    'O documento ainda não foi concluído. Os resumos do arquivo final serão publicados na conclusão.',
            };
    }
}

export const SEAL_TONE_CLASSES: Record<SealTone, string> = {
    success: 'border-success-border bg-success-bg text-success',
    warning: 'border-warning-border bg-warning-bg text-warning',
    info: 'border-primary-soft-border bg-primary-soft text-primary',
    danger: 'border-danger-border bg-danger-bg text-danger',
    neutral: 'border-neutral-border bg-neutral-bg text-text-secondary',
};
