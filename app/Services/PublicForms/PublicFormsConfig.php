<?php

namespace App\Services\PublicForms;

/**
 * Parâmetros do formulário público. Todos têm padrão aqui; a instalação pode sobrescrevê-los
 * em `config('assinavelox.public_forms.*')` (bloco opcional — ver
 * docs/fase-2/formulario-publico.md §9). Nenhum deles é decidido pelo navegador.
 */
final class PublicFormsConfig
{
    /** Validade do link de confirmação enviado por e-mail. */
    public static function confirmationTtlMinutes(): int
    {
        return max(5, (int) config('assinavelox.public_forms.confirmation_ttl_minutes', 60));
    }

    /** Tempo mínimo entre abrir a página e enviar: abaixo disso é preenchimento automático. */
    public static function minFillSeconds(): int
    {
        return max(0, (int) config('assinavelox.public_forms.min_fill_seconds', 3));
    }

    /** Página aberta há mais tempo que isto precisa ser recarregada. */
    public static function maxFillMinutes(): int
    {
        return max(10, (int) config('assinavelox.public_forms.max_fill_minutes', 360));
    }

    /**
     * Limites de tentativas de envio (toda requisição POST conta, válida ou não).
     *
     * @return array{per_ip_form: array{0: int, 1: int}, per_ip: array{0: int, 1: int}, per_form: array{0: int, 1: int}}
     *                                                                                                                   [máximo, janela em minutos]
     */
    public static function rateLimits(): array
    {
        return [
            // Mesmo IP no mesmo formulário.
            'per_ip_form' => [
                max(1, (int) config('assinavelox.public_forms.rate.per_ip_form', 10)),
                max(1, (int) config('assinavelox.public_forms.rate.per_ip_form_minutes', 10)),
            ],
            // Mesmo IP em qualquer formulário da plataforma.
            'per_ip' => [
                max(1, (int) config('assinavelox.public_forms.rate.per_ip', 30)),
                max(1, (int) config('assinavelox.public_forms.rate.per_ip_minutes', 60)),
            ],
            // Volume total de um formulário, de qualquer origem (varredura distribuída).
            'per_form' => [
                max(1, (int) config('assinavelox.public_forms.rate.per_form', 120)),
                max(1, (int) config('assinavelox.public_forms.rate.per_form_minutes', 10)),
            ],
        ];
    }

    /** Links de confirmação pendentes aceitos para o mesmo e-mail no mesmo formulário (última hora). */
    public static function pendingPerEmail(): int
    {
        return max(1, (int) config('assinavelox.public_forms.pending_per_email', 3));
    }

    /** Teto de caracteres de qualquer valor enviado (o tipo da variável pode exigir menos). */
    public static function maxFieldLength(): int
    {
        return max(1, min(5000, (int) config('assinavelox.public_forms.max_field_length', 5000)));
    }

    /** Linhas apagadas por rodada da limpeza de envios vencidos. */
    public static function purgeBatch(): int
    {
        return max(1, (int) config('assinavelox.public_forms.purge_batch', 500));
    }
}
