<?php

namespace App\Services\Signing\External;

use App\Enums\CertificateEnvironment;
use App\Enums\ExternalSignatureKind;
use App\Enums\SignatureStatus;
use App\Integrations\LocalSigner\FakeLocalSigner;
use App\Models\CertificateReference;
use App\Models\Envelope;
use App\Models\ParticipantSignature;
use App\Models\ParticipantSignatureRequest;
use App\Models\VerificationRecord;
use App\Services\Signing\Certificates\IncrementalChain;
use App\Services\Verification\SignatureNarrative;

/**
 * Linguagem para `signature_status = participant_a3 | participant_external` (Fase 3 §3.4), no
 * MESMO formato de {@see SignatureNarrative::for()}.
 *
 * Diz o que aconteceu, e só isso (T1/T2/T3): a assinatura foi feita FORA da plataforma sobre um
 * resumo preparado por ela; o simulador é dito simulado ("nenhum token foi usado"); A3 só com
 * componente real; perfil PAdES-B-B; cadeia "não verificada" sem âncora; ICP-Brasil nunca
 * afirmada; revogação não verificada. Integridade de uma cadeia de revisões vem da análise
 * gravada na conclusão (`validation_result.incremental_chain`, {@see IncrementalChain}).
 */
final class ExternalSignatureNarrative
{
    /**
     * @return array{state: string, status: string, label: string, statement: string, profile: string|null, certificate: array<string, mixed>|null, validation: array<string, mixed>}
     */
    public static function for(Envelope $envelope, VerificationRecord $record): array
    {
        $status = $record->signature_status ?? SignatureStatus::ParticipantExternal;
        $facts = self::facts($envelope);
        $operator = $record->certificate_reference_id !== null ? $record->certificateReference : null;

        return [
            'state' => $status->value,
            'status' => $status->value,
            'label' => self::label($status, $facts['simulated'] > 0),
            'statement' => self::statement($record, $facts, $operator),
            'profile' => $record->signature_profile,
            'certificate' => self::operatorCertificate($operator, $record->signature_profile),
            'validation' => self::validation($record),
        ];
    }

    public static function statusLabel(Envelope $envelope, VerificationRecord $record): string
    {
        $simulated = self::facts($envelope)['simulated'] > 0;

        return match ($record->signature_status) {
            SignatureStatus::ParticipantA3 => 'Concluído · assinado com certificado A3 de participante (componente local)',
            default => $simulated
                ? 'Concluído · assinado por participante com componente externo ('.FakeLocalSigner::LABEL.')'
                : 'Concluído · assinado por participante com componente externo',
        };
    }

    private static function label(SignatureStatus $status, bool $simulated): string
    {
        return $status === SignatureStatus::ParticipantA3
            ? 'Assinatura com certificado A3 de participante (componente local)'
            : 'Assinatura de participante por componente externo'.($simulated ? ' ('.FakeLocalSigner::LABEL.')' : '');
    }

    /**
     * @return array{external: int, a3: int, simulated: int, a1: int, test: int}
     */
    private static function facts(Envelope $envelope): array
    {
        $rows = ParticipantSignature::withoutOrganizationScope()
            ->where('envelope_id', $envelope->getKey())
            ->get(['id', 'participant_signature_request_id', 'signature_status', 'is_simulated']);

        $external = $rows->filter(fn (ParticipantSignature $row): bool => $row->getAttribute('signature_status') !== null);
        $requests = static fn ($collection): int => $collection->pluck('participant_signature_request_id')->unique()->count();

        return [
            'external' => $requests($external),
            'a3' => $requests($external->filter(fn (ParticipantSignature $row): bool => $row->getAttribute('signature_status') === ExternalSignatureKind::ParticipantA3->value)),
            'simulated' => $requests($external->filter(fn (ParticipantSignature $row): bool => (bool) $row->getAttribute('is_simulated'))),
            'a1' => $requests($rows->filter(fn (ParticipantSignature $row): bool => $row->getAttribute('signature_status') === null)),
            'test' => ParticipantSignatureRequest::withoutOrganizationScope()
                ->where('envelope_id', $envelope->getKey())
                ->where('is_test_certificate', true)
                ->whereIn('id', $rows->pluck('participant_signature_request_id')->unique()->all())
                ->count(),
        ];
    }

