# SDK PHP da API v1 da AssinaVelox

Cliente fino e tipado, gerado da especificação OpenAPI (`sdks/openapi/v1.json`). PHP 8.1+, sem dependências: usa a extensão curl quando existe e, sem ela, os streams do PHP.

Versão `1.0.0` — a versão maior acompanha a API (`/api/v1`).

## Instalação

Ainda não publicado no Packagist (decisão pendente do proprietário). Enquanto isso:

```php
require 'caminho/para/sdks/php/autoload.php'; // PSR-4 AssinaVelox\Sdk\ → src/
```

Ou, com o Composer, um repositório `path` apontando para `sdks/php` (o `composer.json` declara o mesmo autoload).

## Uso

```php
use AssinaVelox\Sdk\Client;
use AssinaVelox\Sdk\Exception\ApiException;
use AssinaVelox\Sdk\FileUpload;

$client = new Client('https://sua-instalacao.example/api/v1', getenv('ASSINAVELOX_TOKEN'));

$envelope = $client->createEnvelope(['title' => 'Contrato de locação — Apto 302']);
$client->uploadDocument($envelope->id, FileUpload::fromPath('contrato.pdf'));
$client->syncRecipients($envelope->id, [
    'signing_order' => 'sequential',
    'recipients' => [['name' => 'Ana Souza', 'email' => 'ana@example.com']],
]);
$sent = $client->sendEnvelope($envelope->id);
echo $sent->meta['invitations_sent'];

foreach ($client->listEnvelopes(['status' => ['in_progress']])->autoPagingIterator() as $item) {
    echo $item->displayCode, ' ', $item->statusLabel, PHP_EOL;
}

try {
    $client->getEnvelope('01J00000000000000000000000');
} catch (ApiException $error) {
    // RFC 9457: $error->status, $error->type, $error->title, $error->detail, $error->errors
    echo $error->slug(), ' ', $error->correlationId;
}
```

- **Token.** `Authorization: Bearer` em toda chamada. O texto do token nunca aparece em `var_dump()`/`print_r()` nem em mensagens de erro, e no PHP 8.2+ é omitido dos rastros de pilha.
- **Idempotency-Key.** Nas criações e no envio, o SDK gera um UUID v4 se você não passar uma chave. Para repetir com segurança depois de uma queda, guarde e reenvie a sua: `new RequestOptions(idempotencyKey: 'pedido-42')`.
- **Erros.** Resposta de erro da API → `Exception\ApiException`, com os campos da RFC 9457. Falha de rede → `Exception\NetworkException` (tempo esgotado: `Exception\TimeoutException`). Pedido recusado pelo próprio SDK → `Exception\InvalidRequestException`.
- **Paginação.** `listEnvelopes`, `listEvents` e `listTemplates` devolvem `Page`: `->data`, `->nextCursor()`, `->nextPage()` e `->autoPagingIterator()`.
- **Tempo limite.** `new Client($url, $token, timeout: 10.0)` ou, por chamada, `new RequestOptions(timeout: 5.0)`.
- **Modelos.** Respostas viram objetos `Model\*` com propriedades somente leitura em camelCase (`$envelope->displayCode`). A resposta original fica em `->raw`, com os campos que esta versão ainda não conhece.
- **Redirecionamentos** não são seguidos: o token nunca vai para outro endereço.

## Webhooks de saída

```php
use AssinaVelox\Sdk\Webhook\WebhookSignature;

$event = WebhookSignature::constructEvent(
    file_get_contents('php://input'),  // corpo BRUTO, antes do json_decode
    getallheaders(),
    getenv('ASSINAVELOX_WEBHOOK_SECRET'),
);
// deduplique por X-AssinaVelox-Delivery-Id; responda 2xx rápido
```

`WebhookSignature::verify()` é o mesmo algoritmo do servidor (HMAC-SHA256 de `"{timestamp}.{corpo}"`, janela de 300 s, comparação em tempo constante, duas assinaturas durante a rotação do segredo).

## Experimentar sem a API real

O servidor falso dos testes responde pela especificação:

```bash
python tools/sdkgen/fake_server.py --port 8765
```

Use `new Client('http://127.0.0.1:8765/api/v1', 'qualquer-token')`. O exemplo acima é o teste "exemplo do README" em `tests/client_test.php`.

## Testes

```bash
python tools/sdkgen/sdkgen.py test                                  # os três SDKs
FAKE_API_URL=http://127.0.0.1:8765/api/v1 php sdks/php/tests/run.php  # só este, com o servidor já de pé
```

Os testes rodam com os dois transportes (curl e streams). `src/Client.php`, `src/Version.php`, `src/Model/*` e `tests/generated_operations.php` são gerados: não edite, rode `python tools/sdkgen/sdkgen.py generate`.
