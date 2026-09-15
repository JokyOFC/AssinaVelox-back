<?php

/*
 * Autoload PSR-4 para quem não usa o Composer: require 'caminho/para/sdks/php/autoload.php';
 * Com o Composer, o composer.json deste diretório declara o mesmo mapeamento.
 */

spl_autoload_register(static function (string $class): void {
    $prefix = 'AssinaVelox\\Sdk\\';

    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $file = __DIR__.'/src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';

    if (is_file($file)) {
        require $file;
    }
});
