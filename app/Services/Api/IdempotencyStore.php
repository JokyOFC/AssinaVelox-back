<?php

namespace App\Services\Api;

use App\Models\ApiToken;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use stdClass;
use Symfony\Component\HttpFoundation\Response;

/**
 * Chaves `Idempotency-Key` da API v1 (docs/fase-2/api-v1.md §7), por token.
 *
 * Fluxo: `reserve()` tenta INSERIR (token, chave). O UNIQUE decide a corrida — só uma
 * requisição reserva; a outra encontra a linha e recebe:
 *  - `replay`: mesma impressão digital e resposta já armazenada → a MESMA resposta de novo;
 *  - `mismatch`: impressão digital diferente (outro corpo, arquivo, rota ou recurso) → 409;
 *  - `in_progress`: a primeira ainda está processando → 409 com `Retry-After`.
 *
 * Só respostas de sucesso (< 400) são guardadas, por 24 h; um erro libera a chave para que o
 * cliente tente de novo (com o mesmo corpo) depois de corrigir a causa. Uma reserva presa (o
 * processo morreu no meio) vence em `lock_seconds` e pode ser retomada.
 */
final class IdempotencyStore
{
    public const TABLE = 'api_idempotency_keys';

    public const RESERVED = 'reserved';

    public const REPLAY = 'replay';

    public const MISMATCH = 'mismatch';

    public const IN_PROGRESS = 'in_progress';

    private const MAX_STORED_BYTES = 1_048_576;

    /**
     * Impressão digital do pedido: método, rota, parâmetros de rota, corpo canônico e, para
     * cada arquivo, nome, tamanho e SHA-256 do conteúdo.
     */
    public static function fingerprint(Request $request): string
    {
        $route = $request->route();
        $files = [];

        foreach (Arr::dot($request->allFiles()) as $name => $file) {
            if ($file instanceof UploadedFile) {
                $path = (string) $file->getRealPath();

                $files[(string) $name] = [
                    'name' => $file->getClientOriginalName(),
                    'size' => $file->getSize(),
                    'sha256' => $path !== '' && is_file($path) ? hash_file('sha256', $path) : null,
                ];
            }
        }

        ksort($files);

        $payload = [
            'method' => $request->getMethod(),
            'route' => is_object($route) ? $route->getName() : null,
            'parameters' => is_object($route) ? self::canonical($route->originalParameters()) : [],
            'input' => self::canonical((array) $request->input()),
            'files' => $files,
        ];

        return hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    /**
     * @return array{state: string, id: int|null}
     */
    public function reserve(ApiToken $token, string $key, string $hash, string $method, string $route): array
    {
        $now = Carbon::now();

        // Limpeza oportunista: as chaves vencidas deste token saem antes de reservar.
        DB::table(self::TABLE)
            ->where('personal_access_token_id', $token->getKey())
            ->where('expires_at', '<=', $now)
            ->delete();

        try {
            $id = DB::table(self::TABLE)->insertGetId([
                'organization_id' => $token->organization_id,
                'personal_access_token_id' => $token->getKey(),
                'idempotency_key' => $key,
                'request_hash' => $hash,
                'method' => $method,
                'route' => mb_substr($route, 0, 120),
                'status' => 'processing',
                'locked_until' => $now->copy()->addSeconds($this->lockSeconds()),
                'expires_at' => $now->copy()->addHours($this->ttlHours()),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return ['state' => self::RESERVED, 'id' => (int) $id];
        } catch (UniqueConstraintViolationException) {
            // Outra requisição (anterior ou simultânea) já tem esta chave.
        }

        $row = $this->find($token, $key);

        if ($row === null) {
            return ['state' => self::IN_PROGRESS, 'id' => null];
        }

        if (! hash_equals((string) $row->request_hash, $hash)) {
            return ['state' => self::MISMATCH, 'id' => (int) $row->id];
        }

        if ($row->status === 'completed') {
            return ['state' => self::REPLAY, 'id' => (int) $row->id];
        }

        // Reserva abandonada (lock vencido): retoma de forma atômica — só uma consegue.
        $taken = DB::table(self::TABLE)
            ->where('id', $row->id)
            ->where('status', 'processing')
            ->where(fn ($query) => $query->whereNull('locked_until')->orWhere('locked_until', '<=', $now))
            ->update([
                'locked_until' => $now->copy()->addSeconds($this->lockSeconds()),
                'updated_at' => $now,
            ]);

        return $taken === 1
            ? ['state' => self::RESERVED, 'id' => (int) $row->id]
            : ['state' => self::IN_PROGRESS, 'id' => (int) $row->id];
    }

    /**
     * Guarda a resposta de sucesso (JSON até 1 MB) ou libera a chave.
     */
    public function complete(int $id, Response $response): void
    {
        $content = $response instanceof JsonResponse ? (string) $response->getContent() : null;

        if ($response->getStatusCode() >= 400 || $content === null || strlen($content) > self::MAX_STORED_BYTES) {
            $this->release($id);

            return;
        }

        $headers = array_filter([
            'Content-Type' => $response->headers->get('Content-Type'),
            'Location' => $response->headers->get('Location'),
        ]);

        DB::table(self::TABLE)->where('id', $id)->update([
            'status' => 'completed',
            'response_status' => $response->getStatusCode(),
            'response_headers' => json_encode($headers, JSON_UNESCAPED_SLASHES),
            'response_body' => $content,
            'locked_until' => null,
            'updated_at' => Carbon::now(),
        ]);
    }

    public function release(int $id): void
    {
        DB::table(self::TABLE)->where('id', $id)->where('status', 'processing')->delete();
    }

    /**
     * A resposta armazenada, idêntica à original, com `Idempotent-Replayed: true`.
     */
    public function replay(int $id): ?Response
    {
        $row = DB::table(self::TABLE)->where('id', $id)->first();

        if ($row === null || $row->status !== 'completed') {
            return null;
        }

        $headers = json_decode((string) $row->response_headers, true);
        $headers = is_array($headers) ? array_filter($headers, 'is_string') : [];

        return new Response((string) $row->response_body, (int) $row->response_status, $headers + [
            'Idempotent-Replayed' => 'true',
        ]);
    }

    private function find(ApiToken $token, string $key): ?stdClass
    {
        return DB::table(self::TABLE)
            ->where('personal_access_token_id', $token->getKey())
            ->where('idempotency_key', $key)
            ->first();
    }

    private function ttlHours(): int
    {
        return max(1, (int) config('assinavelox.api.idempotency.ttl_hours', 24));
    }

    private function lockSeconds(): int
    {
        return max(5, (int) config('assinavelox.api.idempotency.lock_seconds', 60));
    }

    /**
     * Ordena chaves de mapas (listas mantêm a ordem), para que a mesma entrada em outra
     * ordem de campos tenha a mesma impressão digital.
     *
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    private static function canonical(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::canonical($item);
            }
        }

        return $value;
    }
}
