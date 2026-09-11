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
    options: { policy?: string | null } = {},
): SealPresentation {
    const policy = options.policy ? ` (perfil ${options.policy})` : '';

    switch (status) {
        case 'completed':
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
