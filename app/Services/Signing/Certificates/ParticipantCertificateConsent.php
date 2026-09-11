<?php

namespace App\Services\Signing\Certificates;

use App\Models\Envelope;
use App\Models\Recipient;
use App\Services\Documents\EnvelopeDocuments;
use Illuminate\Support\Carbon;

/**
 * Consentimento ESPECÍFICO para usar o certificado do participante (roadmap §2.12, passo 1:
 * "autorizo o uso deste certificado para assinar este documento").
 *
 * É um texto próprio, separado da declaração de aceite eletrônico, versionado por
 * {@see self::VERSION}. O texto resolvido (com titular, emissor, série, validade, impressão
 * digital e os resumos dos documentos) é gravado em
 * `participant_signature_requests.consent_statement` junto da versão, como o aceite faz.
 *
 * ⚠️ **Escrito pela engenharia de forma conservadora. EXIGE REVISÃO JURÍDICA antes de ligar
 * a flag `participant_a1` em produção** ({@see self::LEGAL_REVIEW_REQUIRED}). Qualquer
 * mudança de palavra exige versão nova.
 */
final class ParticipantCertificateConsent
{
    public const VERSION = 'v1-a1-participante-2026-09-11';

    public const LEGAL_REVIEW_REQUIRED = true;

    public const CHECKBOX_LABEL = 'Li a autorização acima e autorizo o uso deste certificado digital para assinar este documento em meu nome.';

    /**
     * Resumo exibido antes de o certificado ser escolhido (sem dados do certificado).
     */
    public static function summary(): string
    {
        return 'Além do aceite eletrônico, você pode assinar este documento com o seu próprio certificado digital '
            .'A1 (arquivo .pfx ou .p12). O certificado e a senha são usados uma única vez, só para esta '
            .'assinatura, e descartados logo em seguida: a plataforma não guarda cópia deles. A assinatura é '
            .'acrescentada ao arquivo depois que todos os participantes concluírem o aceite.';
    }

    /**
     * Texto integral da autorização, resolvido para o certificado conferido.
     */
    public static function statement(Envelope $envelope, Recipient $recipient, CertificateInspection $certificate): string
    {
        $lines = [
            sprintf('Autorização de uso de certificado digital — versão %s', self::VERSION),
            '',
            sprintf(
                'Eu, %s, autorizo a %s a usar o certificado digital identificado abaixo, uma única vez, para '
                .'acrescentar a minha assinatura digital (perfil PAdES-B-B) ao(s) documento(s) deste envelope.',
                $recipient->name,
                (string) config('app.name', 'AssinaVelox'),
            ),
            '',
            sprintf('Envelope: "%s" — código de verificação %s', $envelope->title, $envelope->formatted_verification_code ?? '—'),
        ];

        foreach (EnvelopeDocuments::sent($envelope) as $index => $row) {
            $lines[] = sprintf(
                'Documento %d: "%s" — SHA-256 da versão apresentada %s',
                $index + 1,
                $row['document']->name,
                $row['version']->sha256,
            );
        }

        $lines = [
            ...$lines,
            sprintf('Titular declarado no certificado: %s', $certificate->holderName ?? $certificate->subjectCn ?? '—'),
            sprintf('Emitido por: %s', $certificate->issuerCn ?? $certificate->issuer),
            sprintf('Número de série: %s', $certificate->serial),
            sprintf('Validade: %s a %s', self::date($certificate->notBefore), self::date($certificate->notAfter)),
            sprintf('Impressão digital SHA-256: %s', $certificate->fingerprint),
            '',
            'Declaro que sou o(a) titular deste certificado, ou pessoa autorizada a usá-lo, e que a senha informada é de meu conhecimento.',
            'Estou ciente de que:',
            '1. esta assinatura digital é acrescentada ao arquivo como uma revisão incremental, depois de consolidados '
            .'os campos preenchidos e o relatório de evidências; ela não substitui nem altera o meu aceite eletrônico já registrado;',
            '2. o arquivo do certificado e a senha são usados somente para esta assinatura e descartados em seguida; a '
            .'plataforma não guarda cópia deles;',
            '3. a plataforma confere a senha, a validade e o uso do certificado, mas não valida a cadeia de certificação até '
            .'uma raiz da ICP-Brasil nem consulta a revogação (LCR/OCSP); o que foi e o que não foi verificado aparece na página de verificação;',
            '4. se a assinatura não puder ser aplicada dentro do prazo, o documento é concluído sem ela, e o meu aceite eletrônico continua válido.',
        ];

        if ($certificate->isTest) {
            $lines[] = '5. este é um certificado de TESTE: não é ICP-Brasil, não tem validade jurídica e serve apenas para ambiente de testes.';
        }

        return implode("\n", $lines);
    }

    private static function date(?string $iso): string
    {
        if ($iso === null || $iso === '') {
            return '—';
        }

        try {
            return Carbon::parse($iso)->utc()->format('d/m/Y H:i').' UTC';
        } catch (\Throwable) {
            return $iso;
        }
    }
}