    /**
     * @param  array{external: int, a3: int, simulated: int, a1: int, test: int}  $facts
     */
    private static function statement(VerificationRecord $record, array $facts, ?CertificateReference $operator): string
    {
        $count = max(1, $facts['external']);
        $profile = $record->signature_profile !== null && $record->signature_profile !== '' ? $record->signature_profile : 'PAdES-B-B';

        $statement = sprintf(
            'O arquivo final recebeu %d %s no perfil %s feita%s FORA da plataforma: o participante assinou, com um '
            .'componente no próprio computador, um resumo preparado pela plataforma, que depois incorporou a assinatura '
            .'ao arquivo como revisão incremental, depois da consolidação dos campos e do relatório de evidências. A '
            .'chave do certificado não passou pela plataforma. Cada assinatura identifica o titular do certificado usado '
            .'e se soma ao aceite eletrônico registrado para cada participante, sem substituí-lo.',
            $count,
            $count === 1 ? 'assinatura criptográfica de participante' : 'assinaturas criptográficas de participantes',
            $profile,
            $count === 1 ? '' : 's',
        );

        if ($facts['a3'] > 0) {
            $statement .= sprintf(' %d %s com certificado A3 (token ou cartão), segundo o componente local e a política declarada no certificado.', $facts['a3'], $facts['a3'] === 1 ? 'foi feita' : 'foram feitas');
        }

        if ($facts['simulated'] > 0) {
            $statement .= sprintf(
                ' Atenção: %d %s pelo SIMULADOR de componente local, em ambiente de teste — %s, sem valor para uso real.',
                $facts['simulated'],
                $facts['simulated'] === 1 ? 'assinatura foi produzida' : 'assinaturas foram produzidas',
                FakeLocalSigner::LABEL,
            );
        }

        if ($facts['a1'] > 0) {
            $statement .= sprintf(' O arquivo também recebeu %d %s com o certificado A1 (arquivo) do próprio participante.', $facts['a1'], $facts['a1'] === 1 ? 'assinatura' : 'assinaturas');
        }

        $statement .= $operator !== null
            ? sprintf(
                ' Por último, a operadora %s aplicou a sua própria assinatura criptográfica, com certificado de titularidade '
                .'da operadora, que lacra o arquivo; ela não é a assinatura pessoal de nenhum participante.',
                (string) config('app.name', 'AssinaVelox'),
            )
            : ' A operadora não aplicou assinatura própria a este arquivo.';

        if ($facts['test'] > 0) {
            $statement .= ' Atenção: ao menos um certificado de participante é de AMBIENTE DE TESTE, sem valor para uso real. '
                .'Certificado de teste não é ICP-Brasil.';
        }

        if ($operator?->environment === CertificateEnvironment::Test) {
            $statement .= ' A assinatura da operadora foi aplicada com um certificado de AMBIENTE DE TESTE, sem valor para uso real.';
        }

        return $statement.' A plataforma não validou a cadeia até uma raiz da ICP-Brasil e não consultou a revogação dos certificados.';
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
                'integrity_label' => 'As assinaturas foram aplicadas, mas nenhum resultado de validação da cadeia foi registrado na '
                    .'conclusão. A integridade não pode ser afirmada a partir desta página.',
                'chain_trust' => 'not_verified',
                'chain_trust_label' => 'Confiança da cadeia de certificação não verificada.',
                'revocation' => 'not_checked',
                'revocation_label' => ExternalSignatureLabels::REVOCATION_NOT_CHECKED,
                'notes' => [],
            ];
        }

        $signatureCount = (int) ($result['signature_count'] ?? 0);
        $sound = ($chain['ok'] ?? false) === true && ($result['all_intact'] ?? false) === true && ($result['all_valid'] ?? false) === true;
        $trustRoots = (int) ($result['trust_roots_configured'] ?? 0);
        $trusted = ($result['all_trusted'] ?? false) === true && $trustRoots > 0;
        $revocation = is_string($result['revocation'] ?? null) ? (string) $result['revocation'] : 'not_checked';

        return [
            'available' => true,
            'summary' => implode('; ', [
                $sound
                    ? sprintf('%d %s na conclusão, cada revisão posterior só acrescentou assinatura', $signatureCount, $signatureCount === 1 ? 'assinatura íntegra e válida' : 'assinaturas íntegras e válidas')
                    : 'integridade NÃO confirmada',
                $trusted ? 'cadeia de certificação validada até uma âncora configurada' : 'cadeia de certificação não verificada por esta plataforma',
                $revocation === 'not_checked' ? 'revogação não verificada' : 'revogação: '.$revocation,
            ]),
            'validated_at' => $record->validated_at?->toIso8601String(),
            'signature_count' => $signatureCount,
            'integrity' => $sound ? 'intact' : ($signatureCount === 0 ? 'unknown' : 'broken'),
            'integrity_label' => $sound
                ? 'Íntegro na conclusão: todas as assinaturas estão íntegras e válidas, a mais recente cobre o arquivo inteiro e '
                    .'cada revisão posterior só acrescentou uma assinatura (alteração permitida).'
                : 'A validação NÃO confirmou a integridade da sequência de assinaturas. Trate este resultado como inconclusivo e '
                    .'confira o arquivo por conta própria.',
            'chain_trust' => $trusted ? 'trusted' : 'not_verified',
            'chain_trust_label' => $trusted
                ? 'Cadeia de certificação validada até uma âncora de confiança configurada nesta plataforma.'
                : 'Confiança da cadeia de certificação NÃO verificada por esta plataforma. Quem recebe o arquivo pode validá-lo no '
                    .'próprio leitor de PDF.',
            'revocation' => $revocation,
            'revocation_label' => $revocation === 'not_checked'
                ? 'Revogação dos certificados NÃO verificada. Não há consulta a LCR nem a OCSP nesta versão.'
                : 'Revogação dos certificados: '.$revocation.'.',
            'notes' => [
                'Este resultado é o registrado no momento da conclusão do envelope; ele não é recalculado a cada visita a esta página.',
                'Esta página não é um certificado emitido por autoridade certificadora, e um resumo SHA-256 não é uma assinatura.',
            ],
        ];
    }
}
