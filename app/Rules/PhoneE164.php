<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumber;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberType;
use libphonenumber\PhoneNumberUtil;

/**
 * Telefone válido, normalizável para E.164 (Fase 2 §2.9, `recipients.phone`).
 *
 * Usa giggsey/libphonenumber-for-php com a região padrão de
 * `assinavelox.channels.default_region` (BR): "(11) 91234-5678" vira "+5511912345678".
 * Com `mobileOnly` (padrão), só aceita número que pode receber SMS/WhatsApp: celular ou
 * "fixo ou celular" quando a numeração do país não distingue os dois.
 *
 * Os helpers estáticos são o único lugar que normaliza e mascara telefone na aplicação.
 */
class PhoneE164 implements ValidationRule
{
    public function __construct(private readonly bool $mobileOnly = true) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || self::normalize($value, $this->mobileOnly) === null) {
            $fail($this->mobileOnly
                ? 'Informe um celular válido com DDD, por exemplo +55 11 91234-5678.'
                : 'Informe um telefone válido com DDD, por exemplo +55 11 3123-4567.');
        }
    }

    /**
     * Número em E.164 (+5511912345678) ou null quando inválido.
     */
    public static function normalize(string $raw, bool $mobileOnly = true, ?string $region = null): ?string
    {
        $number = self::parse($raw, $region);

        if ($number === null) {
            return null;
        }

        $util = PhoneNumberUtil::getInstance();

        if (! $util->isValidNumber($number)) {
            return null;
        }

        if ($mobileOnly && ! in_array($util->getNumberType($number), [PhoneNumberType::MOBILE, PhoneNumberType::FIXED_LINE_OR_MOBILE], true)) {
            return null;
        }

        return $util->format($number, PhoneNumberFormat::E164);
    }

    /**
     * Máscara para exibição pública e trilha: DDI + últimos 4 dígitos ("+55 •••••••5678").
     * Nunca devolve o número inteiro.
     */
    public static function mask(?string $e164): string
    {
        $digits = preg_replace('/\D+/', '', (string) $e164) ?? '';

        if ($digits === '') {
            return '';
        }

        $number = self::parse('+'.$digits);

        if ($number === null) {
            return str_repeat('•', max(0, strlen($digits) - 4)).substr($digits, -4);
        }

        $national = PhoneNumberUtil::getInstance()->getNationalSignificantNumber($number);

        return '+'.$number->getCountryCode().' '.str_repeat('•', max(0, strlen($national) - 4)).substr($national, -4);
    }

    private static function parse(string $raw, ?string $region = null): ?PhoneNumber
    {
        $raw = trim($raw);

        if ($raw === '' || strlen($raw) > 32) {
            return null;
        }

        $region ??= (string) config('assinavelox.channels.default_region', 'BR');

        try {
            return PhoneNumberUtil::getInstance()->parse($raw, $region);
        } catch (NumberParseException) {
            return null;
        }
    }
}
