<?php

namespace App\Services\Signing\GovBr;

use Illuminate\Support\Str;

/**
 * A decisão sobre uma devolução, a partir do resultado do `pdftool verify-incremental`
 * (P3-GOV, docs/fase-3/gov-br.md §4). Pura: sem banco, sem disco — testável à parte.
 *
 * Aceita SOMENTE se:
 *
 * (a) a revisão reservada é prefixo exato do arquivo devolvido e só UMA revisão foi
 *     acrescentada (atualização incremental);
 * (b) há exatamente uma assinatura nova, íntegra, válida, cobrindo o arquivo inteiro, e a
 *     revisão dela não muda nada além do que uma assinatura traz; as assinaturas anteriores
 *     continuam íntegras; nenhuma certificação que proíba as assinaturas seguintes;
 * (c) com âncoras gov.br configuradas, a cadeia confere com elas (senão, recusa); sem
 *     âncoras, aceita com o rótulo "assinatura digital de terceiro, cadeia não verificada";
 * (d) quando o participante informou CPF neste envelope, o CPF do certificado confere (e,
 *     com `require_holder_cpf`, o certificado PRECISA trazer um CPF legível); sem CPF
 *     informado, o NOME do titular precisa corresponder ao do participante;
 *
 * mais: certificado de teste só onde `accept_test_certificates` permite.
 */
final class GovBrReturnDecision
{
    /**
     * @param  array<string, mixed>  $result  saída do `pdftool verify-incremental`
     */
    public function __construct(
        public readonly bool $accepted,
        public readonly ?string $rejectionCode,
        public readonly ?GovBrSignatureKind $kind,
        public readonly array $result,
    ) {}

    /**
     * @param  array<string, mixed>  $result
     */
    public static function decide(
        array $result,
        bool $anchorsConfigured,
        bool $participantInformedCpf,
        bool $requireHolderCpf,
        bool $acceptTestCertificates,
        ?string $participantName = null,
    ): self {
        $problems = array_values(array_filter((array) ($result['problems'] ?? []), 'is_string'));

        if (($result['accepted'] ?? false) !== true || $problems !== []) {
            return new self(false, $problems[0] ?? 'signature_invalid', null, $result);
        }

        $signature = is_array($result['signature'] ?? null) ? $result['signature'] : null;

        if ($signature === null
            || ($signature['intact'] ?? false) !== true
            || ($signature['valid'] ?? false) !== true
            || ($signature['coverage'] ?? null) !== 'ENTIRE_FILE'
            || ($result['prefix_preserved'] ?? false) !== true
            || (int) ($result['new_signature_count'] ?? 0) !== 1
            || (int) ($result['new_revisions'] ?? 0) !== 1) {
            // Defesa em profundidade: o pdftool disse "aceito", mas os fatos não sustentam.
            return new self(false, 'signature_invalid', null, $result);
        }

        $trusted = ($signature['trusted'] ?? false) === true;

        if ($anchorsConfigured && ! $trusted) {
            return new self(false, 'chain_not_trusted', null, $result);
        }

        if (($signature['test_certificate'] ?? false) === true && ! $acceptTestCertificates) {
            return new self(false, 'test_certificate_not_accepted', null, $result);
        }

        $holder = is_array($signature['holder'] ?? null) ? $signature['holder'] : [];

        if ($participantInformedCpf) {
            $match = (string) ($holder['cpf_match'] ?? 'unknown');

            if ($match === 'mismatch') {
                return new self(false, 'holder_mismatch', null, $result);
            }

            if ($match !== 'match' && $requireHolderCpf) {
                return new self(false, 'holder_cpf_not_found', null, $result);
            }
        } elseif ($participantName !== null) {
            // Sem CPF informado, o NOME do titular precisa corresponder ao do participante
            // (docs/integracoes/gov-br-assinatura.md §6, item 3; revisão adversarial I-3A).
            // Sem isso, qualquer conta gov.br assinaria "pelo" participante.
            $holderName = is_string($holder['name'] ?? null) ? $holder['name'] : null;

            if ($holderName === null || trim($holderName) === '') {
                return new self(false, 'holder_name_not_found', null, $result);
            }

            if (! self::namesCorrespond($participantName, $holderName)) {
                return new self(false, 'holder_name_mismatch', null, $result);
            }
        }

        return new self(true, null, GovBrSignatureKind::for($anchorsConfigured, $trusted), $result);
    }

    /**
     * O nome do certificado corresponde ao do participante? Comparação sem acento e sem caixa,
     * palavra a palavra: o primeiro nome precisa ser igual e TODAS as demais palavras do nome
     * cadastrado (fora partículas "de", "da", "dos"…) precisam estar no nome do certificado — o
     * cadastro costuma abreviar ("Maria Souza"), o certificado traz o nome completo. O marcador
     * `TESTE` dos certificados de teste é ignorado.
     */
    public static function namesCorrespond(string $participantName, string $holderName): bool
    {
        $participant = self::nameTokens($participantName);
        $holder = self::nameTokens($holderName);

        if ($participant === [] || $holder === [] || $participant[0] !== $holder[0]) {
            return false;
        }

        return array_diff($participant, $holder) === [];
    }

    /**
     * @return list<string>
     */
    private static function nameTokens(string $name): array
    {
        $ascii = strtoupper(Str::ascii($name));
        // "NOME:CPF" (formato comum no CN de certificados brasileiros) — só o nome importa.
        $ascii = (string) preg_replace('/:\s*[\d.\-*]+\s*$/', '', $ascii);
        $words = preg_split('/[^A-Z]+/', $ascii, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter(
            $words,
            static fn (string $word): bool => strlen($word) > 1 && ! in_array($word, ['DE', 'DA', 'DO', 'DAS', 'DOS', 'E', 'TESTE'], true),
        ));
    }

    /**
     * Resumo técnico gravável (sem nome, sem CPF, sem assunto do certificado).
     *
     * @return array<string, mixed>
     */
    public function checks(): array
    {
        $signature = is_array($this->result['signature'] ?? null) ? $this->result['signature'] : [];
        $holder = is_array($signature['holder'] ?? null) ? $signature['holder'] : [];
        $changes = is_array($this->result['changes_since_base'] ?? null) ? $this->result['changes_since_base'] : [];

        return [
            'problems' => array_values((array) ($this->result['problems'] ?? [])),
            'decision' => $this->rejectionCode,
            'prefix_preserved' => $this->result['prefix_preserved'] ?? null,
            'new_revisions' => $this->result['new_revisions'] ?? null,
            'new_signature_count' => $this->result['new_signature_count'] ?? null,
            'previous_signatures_ok' => $this->result['previous_signatures_ok'] ?? null,
            'modification_level' => $changes['modification_level'] ?? null,
            'change_findings' => $changes['detail'] ?? null,
            'intact' => $signature['intact'] ?? null,
            'valid' => $signature['valid'] ?? null,
            'coverage' => $signature['coverage'] ?? null,
            'trusted' => $signature['trusted'] ?? null,
            'trust_roots_configured' => $this->result['trust_roots_configured'] ?? 0,
            'is_certification' => $signature['is_certification'] ?? null,
            'docmdp_permission' => $signature['docmdp_permission'] ?? null,
            'subfilter' => $signature['subfilter'] ?? null,
            'cpf_match' => $holder['cpf_match'] ?? null,
            'test_certificate' => $signature['test_certificate'] ?? null,
            'certificate_fingerprint_sha256' => $signature['cert_fingerprint_sha256'] ?? null,
            'revocation' => $this->result['revocation'] ?? 'not_checked',
        ];
    }
}
