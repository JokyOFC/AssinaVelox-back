<?php

namespace App\Services\Verification;

use App\Enums\CertificateEnvironment;
use App\Enums\EnvelopeStatus;
use App\Enums\SignatureStatus;
use App\Models\CertificateReference;
use App\Models\Envelope;
use App\Models\VerificationRecord;
use App\Services\Pdf\Dto\ValidationResult;

/**
 * Linguagem honesta para a situação da assinatura (arquitetura §2).
 *
 * Três estados possíveis, e **nenhum outro** pode ser afirmado:
 *
 * 1. o envelope ainda não concluiu → não existe arquivo final nem assinatura;
 * 2. concluiu com `signature_status = none` → *aceite eletrônico com evidências*, sem
 *    assinatura criptográfica, sem certificado, sem "assinado digitalmente";
 * 3. concluiu com `signature_status = company_a1` → PAdES aplicado pela **operadora** com
 *    certificado de titularidade dela, que não é assinatura pessoal de participante algum.
 *
 * O resultado técnico da validação é montado a partir de `verification_records.validation_result`
 * — o resumo de {@see ValidationResult::summary()} gravado pela
 * finalização. Três garantias valem aqui:
 *
 * - integridade só é afirmada quando a validação diz `all_intact` **e** que a assinatura
 *   cobre o arquivo inteiro (`all_covering`, isto é, `coverage = ENTIRE_FILE` em todas as
 *   assinaturas) **e** que nenhuma política DocMDP foi violada. Conteúdo acrescentado depois
 *   da revisão assinada mantém `intact = true`: sem os outros dois, "nada mudou depois da
 *   assinatura" seria falso;
 * - confiança da cadeia só é afirmada quando há raiz de confiança configurada **e** a
 *   ferramenta marcou a assinatura como `trusted`;
 * - revogação é declarada **não verificada** sempre que o resultado não disser o contrário.
 *   A `pdftool validate` devolve `revocation = not_checked` na Fase 1: é isso que a página diz.
 *
 * Um resultado ausente, incompleto ou inconclusivo **nunca** vira sucesso.
 */
final class SignatureNarrative
{
    public const STATE_PENDING = 'pending';

    public const STATE_NONE = 'none';

    public const STATE_COMPANY_A1 = 'company_a1';

    /**
     * Situação da assinatura pronta para as props (pública e de evidências).
     *
     * @return array{
     *     state: string,
     *     status: string,
     *     label: string,
     *     statement: string,
     *     profile: string|null,
     *     certificate: array<string, mixed>|null,
     *     validation: array<string, mixed>
     * }
     */
    public static function for(Envelope $envelope, ?VerificationRecord $record): array
    {
        $concluded = $envelope->status === EnvelopeStatus::Completed && $record !== null;
        $status = $record->signature_status ?? SignatureStatus::None;

        if (! $concluded) {
            return [
                'state' => self::STATE_PENDING,
                'status' => SignatureStatus::None->value,
                'label' => 'Sem arquivo final',
                'statement' => self::pendingStatement($envelope),
                'profile' => null,
                'certificate' => null,
                'validation' => self::unavailableValidation(
                    'O arquivo final ainda não foi gerado, portanto não há o que validar.',
                ),
            ];
        }

        // A saída antecipada de `! $concluded` já garantiu `$record !== null` aqui.
        if ($status === SignatureStatus::CompanyA1) {
            $certificate = $record->certificateReference;

            return [
                'state' => self::STATE_COMPANY_A1,
                'status' => SignatureStatus::CompanyA1->value,
                'label' => 'Assinatura criptográfica da operadora',
                'statement' => self::companyStatement($record->signature_profile, $certificate),
                'profile' => $record->signature_profile,
                'certificate' => self::certificate($certificate, $record->signature_profile),
                'validation' => self::validation($record),
            ];
        }

        return [
            'state' => self::STATE_NONE,
            'status' => SignatureStatus::None->value,
            'label' => 'Aceite eletrônico com evidências',
            'statement' => self::noneStatement(),
            'profile' => null,
            'certificate' => null,
            'validation' => self::unavailableValidation(
                'Não há assinatura criptográfica neste arquivo; não há validação de assinatura a apresentar.',
            ),
        ];
    }

