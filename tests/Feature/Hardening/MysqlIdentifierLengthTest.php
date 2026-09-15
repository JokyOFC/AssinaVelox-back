<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Nomes de índice cabem no MySQL
|--------------------------------------------------------------------------
|
| A produção e o desenvolvimento usam MySQL, que recusa identificadores com mais de 64
| caracteres (erro 1059); os testes usam SQLite, que aceita qualquer tamanho. Sem esta guarda,
| um nome gerado automaticamente pelo Laravel (tabela + colunas + sufixo) passa na suíte e só
| quebra no `php artisan migrate` de verdade — foi o que aconteceu com
| sso_consumed_assertions. Dê um nome explícito ao índice quando o gerado for longo.
|
| Limitação: o SQLite não guarda o nome das chaves estrangeiras, então elas não são conferidas
| aqui; as longas do projeto já usam `indexName:` explícito.
*/

test('nenhum índice criado pelas migrations passa de 64 caracteres (limite do MySQL)', function () {
    $tooLong = collect(DB::select("select name, tbl_name from sqlite_master where type = 'index' and name not like 'sqlite_%'"))
        ->filter(fn (object $index): bool => strlen($index->name) > 64)
        ->map(fn (object $index): string => sprintf('%s (%d caracteres, tabela %s)', $index->name, strlen($index->name), $index->tbl_name))
        ->values()
        ->all();

    expect($tooLong)->toBe([]);
})->skip(fn (): bool => DB::connection()->getDriverName() !== 'sqlite', 'A conferência lê o sqlite_master.');
