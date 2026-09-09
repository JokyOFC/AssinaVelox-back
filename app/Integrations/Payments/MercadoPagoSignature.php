<?php

namespace App\Integrations\Payments;

use App\Services\Billing\BillingSettings;
use Illuminate\Support\Carbon;
use MercadoPago\Exceptions\InvalidWebhookSignatureException;
use MercadoPago\Exceptions\SignatureFailureReason;
use MercadoPago\Webhook\WebhookSignatureValidator;

/**
 * Validação da assinatura do webhook (docs/integracoes/mercado-pago.md §4.4).
 *
 * Passo a passo, exatamente como a documentação oficial descreve:
 *
 * 1. o cabeçalho `x-signature` traz `ts=<carimbo de tempo>` e `v1=<hash hexadecimal>`;
 * 2. o manifesto é `id:<data.id>;request-id:<x-request-id>;ts:<ts>;` — cada par cujo
 *    valor esteja ausente é **removido** do manifesto;
 * 3. calcula-se `HMAC-SHA256` em hexadecimal com a chave secreta do painel como chave e
 *    o manifesto como mensagem;
 * 4. compara-se com `v1` em **tempo constante** (`hash_equals`);
 * 5. o `ts` precisa estar dentro da janela de tolerância configurada
 *    (`MERCADOPAGO_WEBHOOK_TOLERANCE_SECONDS`, 300 s por padrão).
 *
 * O passo 3 e o passo 4 são feitos pelo validador do SDK oficial
 * (`MercadoPago\Webhook\WebhookSignatureValidator`, função pura, sem rede e sem estado
 * global): usar o código do próprio provedor elimina a chance de o nosso manifesto
 * divergir do dele.
 *
 * ## Por que a janela de tempo é conferida aqui, e não pelo SDK
 *
 * O validador aceita um `toleranceSeconds`, mas converte o carimbo com
 * `$tsMs = (int) $ts * 1000`, isto é, tratando o `ts` do cabeçalho como **segundos**. O
 * exemplo oficial da documentação traz `ts=1742505638683` — treze dígitos, portanto
 * **milissegundos** (como segundos isso seria o ano 57179). Passar `toleranceSeconds`
 * ao SDK faria toda notificação real ser recusada por deriva. Então chamamos o validador
 * sem tolerância — ele cuida do HMAC — e medimos a janela aqui, detectando a unidade
 * pelo tamanho do número (≥ 13 dígitos = milissegundos; caso contrário, segundos).
 * A discrepância está registrada em docs/cobranca.md.
 *
 * ## Caixa do `data.id`
 *
 * A documentação manda passar o id para minúsculas antes do HMAC; o validador do SDK
 * 3.16 monta `id:<valor original>` sem `strtolower` (mudança da release 3.11.3). Qual dos
 * dois o servidor realmente assina para ids alfanuméricos está marcado como **NÃO
 * CONFIRMADO** na pesquisa oficial. Para o tópico `payment` o id é numérico e a questão
 * não existe. Ainda assim tentamos primeiro com a caixa original e, se o hash não bater
 * e o id tiver maiúsculas, repetimos com o id em minúsculas — registrando que precisou
 * da segunda tentativa.
 *
 * O segredo nunca sai daqui: não vai para log, exceção nem payload de fila.
 */
final readonly class MercadoPagoSignature
{
    public function __construct(private BillingSettings $settings) {}

    /**
     * Sem segredo configurado não há como validar — e sem validar não se processa nada.
     */
    public function isConfigured(): bool
    {
        return $this->settings->hasWebhookSecret();
    }

    /**
     * @return array{valid: bool, reason: string|null, lowercased_data_id: bool}
     */
    public function verify(?string $xSignature, ?string $xRequestId, ?string $dataId): array
    {
        $secret = $this->settings->webhookSecret();

        if ($secret === null) {
            return self::invalid('SecretNotConfigured');
        }

        $window = $this->checkTimestamp($xSignature);

        if ($window !== null) {
            return self::invalid($window);
        }

        try {
            WebhookSignatureValidator::validate($xSignature, $xRequestId, $dataId, $secret);

            return ['valid' => true, 'reason' => null, 'lowercased_data_id' => false];
        } catch (InvalidWebhookSignatureException $exception) {
            $reason = $exception->getReason();
        }

        $lowercased = is_string($dataId) ? mb_strtolower($dataId) : null;

        if ($reason === SignatureFailureReason::SIGNATURE_MISMATCH
            && is_string($dataId)
            && $lowercased !== null
            && $lowercased !== $dataId
        ) {
            try {
                WebhookSignatureValidator::validate($xSignature, $xRequestId, $lowercased, $secret);

                return ['valid' => true, 'reason' => null, 'lowercased_data_id' => true];
            } catch (InvalidWebhookSignatureException $exception) {
                $reason = $exception->getReason();
            }
        }

        return self::invalid($reason);
    }

    /**
     * Devolve o motivo da recusa por janela de tempo, ou null quando o carimbo está
     * dentro da tolerância (ou quando a tolerância está desligada com 0).
     */
    private function checkTimestamp(?string $xSignature): ?string
    {
        $tolerance = $this->settings->webhookToleranceSeconds();

        if ($tolerance <= 0) {
            return null;
        }

        $ts = self::timestampOf($xSignature);

        if ($ts === null) {
            // Cabeçalho ausente ou sem `ts`: o próprio validador do SDK recusa depois,
            // com o motivo específico. Aqui não há o que medir.
            return null;
        }

        $seconds = strlen($ts) >= 13 ? (int) round(((int) $ts) / 1000) : (int) $ts;

        return abs(Carbon::now()->getTimestamp() - $seconds) > $tolerance
            ? SignatureFailureReason::TIMESTAMP_OUT_OF_TOLERANCE
            : null;
    }

    /**
     * Valor de `ts=` no cabeçalho, só quando é uma sequência de dígitos.
     */
    private static function timestampOf(?string $xSignature): ?string
    {
        if ($xSignature === null || trim($xSignature) === '') {
            return null;
        }

        foreach (explode(',', $xSignature) as $part) {
            $pieces = explode('=', $part, 2);

            if (count($pieces) !== 2) {
                continue;
            }

            if (strtolower(trim($pieces[0])) === 'ts') {
                $value = trim($pieces[1]);

                return ctype_digit($value) ? $value : null;
            }
        }

        return null;
    }

    /**
     * @return array{valid: false, reason: string, lowercased_data_id: false}
     */
    private static function invalid(string $reason): array
    {
        return ['valid' => false, 'reason' => $reason, 'lowercased_data_id' => false];
    }
}