    /**
     * Rótulo do estado do envelope para a página pública.
     *
     * `EnvelopeStatus::Completed->label()` é "Assinado", o que na Fase 1 seria falso quando
     * `signature_status = none`: o arquivo não tem assinatura criptográfica nenhuma. Aqui o
     * rótulo diz o que de fato aconteceu.
     */
    public static function statusLabel(Envelope $envelope, ?VerificationRecord $record): string
    {
        if ($envelope->status !== EnvelopeStatus::Completed) {
            return match ($envelope->status) {
                EnvelopeStatus::InProgress, EnvelopeStatus::Finalizing => 'Em andamento',
                EnvelopeStatus::Refused => 'Recusado',
                EnvelopeStatus::Expired => 'Expirado',
                EnvelopeStatus::Canceled => 'Cancelado',
                default => $envelope->status->label(),
            };
        }

        return ($record->signature_status ?? SignatureStatus::None) === SignatureStatus::CompanyA1
            ? 'Concluído · assinado com certificado da operadora'
            : 'Concluído · aceite eletrônico com evidências';
    }

    /**
     * Rótulo curto do estado de um envelope **concluído**, para badge e listagem.
     *
     * `EnvelopeStatus::Completed->label()` é "Assinado" (ROUTES §6.1). Num envelope com
     * `signature_status = none` isso é falso: o arquivo final não tem assinatura nenhuma, e
     * o badge fica a poucos centímetros do texto que diz exatamente o contrário. A
     * arquitetura §2 ("Sem certificado … a UI diz exatamente isso") tem precedência sobre o
     * rótulo do design pela ordem fixada em RECONCILIACAO.md, então "Assinado" fica
     * reservado ao caso em que houve mesmo assinatura — e ao rótulo do **participante**
     * (`RecipientStatus::Signed`), onde ROUTES §6.2 o exige e ele é verdadeiro.
     *
     * Sem registro de verificação o rótulo é o conservador: "Concluído" nunca é falso.
     */
    public static function completedLabel(?VerificationRecord $record): string
    {
        return ($record->signature_status ?? SignatureStatus::None) === SignatureStatus::CompanyA1
            ? 'Assinado'
            : 'Concluído';
    }

    private static function pendingStatement(Envelope $envelope): string
    {
        return match ($envelope->status) {
            EnvelopeStatus::Refused => 'A coleta foi encerrada por recusa de um participante. '
                .'Não existe arquivo final e nenhuma assinatura criptográfica foi aplicada.',
            EnvelopeStatus::Expired => 'O prazo para assinatura terminou antes de todos os aceites. '
                .'Não existe arquivo final e nenhuma assinatura criptográfica foi aplicada.',
            EnvelopeStatus::Canceled => 'O remetente cancelou a solicitação. Não existe arquivo final '
                .'e nenhuma assinatura criptográfica foi aplicada.',
            default => 'A coleta de aceites ainda está em andamento. O arquivo final e o resumo '
                .'SHA-256 dele só existem depois que todos os participantes concluírem.',
        };
    }

    /**
     * Variante `none` (declaração de aceite §5.3). O texto não pode conter nenhuma
     * afirmação de assinatura digital — nem em sentido figurado.
     */
    private static function noneStatement(): string
    {
        return 'Concluído como aceite eletrônico com evidências, sem assinatura criptográfica. '
            .'Nenhum certificado foi aplicado ao arquivo, e leitores de PDF não devem indicar '
            .'nenhuma assinatura nele. A manifestação de vontade de cada participante está '
            .'registrada no relatório de evidências anexado ao arquivo; a integridade do arquivo '
            .'pode ser conferida comparando o resumo SHA-256 dele com o resumo final publicado '
            .'nesta página.';
    }

    /**
     * Variante `company_a1` (declaração de aceite §5.2), com o aviso obrigatório quando o
     * certificado é de ambiente de teste.
     */
    private static function companyStatement(?string $profile, ?CertificateReference $certificate): string
    {
        $operator = (string) config('app.name', 'AssinaVelox');
        $profileLabel = $profile !== null && $profile !== '' ? $profile : 'PAdES';

        $statement = sprintf(
            'O arquivo final recebeu uma assinatura criptográfica no perfil %s, aplicada pela '
            .'operadora %s com certificado digital de titularidade da própria operadora. Ela '
            .'identifica quem consolidou e lacrou o arquivo e permite detectar alterações feitas '
            .'depois do lacre. Não é a assinatura pessoal de nenhum participante nem um certificado '
            .'emitido em nome deles: a manifestação de vontade de cada um é o aceite eletrônico '
            .'registrado no relatório de evidências.',
            $profileLabel,
            $operator,
        );

        if ($certificate?->environment === CertificateEnvironment::Test) {
            $statement .= ' Atenção: a assinatura foi aplicada com um certificado de AMBIENTE DE TESTE, '
                .'sem valor para uso real. Este certificado não é ICP-Brasil.';
        }

        return $statement;
    }

