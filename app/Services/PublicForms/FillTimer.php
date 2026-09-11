<?php

namespace App\Services\PublicForms;

use App\Models\PublicForm;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Tempo mínimo de preenchimento (antiabuso sem CAPTCHA — docs/fase-2/formulario-publico.md §7).
 *
 * A página pública recebe um carimbo CIFRADO (APP_KEY) com o formulário e o instante em
 * que foi aberta; o envio o devolve. Menos de `min_fill_seconds` entre abrir e enviar é
 * preenchimento automático. Carimbo de outro formulário, adulterado ou velho demais é
 * recusado. O navegador não consegue forjar um carimbo antigo sem a chave.
 *
 * **Uso único.** Cada exibição do formulário emite um carimbo próprio (o vetor de
 * inicialização aleatório da cifra o torna único, e ele é protegido pelo MAC: não dá para
 * produzir outro carimbo válido para o mesmo instante sem a chave). O envio ACEITO o consome
 * ({@see self::consume()}) gravando uma marca no BANCO (`public_form_timer_marks`, com
 * `token_digest` único): o consumo é um INSERT com unicidade, atômico — de dois envios
 * simultâneos com o mesmo carimbo, só um grava. Reusar o carimbo — outro envio sem abrir a
 * página de novo — é recusado. Envio RECUSADO (rápido demais, dado inválido, limite) não
 * consome: a pessoa corrige e reenvia da mesma página.
 *
 * Fase 2, onda C (K-RET): as marcas saíram do cache. Esvaziar o cache (`cache:clear`,
 * `FLUSHALL`, reinício do Redis sem persistência) não reabre nenhum carimbo. A marca vive até
 * o carimbo vencer por conta própria (+1 min); a limpeza agendada ({@see self::prune()}) apaga
 * as vencidas.
 */
final class FillTimer
{
    public const TOO_FAST = 'too_fast';

    public const STALE = 'stale';

    public const INVALID = 'invalid';

    public const USED = 'used';

    public const TABLE = 'public_form_timer_marks';

    public function issue(PublicForm $form): string
    {
        return Crypt::encryptString((string) json_encode([
            'f' => $form->ulid,
            't' => Carbon::now()->getTimestamp(),
        ]));
    }

    /**
     * Motivo da recusa, ou null quando o tempo é aceitável e o carimbo ainda não foi usado.
     */
    public function check(PublicForm $form, mixed $token): ?string
    {
        $issuedAt = $this->issuedAt($form, $token);

        if ($issuedAt === null) {
            return self::INVALID;
        }

        $elapsed = Carbon::now()->getTimestamp() - $issuedAt;

        if ($elapsed < PublicFormsConfig::minFillSeconds()) {
            return self::TOO_FAST;
        }

        if ($elapsed > PublicFormsConfig::maxFillMinutes() * 60) {
            return self::STALE;
        }

        /** @var string $token */
        if (DB::table(self::TABLE)->where('token_digest', $this->digest($form, $token))->exists()) {
            return self::USED;
        }

        return null;
    }

    /**
     * Marca o carimbo como usado. Atômico (INSERT com `token_digest` único, sem exceção de
     * corrida: `insertOrIgnore`): de dois envios simultâneos com o mesmo carimbo, só um grava.
     * Devolve false quando o carimbo já tinha sido usado ou não é válido para este formulário.
     */
    public function consume(PublicForm $form, mixed $token): bool
    {
        $issuedAt = $this->issuedAt($form, $token);

        if ($issuedAt === null) {
            return false;
        }

        // A marca vive até o carimbo vencer por conta própria (+1 min de folga): depois
        // disso ele já é recusado como velho, e a marca pode sumir.
        $expiresAt = max(
            Carbon::now()->getTimestamp() + 60,
            $issuedAt + PublicFormsConfig::maxFillMinutes() * 60 + 60,
        );

        /** @var string $token */
        return DB::table(self::TABLE)->insertOrIgnore([
            'token_digest' => $this->digest($form, $token),
            'expires_at' => Carbon::createFromTimestamp($expiresAt),
            'created_at' => Carbon::now(),
        ]) === 1;
    }

    /**
     * Apaga as marcas cujo carimbo já venceu (não protegem mais nada). Idempotente.
     */
    public static function prune(?Carbon $now = null): int
    {
        return DB::table(self::TABLE)->where('expires_at', '<', $now ?? Carbon::now())->delete();
    }

    /**
     * Instante de emissão de um carimbo válido deste formulário, ou null.
     */
    private function issuedAt(PublicForm $form, mixed $token): ?int
    {
        if (! is_string($token) || $token === '' || strlen($token) > 2048) {
            return null;
        }

        try {
            $data = json_decode(Crypt::decryptString($token), true);
        } catch (DecryptException) {
            return null;
        }

        if (! is_array($data) || ($data['f'] ?? null) !== $form->ulid || ! is_int($data['t'] ?? null)) {
            return null;
        }

        return $data['t'];
    }

    /**
     * Identidade do carimbo para a marca de uso. Vem do vetor de inicialização e do texto
     * cifrado — as partes cobertas pelo MAC — e não da string enviada: reformatar o JSON ou o
     * base64 do envelope não gera um "carimbo novo". Só é chamada depois de decifrar com
     * sucesso, então o envelope é válido. O banco guarda só o SHA-256, nunca o carimbo.
     */
    private function digest(PublicForm $form, string $token): string
    {
        $envelope = json_decode((string) base64_decode($token, true), true);

        $identity = is_array($envelope) && is_string($envelope['iv'] ?? null) && is_string($envelope['value'] ?? null)
            ? base64_decode($envelope['iv']).'|'.$envelope['value']
            : $token;

        return hash('sha256', 'public-form-timer|'.$form->ulid.'|'.$identity);
    }
}
