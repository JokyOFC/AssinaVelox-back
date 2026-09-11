<?php

namespace App\Services\Signing\Certificates;

use App\Services\Envelopes\Finalization\Exceptions\FinalizationException;
use App\Services\Pdf\Dto\SignatureValidation;
use App\Services\Pdf\Dto\ValidationResult;

/**
 * A sequência de assinaturas incrementais de um arquivo é íntegra COMO UM TODO?
 *
 * `OperatorSignature::assertPublishable()` exige `all_covering` (toda assinatura cobre o
 * arquivo inteiro) — correto para um arquivo com UMA assinatura, e falso por construção
 * para uma cadeia: cada assinatura anterior cobre a sua própria revisão
 * (`coverage = ENTIRE_REVISION`) e só a última cobre o arquivo inteiro. Para a cadeia valem
 * outras condições, e todas são necessárias:
 *
 * - todas as assinaturas íntegras (`intact`) e válidas (`valid`);
 * - a mais recente cobre o arquivo INTEIRO (nada fora de revisão assinada);
 * - cada anterior cobre a sua revisão, e o que veio depois dela é só alteração PERMITIDA
 *   (`NONE` ou `FORM_FILLING` — acrescentar e preencher um campo de assinatura);
 * - nenhuma modificação suspeita e nenhuma violação de DocMDP;
 * - a quantidade de assinaturas é a esperada (nenhuma a mais, nenhuma a menos).
 *
 * Confiança da cadeia de certificação é outra dimensão e não entra aqui: sem raiz
 * configurada, toda assinatura é "não confiável" por construção, e isso é dito à parte.
 * Mesma regra de `pdftool participant-sign` (`analyse_chain`).
 */
final class IncrementalChain
{
    public const PERMITTED_LATER_CHANGES = ['NONE', 'FORM_FILLING'];

    private const TRUST_ONLY_ERRORS = ['trust:', 'self_anchored_chain_problem'];

    /**
     * @return array{ok: bool, signature_count: int, newest_covers_entire_file: bool, earlier_changes_permitted: bool, problems: list<string>}
     */
    public static function analyse(ValidationResult $validation, ?int $expectedCount = null): array
    {
        $signatures = $validation->signatures;
        $problems = [];

        if ($signatures === []) {
            $problems[] = 'no_signatures';
        }

        $last = count($signatures) - 1;

        foreach ($signatures as $index => $signature) {
            $name = $signature->fieldName !== '' ? $signature->fieldName : '#'.($index + 1);

            if (! $signature->intact) {
                $problems[] = $name.': not_intact';
            }

            if (! $signature->valid) {
                $problems[] = $name.': not_valid';
            }

            if ($signature->docmdpOk === false) {
                $problems[] = $name.': docmdp_violation';
            }

            if ($index === $last) {
                if ($signature->coverage !== 'ENTIRE_FILE') {
                    $problems[] = $name.': newest_signature_does_not_cover_entire_file';
                }
            } else {
                if (! in_array($signature->coverage, ['ENTIRE_REVISION', 'ENTIRE_FILE'], true)) {
                    $problems[] = $name.': unclear_coverage';
                }

                if (! in_array($signature->modificationLevel, self::PERMITTED_LATER_CHANGES, true)) {
                    $problems[] = $name.': later_changes_not_permitted';
                }
            }

            foreach ($signature->errors as $error) {
                if (self::isTrustOnly($error) || str_contains($error, 'digest_mismatch') || str_contains($error, 'invalid_signature')) {
                    continue;
                }

                $problems[] = $name.': '.$error;
            }
        }

        if ($expectedCount !== null && $validation->signatureCount !== $expectedCount) {
            $problems[] = sprintf('expected %d signatures, found %d', $expectedCount, $validation->signatureCount);
        }

        return [
            'ok' => $problems === [],
            'signature_count' => $validation->signatureCount,
            'newest_covers_entire_file' => $signatures !== [] && $signatures[$last]->coverage === 'ENTIRE_FILE',
            'earlier_changes_permitted' => self::earlierPermitted($signatures),
            'problems' => $problems,
        ];
    }

    /**
     * @throws FinalizationException
     */
    public static function assertSound(ValidationResult $validation, int $expectedCount): void
    {
        $analysis = self::analyse($validation, $expectedCount);

        if ($analysis['ok']) {
            return;
        }

        throw FinalizationException::signatureNotVerifiable('incremental_chain: '.implode('; ', $analysis['problems']));
    }

    /**
     * @param  list<SignatureValidation>  $signatures
     */
    private static function earlierPermitted(array $signatures): bool
    {
        $earlier = array_slice($signatures, 0, max(0, count($signatures) - 1));

        foreach ($earlier as $signature) {
            if (! in_array($signature->modificationLevel, self::PERMITTED_LATER_CHANGES, true)) {
                return false;
            }
        }

        return true;
    }

    private static function isTrustOnly(string $error): bool
    {
        foreach (self::TRUST_ONLY_ERRORS as $prefix) {
            if (str_starts_with($error, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
