<?php

namespace App\Services\Signing\External;

use App\Enums\ExternalSignatureKind;
use App\Integrations\LocalSigner\FakeLocalSigner;

/**
 * Vocabulário da assinatura externa (roadmap T1, T3; arquitetura §2).
 *
 * - Cada meio tem rótulo próprio: A3 por componente local ≠ assinatura por componente
 *   externo ≠ A1 por arquivo ≠ operadora ≠ aceite eletrônico.
 * - O simulador é SEMPRE dito simulado ("{@see FakeLocalSigner::LABEL}") e nunca é A3.
 * - Cadeia: sem âncora fixada e validada, "não verificada". Nunca "ICP-Brasil" como fato —
 *   no máximo o que o certificado DECLARA, com a ressalva de que a cadeia não foi validada.
 * - Revogação: sempre "não verificada" nesta versão (sem LCR/OCSP).
 */
final class ExternalSignatureLabels
{
    public const TEST_CERTIFICATE = 'Certificado de TESTE — não é ICP-Brasil e não tem validade jurídica';

    public const REVOCATION_NOT_CHECKED = 'Revogação do certificado (LCR/OCSP) não verificada.';

    public static function kind(ExternalSignatureKind $kind, bool $simulated): string
    {
        return $kind->label().($simulated ? ' ('.FakeLocalSigner::LABEL.')' : '');
    }

    public static function signed(?string $holder, ?string $issuerCn, ExternalSignatureKind $kind, bool $simulated, bool $test): string
    {
        $who = $holder !== null && $holder !== '' ? $holder : 'titular não identificado';
        $issuer = $issuerCn !== null && $issuerCn !== '' ? $issuerCn : 'emissor não identificado';
        $medium = $kind === ExternalSignatureKind::ParticipantA3
            ? 'com certificado A3 (token ou cartão), por componente local'
            : 'por componente externo, fora da plataforma';

        $label = sprintf('Assinado por %s %s, com certificado emitido por %s', $who, $medium, $issuer);

        if ($simulated) {
            $label .= ' — '.FakeLocalSigner::LABEL;
        }

        if ($test) {
            $label .= ' — '.self::TEST_CERTIFICATE;
        }

        return $label;
    }

    /**
     * @param  array<string, mixed>  $facts  fatos públicos gravados na reserva/pedido
     */
    public static function certificate(array $facts, bool $test): string
    {
        if ($test) {
            return self::TEST_CERTIFICATE;
        }

        if (($facts['self_signed'] ?? false) === true) {
            return 'Certificado autoassinado — nenhuma autoridade certificadora atesta o titular';
        }

        if (($facts['declared_certificate_type'] ?? null) === 'A3') {
            return 'Certificado que declara política ICP-Brasil do tipo A3 (declaração do próprio certificado; cadeia não validada até a ICP-Brasil por esta plataforma)';
        }

        return ($facts['declares_icp_brasil_policy'] ?? false) === true
            ? 'Certificado que declara política ICP-Brasil (declaração do próprio certificado; cadeia não validada até a ICP-Brasil por esta plataforma)'
            : 'Certificado de autoridade certificadora não identificada como ICP-Brasil';
    }

    public static function chain(bool $trusted, int $anchors): string
    {
        if ($anchors === 0) {
            return 'Cadeia de certificação não verificada: nenhuma âncora de confiança configurada nesta plataforma.';
        }

        return $trusted
            ? 'Cadeia de certificação validada até uma âncora de confiança configurada e fixada por impressão digital. Revogação não verificada.'
            : 'Cadeia de certificação não verificada: não foi possível validá-la até as âncoras de confiança configuradas.';
    }

    /**
     * @return list<string>
     */
    public static function notices(): array
    {
        return [
            'A chave do seu certificado não sai do token ou cartão: a plataforma entrega só um resumo do documento e recebe de volta a assinatura.',
            'A assinatura com certificado se soma ao seu aceite eletrônico e não o substitui.',
            'O resumo preparado vale por poucos minutos e só pode ser usado uma vez; se o prazo acabar, prepare de novo.',
            'A plataforma não valida a cadeia até uma raiz da ICP-Brasil nem consulta a revogação do certificado nesta versão.',
        ];
    }

    public static function rejection(string $code): string
    {
        return match ($code) {
            'signature_invalid' => 'A assinatura recebida não confere com o resumo preparado e o certificado anunciado.',
            'certificate_mismatch' => 'O certificado usado para assinar não é o mesmo anunciado na preparação.',
            'digest_mismatch' => 'A assinatura recebida é de outro conteúdo (outra revisão do documento). Prepare de novo.',
            'stale_revision' => 'O documento recebeu outra assinatura depois da preparação. Prepare de novo para assinar a versão atual.',
            'cms_invalid' => 'O pacote de assinatura (CMS/PKCS#7) recebido não é válido.',
            'cms_too_large' => 'O pacote de assinatura recebido é maior que o espaço reservado no documento.',
            'pending_mismatch', 'state_invalid', 'pending_missing' => 'A preparação desta assinatura não está mais íntegra. Prepare de novo.',
            'certificate_invalid' => 'O certificado enviado não é um certificado X.509 legível.',
            'certificate_expired' => 'O certificado está vencido.',
            'certificate_not_yet_valid' => 'O certificado ainda não está válido.',
            'certificate_not_for_signing' => 'O uso do certificado não permite assinar documentos.',
            'certificate_is_ca' => 'O certificado é de uma autoridade certificadora, não de um titular.',
            'unsupported_key_algorithm' => 'O tipo de chave do certificado não é aceito (use RSA ou curva elíptica).',
            'input_too_large' => 'Um dos arquivos enviados é grande demais.',
            'previous_signature_invalid' => 'As assinaturas já presentes no documento não estão íntegras; nenhuma assinatura foi acrescentada.',
            'revision_chain_broken' => 'A assinatura não pôde ser incorporada sem quebrar as revisões anteriores; nada foi gravado.',
            'field_name_taken' => 'Este documento já tem uma assinatura sua.',
            'test_certificate_not_accepted' => 'Certificados de teste não são aceitos neste ambiente.',
            'holder_mismatch' => 'O CPF do certificado não corresponde ao CPF que você informou neste documento.',
            'chain_not_trusted' => 'A cadeia do certificado não foi validada até as âncoras de confiança exigidas por esta plataforma.',
            default => 'A assinatura não pôde ser incorporada ao documento. Prepare de novo.',
        };
    }
}
