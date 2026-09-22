<?php

namespace App\Services\Identity;

use App\Models\Organization;
use App\Models\Plan;
use App\Services\Envelopes\DomainFeatures;

/**
 * Flags da área de identidade (Fase 2, onda B — C-ID). Todas nascem DESLIGADAS (T8).
 *
 * | Flag               | Liga                                                                  |
 * |--------------------|-----------------------------------------------------------------------|
 * | `cpf_field`        | o tipo de campo `cpf` no editor (o valor é validado pelos dígitos)    |
 * | `cpf_lookup`       | a consulta CADASTRAL do CPF no aceite (classe B, produção desabilitada)|
 * | `cnpj_lookup`      | o autopreenchimento por CNPJ (configurações e cadastro)                |
 * | `identity_capture` | a captura simples de foto do rosto/documento (decisão jurídica pendente)|
 *
 * Mesma regra de {@see DomainFeatures}: a flag da organização só vale
 * quando o interruptor global `assinavelox.features.{flag}` E `plans.features.{flag}` do plano
 * vigente dizem sim. O cadastro (sem organização) usa só o global de `cnpj_lookup`.
 *
 * Contrato para `HandleInertiaRequests::features()` (fora da área C-ID): as quatro chaves
 * devem vir de {@see self::forOrganization()} para a organização corrente.
 *
 * O que NÃO depende da flag: um campo `cpf` que já exista num envelope enviado continua sendo
 * validado pelos dígitos no aceite (desligar a flag impede criar coisas novas, nunca abandona
 * um envelope no meio). A captura, ao contrário, só é exigida e aceita com a flag ligada: sem
 * base legal não se coleta imagem, mesmo que a exigência tenha sido gravada antes.
 */
final class IdentityFeatures
{
    public const CPF_FIELD = 'cpf_field';

    public const CPF_LOOKUP = 'cpf_lookup';

    public const CNPJ_LOOKUP = 'cnpj_lookup';

    public const IDENTITY_CAPTURE = 'identity_capture';

    public const FLAGS = [self::CPF_FIELD, self::CPF_LOOKUP, self::CNPJ_LOOKUP, self::IDENTITY_CAPTURE];

    /**
     * Fase 3 §3.3 (F-VIDEO, docs/fase-3/captura-de-video.md): aceite complementado por vídeo
     * curto. Mesma regra (global E plano), desligada até a decisão jurídica (viabilidade §4.4
     * item 20). Fora de {@see self::FLAGS} e de {@see self::forOrganization()} de propósito: o
     * contrato das quatro chaves da Fase 2 não muda; `HandleInertiaRequests` compartilha
     * `identity_video` numa linha própria.
     */
    public const IDENTITY_VIDEO = 'identity_video';

    public static function identityVideo(?Organization $organization): bool
    {
        return self::enabled(self::IDENTITY_VIDEO, $organization);
    }

    /**
     * Fase 4 §4.1 (docs/fase-4/verificacao-facial.md): verificação facial com documento por
     * PROVEDOR EXTERNO (Verifiky). Só vale com as DUAS flags ligadas para a organização —
     * `identity_verification` E `identity_capture`, cada uma global E plano —, porque as fotos
     * que o provedor compara são as da captura simples: sem captura não há o que enviar. Fora de
     * {@see self::FLAGS} e de {@see self::forOrganization()} pelo mesmo motivo do vídeo;
     * `HandleInertiaRequests` compartilha `identity_verification` numa linha própria.
     */
    public const IDENTITY_VERIFICATION = 'identity_verification';

    public static function identityVerification(?Organization $organization): bool
    {
        // Os interruptores globais primeiro: desligados, nenhuma consulta ao plano.
        if ($organization === null || ! self::global(self::IDENTITY_VERIFICATION) || ! self::global(self::IDENTITY_CAPTURE)) {
            return false;
        }

        $plan = self::planOf($organization);

        return self::enabled(self::IDENTITY_VERIFICATION, $organization, $plan)
            && self::enabled(self::IDENTITY_CAPTURE, $organization, $plan);
    }

    public static function cpfField(?Organization $organization): bool
    {
        return self::enabled(self::CPF_FIELD, $organization);
    }

    public static function cpfLookup(?Organization $organization): bool
    {
        return self::enabled(self::CPF_LOOKUP, $organization);
    }

    public static function cnpjLookup(?Organization $organization): bool
    {
        return self::enabled(self::CNPJ_LOOKUP, $organization);
    }

    public static function identityCapture(?Organization $organization): bool
    {
        return self::enabled(self::IDENTITY_CAPTURE, $organization);
    }

    /**
     * Cadastro e "Nova organização": ainda não há plano, vale o interruptor global.
     */
    public static function cnpjLookupWithoutOrganization(): bool
    {
        return self::global(self::CNPJ_LOOKUP);
    }

    /**
     * @return array{cpf_field: bool, cpf_lookup: bool, cnpj_lookup: bool, identity_capture: bool}
     */
    public static function forOrganization(?Organization $organization): array
    {
        $plan = self::planOf($organization);

        return [
            self::CPF_FIELD => self::enabled(self::CPF_FIELD, $organization, $plan),
            self::CPF_LOOKUP => self::enabled(self::CPF_LOOKUP, $organization, $plan),
            self::CNPJ_LOOKUP => self::enabled(self::CNPJ_LOOKUP, $organization, $plan),
            self::IDENTITY_CAPTURE => self::enabled(self::IDENTITY_CAPTURE, $organization, $plan),
        ];
    }

    public static function enabled(string $flag, ?Organization $organization, ?Plan $plan = null): bool
    {
        if (! self::global($flag) || $organization === null) {
            return false;
        }

        $plan ??= self::planOf($organization);

        return ($plan?->features[$flag] ?? false) === true;
    }

    private static function global(string $flag): bool
    {
        return (bool) config('assinavelox.features.'.$flag, false) === true;
    }

    private static function planOf(?Organization $organization): ?Plan
    {
        if ($organization === null) {
            return null;
        }

        /** @var Plan|null */
        return $organization->currentSubscription()->with('plan')->first()?->plan;
    }
}
