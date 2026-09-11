<?php

namespace App\Services\Signing\Certificates;

use App\Enums\CertificateEnvironment;
use App\Enums\SignatureStatus;
use App\Models\CertificateReference;
use App\Models\Envelope;
use App\Models\VerificationRecord;
use App\Services\Verification\SignatureNarrative;

/**
 * Linguagem para `signature_status = participants_a1 | mixed` (Fase 2 §2.12), no MESMO
 * formato de {@see SignatureNarrative::for()} — as duas páginas continuam lendo um só lugar.
 *
 * Vocabulário (T1): assinatura com o certificado do próprio participante ≠ assinatura da
 * operadora ≠ aceite eletrônico. O texto diz as três coisas separadas, nunca "assinatura
 * digital" genérica; certificado de teste é dito teste; ICP-Brasil nunca é afirmado.
 *
 * Integridade de uma CADEIA de revisões não é `all_covering` (falso por construção: cada
 * assinatura anterior cobre a sua revisão). Vale a análise gravada pela finalização em
 * `validation_result.incremental_chain` ({@see IncrementalChain}): todas íntegras e válidas,
 * a última cobrindo o arquivo inteiro, as anteriores só com alterações permitidas depois.
 */
final class ParticipantSignatureNarrative
{
    /**
     * @return array{state: string, status: string, label: string, statement: string, profile: string|null, certificate: array<string, mixed>|null, validation: array<string, mixed>}
     */
    public static function for(Envelope $envelope, VerificationRecord $record): array
    {
        $status = $record->signature_status;
        $mixed = $status === SignatureStatus::Mixed;
        $operatorCertificate = $mixed ? $record->certificateReference : null;

        return [
            'state' => $status->value,
            'status' => $status->value,
            'label' => $mixed
                ? 'Assinaturas com certificado dos participantes e da operadora'
                : 'Assinaturas com certificado dos participantes',
            'statement' => self::statement($envelope, $record, $operatorCertificate),
            'profile' => $record->signature_profile,
            'certificate' => self::operatorCertificate($operatorCertificate, $record->signature_profile),
            'validation' => self::validation($record),
        ];
    }

