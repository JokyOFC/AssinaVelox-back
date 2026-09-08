<?php

namespace App\Services\Pdf\Support;

/**
 * Ambiente MÍNIMO para processos filhos (pdftool, LibreOffice).
 *
 * Por que não herdar o ambiente do PHP: o ambiente de um processo PHP (CLI,
 * FPM, worker de fila) costuma carregar segredos — APP_KEY, credenciais de
 * banco/S3/SMTP, tokens de gateway, e a própria passphrase do certificado. Um
 * processo filho pode registrá-los em logs, dumps de erro ou arquivos
 * temporários (tracebacks do Python, perfis do LibreOffice), e uma biblioteca
 * comprometida os leria trivialmente. Por isso cada variável herdada é
 * explicitamente negada (valor `false`, que o Symfony Process remove do filho)
 * e apenas uma lista curta e nomeada é repassada.
 *
 * O que é repassado:
 * - Windows: SYSTEMROOT (obrigatório para carregar DLLs do sistema/Winsock),
 *   PATH (localização de dependências do interpretador/LibreOffice).
 * - Linux/macOS: PATH, LANG=C.UTF-8.
 * - TEMP/TMP/TMPDIR e HOME/USERPROFILE apontam para o diretório temporário
 *   exclusivo da operação, para que nada seja gravado fora dele.
 * - Extras nomeados pelo chamador (ex.: a variável da passphrase, só na
 *   assinatura; SAL_USE_VCLPLUGIN para o LibreOffice).
 */
final class ProcessEnvironment
{
    /**
     * @param  array<string, string>  $extra  variáveis adicionais (sobrescrevem as padrão)
     * @return array<string, string|false>
     */
    public static function minimal(string $temporaryDirectory, array $extra = []): array
    {
        $env = self::denyInherited();

        foreach (self::inheritedNames() as $name) {
            $value = self::current($name);
            if ($value !== null && $value !== '') {
                $env[$name] = $value;
            }
        }

        if (PHP_OS_FAMILY !== 'Windows') {
            $env['LANG'] = 'C.UTF-8';
            $env['HOME'] = $temporaryDirectory;
        } else {
            $env['USERPROFILE'] = $temporaryDirectory;
        }

        $env['TEMP'] = $temporaryDirectory;
        $env['TMP'] = $temporaryDirectory;
        $env['TMPDIR'] = $temporaryDirectory;

        foreach ($extra as $name => $value) {
            $env[$name] = $value;
        }

        return $env;
    }

    /**
     * Variáveis herdadas do ambiente do PHP, por nome.
     *
     * @return list<string>
     */
    public static function inheritedNames(): array
    {
        return PHP_OS_FAMILY === 'Windows'
            ? ['SYSTEMROOT', 'PATH']
            : ['PATH'];
    }

    /**
     * Valor atual de uma variável no processo PHP (getenv, $_SERVER, $_ENV).
     */
    public static function current(string $name): ?string
    {
        $value = getenv($name);
        if (is_string($value)) {
            return $value;
        }

        foreach ([$_SERVER, $_ENV] as $source) {
            foreach ($source as $key => $candidate) {
                if (is_string($key) && strcasecmp($key, $name) === 0 && is_scalar($candidate)) {
                    return (string) $candidate;
                }
            }
        }

        return null;
    }

    /**
     * Toda variável visível ao PHP marcada com `false` => removida do filho.
     *
     * @return array<string, false>
     */
    private static function denyInherited(): array
    {
        $names = [];
        foreach ([getenv(), $_SERVER, $_ENV] as $source) {
            foreach (array_keys($source) as $key) {
                if (is_string($key) && $key !== '' && ! str_contains($key, '=') && ! str_contains($key, "\0")) {
                    $names[$key] = false;
                }
            }
        }

        return $names;
    }
}
