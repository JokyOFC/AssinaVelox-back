<?php

namespace App\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Processador do Monolog que REDIGE por padrão (docs/seguranca-operacional.md §6).
 *
 * A regra do projeto é que nem token de convite, nem código por e-mail, nem senha, nem
 * endereço de e-mail, nem CPF/CNPJ podem descer para um arquivo de log. Confiar em cada
 * chamada de `Log::info()` para lembrar disso não funciona: basta um `report($exception)`
 * carregando a URL completa de `/assinar/{token}` para o segredo do convite ficar em
 * texto claro no disco — e em qualquer coletor para onde o log seja enviado.
 *
 * Por isso a redação é feita no fim da linha, sobre o registro já montado, em três frentes:
 *
 *  1. CHAVES conhecidas do contexto (`password`, `token`, `code`, `x-signature`, …) são
 *     substituídas inteiras, em qualquer profundidade.
 *  2. VALORES de texto passam por padrões: URL de convite, e-mail, CPF/CNPJ, `Bearer`,
 *     digest hexadecimal longo e sequências base64url compridas (a forma dos nossos
 *     tokens de 32 bytes).
 *  3. A própria MENSAGEM passa pelos mesmos padrões.
 *
 * O que NÃO é redigido, de propósito: identificador de correlação, ULID de recurso, nome
 * de classe, código de erro, contadores. Um log que esconde tudo não serve para operar.
 *
 * Limitação honesta: isto reduz vazamento acidental; não é um controle de acesso ao log.
 * Quem lê o arquivo continua vendo quais organizações e envelopes estiveram ativos.
 */
final class RedactSensitiveData implements ProcessorInterface
{
    /** Profundidade máxima percorrida em arrays aninhados (protege contra estrutura patológica). */
    private const MAX_DEPTH = 8;

    /** @var list<string> */
    private array $keys;

    /**
     * Nomes exatos que a regra de sufixo NÃO deve alcançar (códigos de
     * diagnóstico como `exit_code`). Conferidos antes de `$keys`.
     *
     * @var list<string>
     */
    private array $allowed;

    private string $placeholder;

    /**
     * @param  list<string>|null  $keys
     * @param  list<string>|null  $allowed
     */
    public function __construct(?array $keys = null, ?string $placeholder = null, ?array $allowed = null)
    {
        /** @var list<string> $configured */
        $configured = (array) config('assinavelox.observability.redaction.keys', []);
        /** @var list<string> $configuredAllowed */
        $configuredAllowed = (array) config('assinavelox.observability.redaction.allow_keys', []);

        $this->keys = array_map('strtolower', $keys ?? $configured);
        $this->allowed = array_map(
            static fn (string $key): string => str_replace(['-', ' '], '_', strtolower($key)),
            $allowed ?? $configuredAllowed,
        );
        $this->placeholder = $placeholder
            ?? (string) config('assinavelox.observability.redaction.placeholder', '[REDIGIDO]');
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        if (! (bool) config('assinavelox.observability.redaction.enabled', true)) {
            return $record;
        }

        return $record->with(
            message: $this->scrubText($record->message),
            context: $this->scrubArray($record->context),
            extra: $this->scrubArray($record->extra),
        );
    }

    /**
     * Redação pública (usada pelos testes e por quem monta payload antes de registrar).
     */
    public function redact(string $value): string
    {
        return $this->scrubText($value);
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public function scrubArray(array $data, int $depth = 0): array
    {
        if ($depth >= self::MAX_DEPTH) {
            return [$this->placeholder];
        }

        $result = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
                $result[$key] = $this->placeholder;

                continue;
            }

            $result[$key] = match (true) {
                is_array($value) => $this->scrubArray($value, $depth + 1),
                is_string($value) => $this->scrubText($value),
                default => $value,
            };
        }

        return $result;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower(str_replace(['-', ' '], '_', $key));

        if (in_array($normalized, $this->allowed, true)) {
            return false;
        }

        foreach ($this->keys as $sensitive) {
            $sensitive = str_replace('-', '_', $sensitive);

            if ($normalized === $sensitive || str_ends_with($normalized, '_'.$sensitive)) {
                return true;
            }
        }

        return false;
    }

    private function scrubText(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        $p = $this->placeholder;

        $patterns = [
            // Token do convite dentro da URL do signatário ou do convite de membro.
            '#(/(?:assinar|convites)/)[A-Za-z0-9_-]{16,}#i' => '$1'.$p,
            // `token=...`, `code=...`, `password=...` em query string ou corpo urlencoded.
            '#\b(token|code|otp|password|senha|secret|signature|access_token|api_key)=[^&\s"\']+#i' => '$1='.$p,
            // Cabeçalho Authorization.
            '#\b(Bearer|Basic)\s+[A-Za-z0-9._~+/=-]{8,}#i' => '$1 '.$p,
            // CNPJ e CPF, com ou sem pontuação (o CNPJ vem primeiro: é mais longo).
            '#\b\d{2}\.?\d{3}\.?\d{3}/?\d{4}-?\d{2}\b#' => '[documento]',
            '#\b\d{3}\.?\d{3}\.?\d{3}-?\d{2}\b#' => '[documento]',
            // Endereço de e-mail: sobra a inicial e o domínio de topo, o bastante para
            // reconhecer o caso no suporte sem publicar o endereço.
            '#([A-Za-z0-9])[A-Za-z0-9._%+-]*@[A-Za-z0-9.-]+\.([A-Za-z]{2,})#' => '$1***@***.$2',
        ];

        foreach ($patterns as $pattern => $replacement) {
            $value = (string) preg_replace($pattern, $replacement, $value);
        }

        // Sequências longas que só podem ser segredo: nossos tokens de convite são 32
        // bytes em base64url (43 caracteres, com maiúsculas, minúsculas e dígitos).
        $value = (string) preg_replace_callback(
            '#\b[A-Za-z0-9_-]{40,}\b#',
            fn (array $m): string => $this->looksLikeSecret($m[0]) ? $p : $m[0],
            $value,
        );

        return $value;
    }

    /**
     * Uma sequência longa só é tratada como segredo quando MISTURA classes de caractere.
     * Sem esse filtro, um nome de classe, um caminho de arquivo ou uma frase sem espaços
     * viraria `[REDIGIDO]` e o log perderia justamente o que serve para diagnosticar.
     */
    private function looksLikeSecret(string $candidate): bool
    {
        /*
         * Um SHA-256 em hexadecimal NÃO cai aqui de propósito (só dígitos e minúsculas).
         * O hash do documento é a evidência que o produto publica na página de verificação
         * — apagá-lo do log destruiria justamente o rastro que serve para conferir um
         * arquivo com o cliente ao telefone. Os digests que SÃO material de autenticação
         * (`token_digest`, `code_hash`) já são pegos pela regra de chaves.
         */
        return preg_match('/\d/', $candidate) === 1
            && preg_match('/[a-z]/', $candidate) === 1
            && preg_match('/[A-Z]/', $candidate) === 1;
    }
}
