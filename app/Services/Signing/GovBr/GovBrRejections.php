<?php

namespace App\Services\Signing\GovBr;

/**
 * Mensagens PT-BR para cada motivo de recusa de uma devolução (P3-GOV). O código é o do
 * `pdftool verify-incremental` ou da decisão do Laravel ({@see GovBrReturnDecision}).
 */
final class GovBrRejections
{
    public const FALLBACK = 'O arquivo devolvido não pôde ser aceito. Baixe de novo a versão para assinar e repita a assinatura no portal.';

    /** @var array<string, string> */
    private const MESSAGES = [
        'base_not_prefix' => 'O arquivo devolvido não é a versão que você baixou com a assinatura acrescentada. Assine no portal exatamente o arquivo baixado nesta página e envie o arquivo que o portal devolver, sem abri-lo e salvá-lo em outro programa. Se o portal regravar o arquivo em vez de acrescentar a assinatura, este caminho não consegue aceitá-lo.',
        'no_new_revision' => 'O arquivo devolvido não traz nenhuma assinatura nova sobre a versão que você baixou.',
        'no_new_signature' => 'O arquivo devolvido não traz nenhuma assinatura nova sobre a versão que você baixou.',
        'unexpected_revision_count' => 'O arquivo devolvido tem alterações além da assinatura (mais de uma atualização depois da versão baixada).',
        'multiple_new_signatures' => 'O arquivo devolvido traz mais de uma assinatura nova. Envie o arquivo com uma única assinatura, a sua.',
        'unexpected_document_timestamp' => 'O arquivo devolvido traz, depois da assinatura, um selo de documento que este caminho não aceita.',
        'signature_not_intact' => 'A assinatura do arquivo devolvido não confere: o conteúdo assinado foi alterado.',
        'signature_invalid' => 'A assinatura do arquivo devolvido não é válida.',
        'signature_not_covering_file' => 'A assinatura não cobre o arquivo inteiro: há conteúdo acrescentado depois dela.',
        'unpermitted_changes' => 'Além da assinatura, o arquivo devolvido altera o documento (conteúdo, páginas, campos ou anotações). Só a assinatura pode ser acrescentada.',
        'previous_signature_broken' => 'Uma assinatura que já estava no documento deixou de conferir no arquivo devolvido.',
        'docmdp_violation' => 'O arquivo devolvido desrespeita as permissões de alteração de uma assinatura anterior.',
        'docmdp_locks_document' => 'A assinatura foi feita de um jeito que proíbe novas assinaturas, e os demais participantes e a operadora ainda precisam assinar depois. Assine sem bloquear o documento.',
        'chain_not_trusted' => 'O certificado da assinatura não pertence à cadeia gov.br configurada nesta plataforma.',
        'holder_mismatch' => 'O CPF do certificado da assinatura não corresponde ao CPF que você informou neste documento.',
        'holder_name_mismatch' => 'O nome do titular do certificado da assinatura não corresponde ao seu nome neste documento. A assinatura precisa ser feita com a sua própria conta gov.br.',
        'holder_name_not_found' => 'Não foi possível ler o nome do titular no certificado da assinatura para conferir com o seu nome neste documento.',
        'already_completed' => 'O arquivo assinado deste documento já foi recebido e conferido.',
        'not_reserved' => 'Não há versão reservada para este documento. Reserve e baixe a versão para assinar.',
        'holder_cpf_not_found' => 'Não foi possível ler um CPF no certificado da assinatura, e este documento exige que ele confira com o CPF que você informou.',
        'test_certificate_not_accepted' => 'Assinaturas feitas com certificado de teste não são aceitas neste ambiente.',
        'invalid_pdf' => 'O arquivo enviado não é um PDF legível.',
        'encrypted_pdf' => 'O arquivo enviado está protegido por senha. Envie o arquivo assinado sem proteção.',
        'missing_input' => 'O arquivo enviado não pôde ser lido. Tente de novo.',
        'not_pdf' => 'O arquivo enviado não é um PDF.',
        'file_too_large' => 'O arquivo enviado é maior que o limite aceito.',
        'upload_failed' => 'O arquivo não pôde ser recebido. Tente de novo.',
    ];

    public static function message(string $code): string
    {
        return self::MESSAGES[$code] ?? self::FALLBACK;
    }

    public static function knows(string $code): bool
    {
        return array_key_exists($code, self::MESSAGES);
    }
}
