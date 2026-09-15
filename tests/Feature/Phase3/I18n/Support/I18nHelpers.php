<?php

/*
|--------------------------------------------------------------------------
| Helpers dos testes da Fase 3 §3.3 — F-I18N (página pública e e-mails multilíngues)
|--------------------------------------------------------------------------
| Incluído com require_once pelos arquivos de teste. Não contém testes. Reaproveita o envelope
| JÁ ENVIADO dos testes do signatário (tests/Feature/Sign/Support/SignerHelpers.php) e liga a
| flag `multilingual` pelo mesmo caminho das demais flags da organização (global E plano).
*/

use App\Models\Envelope;
use App\Models\Organization;
use App\Models\Recipient;
use App\Support\Locale\SignerMessageCatalog;
use Illuminate\Support\Facades\File;

require_once __DIR__.'/../../../Support/OrganizationHelpers.php';
require_once __DIR__.'/../../../Sign/Support/SignerHelpers.php';

if (! function_exists('i18nEnableFlag')) {
    /**
     * Liga (ou desliga) `multilingual` para a organização: config global E plano vigente.
     */
    function i18nEnableFlag(Organization $organization, bool $enabled = true): void
    {
        config()->set('assinavelox.features.multilingual', $enabled);

        $plan = $organization->currentSubscription()->with('plan')->first()?->plan;

        if ($plan !== null) {
            $features = (array) ($plan->features ?? []);
            $features['multilingual'] = $enabled;
            $plan->forceFill(['features' => $features])->save();
        }

        SignerMessageCatalog::flush();
    }
}

if (! function_exists('i18nSignerScenario')) {
    /**
     * Envelope enviado com Maria (idioma escolhido pelo remetente) e a flag ligada ou não.
     *
     * @return array{organization: Organization, envelope: Envelope, recipient: Recipient, token: string}
     */
    function i18nSignerScenario(string $locale = 'en', bool $flag = true, ?string $timezone = null): array
    {
        $ctx = signerEnvelope([[
            'name' => 'Maria Alves Souza',
            'email' => 'maria@exemplo.test',
        ]]);

        i18nEnableFlag($ctx['organization'], $flag);

        $recipient = $ctx['recipients']['maria@exemplo.test'];
        $recipient->forceFill(['locale' => $locale, 'timezone' => $timezone])->save();

        return [
            'organization' => $ctx['organization'],
            'envelope' => $ctx['envelope'],
            'recipient' => $recipient->fresh(),
            'token' => $ctx['tokens']['maria@exemplo.test'],
        ];
    }
}

if (! function_exists('i18nWorkspace')) {
    /** Disco `documents` exclusivo do teste (mesma tática dos testes do signatário). */
    function i18nWorkspace(): string
    {
        $work = storage_path('framework/testing/i18n-'.uniqid());
        File::ensureDirectoryExists($work);
        signerDisk($work);

        return $work;
    }
}

if (! function_exists('i18nFlattenKeys')) {
    /**
     * Chaves "a.b.c" de um arquivo de tradução PHP (listas numeradas contam como folhas).
     *
     * @param  array<array-key, mixed>  $lines
     * @return array<string, string>
     */
    function i18nFlattenKeys(array $lines, string $prefix = ''): array
    {
        $flat = [];

        foreach ($lines as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value)) {
                $flat += i18nFlattenKeys($value, $path);

                continue;
            }

            $flat[$path] = (string) $value;
        }

        return $flat;
    }
}

if (! function_exists('i18nPlaceholders')) {
    /**
     * Marcadores de um texto: `:nome` (PHP) ou `{nome}` (front e catálogo), ordenados.
     *
     * @return list<string>
     */
    function i18nPlaceholders(string $text, string $style = 'colon'): array
    {
        $pattern = $style === 'colon' ? '/:([a-z_]+)/' : '/\{([a-z_]+)\}/';
        preg_match_all($pattern, $text, $matches);

        $names = array_values(array_unique($matches[1]));
        sort($names);

        return $names;
    }
}

if (! function_exists('i18nFrontDictionary')) {
    /**
     * Lê um arquivo de dicionário do front (`resources/js/i18n/messages/{idioma}/{ns}.ts`) SEM
     * executar nada: extrai os pares `'chave': 'texto'` (aspas simples ou duplas, com quebra
     * de linha entre a chave e o texto, como o formatador deixa).
     *
     * @return array<string, string>
     */
    function i18nFrontDictionary(string $path): array
    {
        $source = (string) file_get_contents($path);
        $string = '(?:\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)")';
        preg_match_all('/^\s{4}\'([a-z0-9_.]+)\':\s*'.$string.',?\s*$/m', $source, $matches, PREG_SET_ORDER);

        $pairs = [];

        foreach ($matches as $match) {
            $raw = ($match[2] ?? '') !== '' ? $match[2] : ($match[3] ?? '');
            $pairs[$match[1]] = stripcslashes($raw);
        }

        return $pairs;
    }
}