    /**
     * Dados do certificado exibíveis. `secret_ref`, impressão digital e qualquer referência a
     * segredo ficam de fora — a página é pública.
     *
     * @return array<string, mixed>|null
     */
    private static function certificate(?CertificateReference $certificate, ?string $profile): ?array
    {
        if ($certificate === null) {
            return null;
        }

        $test = $certificate->environment === CertificateEnvironment::Test;

        return [
            'subject_cn' => self::commonName($certificate->subject),
            'subject' => $certificate->subject,
            'issuer_cn' => self::commonName($certificate->issuer),
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
     * "CN=AssinaVelox Teste, O=AssinaVelox, C=BR" → "AssinaVelox Teste".
     */
    public static function commonName(?string $distinguishedName): ?string
    {
        if ($distinguishedName === null || trim($distinguishedName) === '') {
            return null;
        }

        if (preg_match('/CN\s*=\s*([^,\/]+)/i', $distinguishedName, $matches) === 1) {
            return trim($matches[1]);
        }

        return trim($distinguishedName);
    }

    /**
     * Resultado técnico da validação em linguagem honesta.
     *
     * @return array<string, mixed>
     */
    private static function validation(VerificationRecord $record): array
    {
        $envelope = $record->validation_result;

        if (! is_array($envelope) || $envelope === []) {
            return self::unavailableValidation(
                'A assinatura foi aplicada, mas nenhum resultado de validação foi registrado na '
                .'conclusão. A integridade não pode ser afirmada a partir desta página.',
            );
        }

        /*
         * `validation_result` é um envelope com os fatos negativos explícitos
         * (`timestamp`, `long_term_validation`, `reason`) e o resultado bruto da
         * `pdftool validate` dentro de `result` — é lá que moram `all_intact`,
         * `all_valid`, `all_trusted` e `trust_roots_configured`
         * ({@see \App\Services\Envelopes\Finalization\OperatorSignature::validationPayload()}).
         *
         * Ler só o nível de cima fazia toda conclusão assinada aparecer como "integridade
         * não verificada", mesmo com a validação tendo confirmado a integridade — errava
         * para o lado conservador, mas errava. A leitura do nível de cima permanece como
         * alternativa para linhas antigas ou de demonstração gravadas achatadas.
         */
        $result = is_array($envelope['result'] ?? null) && $envelope['result'] !== []
            ? $envelope['result']
            : $envelope;

        if (($envelope['result'] ?? null) === null && ! array_key_exists('all_intact', $result)) {
            return self::unavailableValidation(
                'A assinatura foi aplicada, mas o resultado da validação registrado na conclusão '
                .'não traz os campos de integridade. A integridade não pode ser afirmada a partir '
                .'desta página.',
            );
        }

        $intact = ($result['all_intact'] ?? null) === true;
        $valid = ($result['all_valid'] ?? null) === true;
        $trustRoots = (int) ($result['trust_roots_configured'] ?? 0);
        $trusted = ($result['all_trusted'] ?? null) === true && $trustRoots > 0;
        /*
         * `revocation` aparece nos dois níveis: a `pdftool validate` a devolve dentro de
         * `result` e a finalização a repete no envelope. Vale a mais específica; na falta
         * das duas, o fato negativo explícito ("não verificada"), nunca um silêncio.
         */
        $revocation = match (true) {
            is_string($result['revocation'] ?? null) => (string) $result['revocation'],
            is_string($envelope['revocation'] ?? null) => (string) $envelope['revocation'],
            default => 'not_checked',
        };
        $signatureCount = (int) ($result['signature_count'] ?? 0);
        /*
         * `all_intact` responde apenas "os bytes COBERTOS pela assinatura mudaram?". Um PDF
         * com conteúdo acrescentado depois da revisão assinada continua intact/valid — o que
         * denuncia o acréscimo é `coverage = ENTIRE_REVISION` (em vez de ENTIRE_FILE) e, quando
         * a alteração toca o catálogo, `docmdp_ok = false`. Sem ler os dois, esta página
         * publicava "a validação não encontrou alteração no arquivo depois da assinatura"
         * sobre um arquivo que tinha exatamente isso.
         */
        $covering = self::coversEntireFile($result);
        $docmdpOk = self::docmdpNotViolated($result);

        $integrity = match (true) {
            $intact && $valid && $covering && $docmdpOk => 'intact',
            $intact && $valid => 'outside_revision',
            $signatureCount === 0 => 'unknown',
            default => 'broken',
        };

        return [
            'available' => true,
            'summary' => self::summaryLine($integrity, $trusted, $revocation),
            'validated_at' => $record->validated_at?->toIso8601String(),
            'signature_count' => $signatureCount,
            'integrity' => $integrity,
            'integrity_label' => match ($integrity) {
                'intact' => 'Íntegro na conclusão: a validação não encontrou alteração no arquivo '
                    .'depois da assinatura.',
                'outside_revision' => 'A assinatura está íntegra, mas NÃO cobre o arquivo inteiro: há '
                    .'conteúdo fora da revisão assinada. Trate este resultado como inconclusivo e '
                    .'confira o arquivo por conta própria.',
                'broken' => 'A validação NÃO confirmou a integridade do arquivo. Trate este resultado '
                    .'como inconclusivo e confira o arquivo por conta própria.',
                default => 'Integridade não verificada.',
            },
            'chain_trust' => $trusted ? 'trusted' : 'not_verified',
            'chain_trust_label' => $trusted
                ? 'Cadeia de certificação validada até uma raiz de confiança configurada nesta plataforma.'
                : 'Confiança da cadeia de certificação NÃO verificada por esta plataforma (nenhuma raiz '
                    .'de confiança configurada). Quem recebe o arquivo pode validá-lo no próprio leitor de PDF.',
            'revocation' => $revocation,
            'revocation_label' => $revocation === 'not_checked'
                ? 'Revogação do certificado NÃO verificada. Não há consulta a LCR nem a OCSP nesta versão.'
                : 'Revogação do certificado: '.$revocation.'.',
            'notes' => [
                'Este resultado é o registrado no momento da conclusão do envelope; ele não é '
                .'recalculado a cada visita a esta página.',
                'Esta página não é um certificado emitido por autoridade certificadora, e um resumo '
                .'SHA-256 não é uma assinatura.',
            ],
        ];
    }

    /**
     * Frase curta do resultado técnico, para o meio da sentença "Resultado técnico da validação
     * no momento da conclusão: {…}" (declaração de aceite §5.2). Sem ponto final e sem nenhuma
     * afirmação que a validação não tenha feito.
     */
    private static function summaryLine(string $integrity, bool $trusted, string $revocation): string
    {
        return implode('; ', [
            match ($integrity) {
                'intact' => 'assinatura íntegra e válida na conclusão',
                'outside_revision' => 'assinatura íntegra, mas com conteúdo fora da revisão assinada',
                'broken' => 'integridade NÃO confirmada',
                default => 'integridade não verificada',
            },
            $trusted
                ? 'cadeia de certificação validada até uma raiz de confiança configurada'
                : 'cadeia de certificação não verificada por esta plataforma',
            $revocation === 'not_checked'
                ? 'revogação não verificada'
                : 'revogação: '.$revocation,
        ]);
    }

    /**
     * A assinatura cobre o arquivo inteiro? Só `true` com evidência positiva: o agregado
     * `all_covering` da `pdftool validate` ou, na falta dele (linha gravada por uma versão
     * anterior da ferramenta), `coverage = ENTIRE_FILE` em **todas** as assinaturas.
     * Ausência de informação não é confirmação.
     *
     * @param  array<string, mixed>  $result
     */
    private static function coversEntireFile(array $result): bool
    {
        if (array_key_exists('all_covering', $result)) {
            return $result['all_covering'] === true;
        }

        return self::everySignature($result, static fn (array $signature): bool => ($signature['coverage'] ?? null) === 'ENTIRE_FILE');
    }

    /**
     * Nenhuma assinatura teve a política DocMDP violada por alterações posteriores.
     *
     * @param  array<string, mixed>  $result
     */
    private static function docmdpNotViolated(array $result): bool
    {
        if (array_key_exists('all_docmdp_ok', $result)) {
            return $result['all_docmdp_ok'] === true;
        }

        return self::everySignature($result, static fn (array $signature): bool => ($signature['docmdp_ok'] ?? null) !== false);
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  callable(array<string, mixed>): bool  $predicate
     */
    private static function everySignature(array $result, callable $predicate): bool
    {
        $signatures = $result['signatures'] ?? null;

        if (! is_array($signatures) || $signatures === []) {
            return false;
        }

        foreach ($signatures as $signature) {
            if (! is_array($signature) || ! $predicate($signature)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private static function unavailableValidation(string $reason): array
    {
        return [
            'available' => false,
            'summary' => null,
            'validated_at' => null,
            'signature_count' => 0,
            'integrity' => 'unknown',
            'integrity_label' => $reason,
            'chain_trust' => 'not_verified',
            'chain_trust_label' => 'Confiança da cadeia de certificação não verificada.',
            'revocation' => 'not_checked',
            'revocation_label' => 'Revogação do certificado não verificada.',
            'notes' => [],
        ];
    }
}
