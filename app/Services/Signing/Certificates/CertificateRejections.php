<?php

namespace App\Services\Signing\Certificates;

use App\Services\Signing\Certificates\Exceptions\ParticipantCertificateException;

/**
 * Códigos do pdftool → mensagem clara para o participante (PT-BR), sem segredo nenhum.
 */
final class CertificateRejections
{
    /**
     * @var array<string, array{0: string, 1: string}> código => [campo, mensagem]
     */
    private const MESSAGES = [
        'wrong_passphrase' => ['password', 'A senha não abre este certificado. Confira a senha e tente de novo.'],
        'invalid_pkcs12' => ['certificate', 'O arquivo não é um certificado digital A1 legível (.pfx ou .p12).'],
        'pkcs12_too_large' => ['certificate', 'O arquivo é grande demais para um certificado digital A1.'],
        'pkcs12_without_key' => ['certificate', 'O arquivo contém o certificado, mas não a chave privada. Exporte o certificado de novo incluindo a chave privada.'],
        'pkcs12_without_certificate' => ['certificate', 'O arquivo não contém o certificado do titular.'],
        'key_certificate_mismatch' => ['certificate', 'A chave privada do arquivo não corresponde ao certificado.'],
        'certificate_is_ca' => ['certificate', 'Este é o certificado de uma autoridade certificadora, não de um titular.'],
        'certificate_expired' => ['certificate', 'Este certificado está vencido. Use um certificado dentro da validade.'],
        'certificate_not_yet_valid' => ['certificate', 'Este certificado ainda não está válido.'],
        'certificate_not_for_signing' => ['certificate', 'Este certificado não permite assinatura digital. Use um certificado de assinatura.'],
        'missing_input' => ['certificate', 'O arquivo do certificado não foi recebido. Tente de novo.'],
        'fingerprint_mismatch' => ['certificate', 'O certificado enviado não é o mesmo que foi conferido e autorizado.'],
        'previous_signature_invalid' => ['certificate', 'O arquivo que receberia a sua assinatura não passou na validação. A equipe foi avisada.'],
        'revision_chain_broken' => ['certificate', 'A assinatura não pôde ser validada junto das anteriores e não foi gravada. Envie o certificado de novo.'],
        'field_name_taken' => ['certificate', 'Já existe uma assinatura sua neste arquivo.'],
        'signing_failed' => ['certificate', 'Não foi possível aplicar a assinatura com este certificado.'],
    ];

    public static function message(string $code): string
    {
        return self::MESSAGES[$code][1] ?? 'Não foi possível conferir o certificado agora. Tente de novo em instantes.';
    }

    public static function fromPdfTool(string $code): ParticipantCertificateException
    {
        if (! isset(self::MESSAGES[$code])) {
            return new ParticipantCertificateException('certificate_check_unavailable', self::message($code), 503);
        }

        [$field, $message] = self::MESSAGES[$code];

        return new ParticipantCertificateException($code, $message, 422, $field);
    }
}
