<?php

namespace App\Support;

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

/**
 * Identificador de correlação da unidade de trabalho corrente.
 *
 * É um ULID opaco, sem nenhum dado do cliente, que amarra numa linha só: a requisição
 * HTTP, os jobs que ela despacha, as chamadas de processo do pdftool, as tentativas de
 * entrega de e-mail e os eventos de auditoria gravados no caminho.
 *
 * O armazenamento é o `Context` do Laravel, e não uma propriedade estática, por um motivo
 * prático: o Context é desidratado dentro do payload do job e re-hidratado no worker, de
 * modo que um job enfileirado por esta requisição continua registrando o MESMO
 * identificador — inclusive em outra máquina, minutos depois.
 *
 * Não é segredo e não é chave de autorização: serve para juntar registros no suporte.
 */
final class Correlation
{
    public const KEY = 'correlation_id';

    /**
     * Identificador corrente; cria um novo se ainda não houver.
     */
    public static function id(): string
    {
        $current = Context::get(self::KEY);

        if (is_string($current) && $current !== '') {
            return $current;
        }

        return self::start();
    }

    /**
     * Define (ou substitui) o identificador da unidade de trabalho corrente.
     */
    public static function start(?string $id = null): string
    {
        $id = self::sanitize($id) ?? (string) Str::ulid();

        Context::add(self::KEY, $id);

        return $id;
    }

    /**
     * Identificador corrente sem criar um novo (null quando não há).
     */
    public static function current(): ?string
    {
        $current = Context::get(self::KEY);

        return is_string($current) && $current !== '' ? $current : null;
    }

    /**
     * Aceita apenas ULID/UUID/hexadecimal curto: um identificador vindo de fora (cabeçalho
     * de um balanceador, por exemplo) não pode injetar quebra de linha, aspas ou 4 KB de
     * texto dentro de cada linha de log.
     */
    public static function sanitize(?string $id): ?string
    {
        $id = trim((string) $id);

        if ($id === '' || preg_match('/^[A-Za-z0-9_-]{8,64}$/', $id) !== 1) {
            return null;
        }

        return $id;
    }
}