    private static function statement(Envelope $envelope, VerificationRecord $record, ?CertificateReference $operatorCertificate): string
    {
        $count = max(1, ParticipantSignatureViews::appliedCount($envelope));
        $profile = $record->signature_profile !== null && $record->signature_profile !== '' ? $record->signature_profile : 'PAdES-B-B';

        $statement = sprintf(
            'O arquivo final recebeu %d %s no perfil %s %s com o certificado digital A1 do próprio participante, '
            .'acrescentada%s ao arquivo como %s depois da consolidação dos campos e do relatório de '
            .'evidências. Cada uma identifica o titular do certificado usado e se soma ao aceite eletrônico registrado '
            .'para cada participante, sem substituí-lo.',
            $count,
            $count === 1 ? 'assinatura criptográfica' : 'assinaturas criptográficas',
            $profile,
            $count === 1 ? 'aplicada' : 'aplicadas',
            $count === 1 ? '' : 's',
            $count === 1 ? 'revisão incremental' : 'revisões incrementais',
        );

        if ($record->signature_status === SignatureStatus::Mixed) {
            $statement .= sprintf(
                ' Por último, a operadora %s aplicou a sua própria assinatura criptográfica, com certificado de '
                .'titularidade da operadora, que lacra o arquivo e permite detectar alterações posteriores; ela não é a '
                .'assinatura pessoal de nenhum participante.',
                (string) config('app.name', 'AssinaVelox'),
            );
        } else {
            $statement .= ' A operadora não aplicou assinatura própria a este arquivo.';
        }

        if (ParticipantSignatureViews::anyTestCertificate($envelope)) {
            $statement .= ' Atenção: ao menos um certificado de participante é de AMBIENTE DE TESTE, sem valor para uso '
                .'real. Certificado de teste não é ICP-Brasil.';
        }

        if ($operatorCertificate?->environment === CertificateEnvironment::Test) {
            $statement .= ' A assinatura da operadora foi aplicada com um certificado de AMBIENTE DE TESTE, sem valor '
                .'para uso real. Este certificado não é ICP-Brasil.';
        }

        return $statement;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function operatorCertificate(?CertificateReference $certificate, ?string $profile): ?array
    {
        if ($certificate === null) {
            return null;
        }

        $test = $certificate->environment === CertificateEnvironment::Test;

        return [
            'subject_cn' => SignatureNarrative::commonName($certificate->subject),
            'subject' => $certificate->subject,
            'issuer_cn' => SignatureNarrative::commonName($certificate->issuer),
            'issuer' => $certificate->issuer,
            'serial' => $certificate->serial_number,
            'valid_from' => $certificate->not_before?->toIso8601String(),
            'valid_to' => $certificate->not_after?->toIso8601String(),
            'policy' => $profile ?? 'PAdES',
            'environment' => $certificate->environment->value,
            'environment_label' => $test ? 'Certificado de teste' : 'Certificado de produção',
            'is_test' => $test,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function validation(VerificationRecord $record): array
    {
        $payload = $record->validation_result;
        $result = is_array($payload) && is_array($payload['result'] ?? null) ? $payload['result'] : null;
        $chain = is_array($payload) && is_array($payload['incremental_chain'] ?? null) ? $payload['incremental_chain'] : null;

        if ($result === null || $chain === null) {
            return [
                'available' => false,
                'summary' => null,
                'validated_at' => null,
                'signature_count' => 0,
                'integrity' => 'unknown',
                'integrity_label' => 'As assinaturas foram aplicadas, mas nenhum resultado de validação da cadeia foi '
                    .'registrado na conclusão. A integridade não pode ser afirmada a partir desta página.',
                'chain_trust' => 'not_verified',
                'chain_trust_label' => 'Confiança da cadeia de certificação não verificada.',
                'revocation' => 'not_checked',
                'revocation_label' => 'Revogação do certificado não verificada.',
                'notes' => [],
            ];
        }

        $signatureCount = (int) ($result['signature_count'] ?? 0);
        $sound = ($chain['ok'] ?? false) === true
            && ($result['all_intact'] ?? false) === true
            && ($result['all_valid'] ?? false) === true;
        $integrity = $sound ? 'intact' : ($signatureCount === 0 ? 'unknown' : 'broken');
        $trustRoots = (int) ($result['trust_roots_configured'] ?? 0);
        $trusted = ($result['all_trusted'] ?? false) === true && $trustRoots > 0;
        $revocation = is_string($result['revocation'] ?? null) ? (string) $result['revocation'] : 'not_checked';

        return [
            'available' => true,
            'summary' => implode('; ', [
                $sound
                    ? ($signatureCount === 1
                        ? '1 assinatura íntegra e válida na conclusão, cada revisão posterior só acrescentou assinatura'
                        : sprintf('%d assinaturas íntegras e válidas na conclusão, cada revisão posterior só acrescentou assinatura', $signatureCount))
                    : 'integridade NÃO confirmada',
                $trusted
                    ? 'cadeia de certificação validada até uma raiz de confiança configurada'
                    : 'cadeia de certificação não verificada por esta plataforma',
                $revocation === 'not_checked' ? 'revogação não verificada' : 'revogação: '.$revocation,
            ]),
            'validated_at' => $record->validated_at?->toIso8601String(),
            'signature_count' => $signatureCount,
            'integrity' => $integrity,
            'integrity_label' => $sound
                ? 'Íntegro na conclusão: todas as assinaturas estão íntegras e válidas, a mais recente cobre o arquivo '
                    .'inteiro e cada revisão posterior só acrescentou uma assinatura (alteração permitida).'
                : 'A validação NÃO confirmou a integridade da sequência de assinaturas. Trate este resultado como '
                    .'inconclusivo e confira o arquivo por conta própria.',
            'chain_trust' => $trusted ? 'trusted' : 'not_verified',
            'chain_trust_label' => $trusted
                ? 'Cadeia de certificação validada até uma raiz de confiança configurada nesta plataforma.'
                : 'Confiança da cadeia de certificação NÃO verificada por esta plataforma. Quem recebe o arquivo pode '
                    .'validá-lo no próprio leitor de PDF.',
            'revocation' => $revocation,
            'revocation_label' => $revocation === 'not_checked'
                ? 'Revogação dos certificados NÃO verificada. Não há consulta a LCR nem a OCSP nesta versão.'
                : 'Revogação dos certificados: '.$revocation.'.',
            'notes' => [
                'Este resultado é o registrado no momento da conclusão do envelope; ele não é recalculado a cada visita '
                .'a esta página.',
                'Esta página não é um certificado emitido por autoridade certificadora, e um resumo SHA-256 não é uma '
                .'assinatura.',
            ],
        ];
    }
}
