<?php

namespace App\Services\Signing\Certificates;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * Um gravador criptográfico por envelope (roadmap §2.12).
 *
 * Toda escrita que acrescenta uma revisão assinada — a assinatura de cada participante e a
 * assinatura final da operadora — acontece dentro deste lock (`envelope:{id}:sign`). O
 * aceite eletrônico continua paralelo; a aplicação criptográfica é serial.
 *
 * O lock é a primeira barreira. A segunda é o banco: `participant_signatures` tem
 * `base_document_version_id` ÚNICO e a gravação confere, sob `lockForUpdate` no documento,
 * que a base ainda é a revisão mais recente ({@see IncrementalRevisions::storeSigned()}).
 * Um lock vencido (worker que morreu) não produz revisão irmã: produz uma exceção.
 *
 * Nada de transação de banco aberta durante a chamada ao pdftool: o lock é de cache.
 */
final class EnvelopeSigningLock
{
    public function __construct(private readonly Repository $config) {}

    public static function key(int $envelopeId): string
    {
        return 'envelope:'.$envelopeId.':sign';
    }

    /**
     * Executa `$callback` com o lock do envelope. Espera até `$waitSeconds` pelo lock.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     *
     * @throws LockTimeoutException quando outro gravador segura o lock além da espera
     */
    public function run(int $envelopeId, callable $callback, ?int $waitSeconds = null): mixed
    {
        $ttl = max(30, (int) $this->config->get('assinavelox.participant_a1.lock_seconds', 900));
        $wait = $waitSeconds ?? max(0, (int) $this->config->get('assinavelox.participant_a1.lock_wait_seconds', 120));

        return Cache::lock(self::key($envelopeId), $ttl)->block($wait, $callback);
    }
}
