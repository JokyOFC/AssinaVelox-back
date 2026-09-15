# SDK Python da API v1 da AssinaVelox

Cliente fino e tipado, gerado da especificação OpenAPI (`sdks/openapi/v1.json`). Python 3.10+, só biblioteca padrão (`urllib`, `dataclasses`).

Versão `1.0.0` — a versão maior acompanha a API (`/api/v1`).

## Instalação

Ainda não publicado no PyPI (decisão pendente do proprietário). A partir do repositório:

```bash
pip install ./sdks/python
```

## Uso

```python
import os

from assinavelox import ApiError, AssinaVelox, FileUpload

client = AssinaVelox(base_url="https://sua-instalacao.example/api/v1", token=os.environ["ASSINAVELOX_TOKEN"])

envelope = client.create_envelope({"title": "Contrato de locação — Apto 302"})
client.upload_document(envelope.id, FileUpload.from_path("contrato.pdf"))
client.sync_recipients(
    envelope.id,
    {"signing_order": "sequential", "recipients": [{"name": "Ana Souza", "email": "ana@example.com"}]},
)
sent = client.send_envelope(envelope.id)
print(sent.meta["invitations_sent"])

for item in client.list_envelopes(status=["in_progress"]).auto_paging_iter():
    print(item.display_code, item.status_label)

try:
    client.get_envelope("01J00000000000000000000000")
except ApiError as error:
    # RFC 9457: error.status, error.type, error.title, error.detail, error.errors
    print(error.slug, error.correlation_id)
```

- **Token.** `Authorization: Bearer` em toda chamada. O token não aparece em `repr()` nem em mensagens de erro.
- **Idempotency-Key.** Nas criações e no envio, o SDK gera um UUID v4 se você não passar uma chave. Para repetir com segurança depois de uma queda, guarde e reenvie a sua: `idempotency_key="pedido-42"`.
- **Erros.** Resposta de erro da API → `ApiError`, com os campos da RFC 9457 (`retry_after` no 429). Falha de rede → `NetworkError` (tempo esgotado: `RequestTimeoutError`). Pedido recusado pelo próprio SDK → `InvalidRequestError`.
- **Paginação.** `list_envelopes`, `list_events` e `list_templates` devolvem `Page`: `.data`, `.next_cursor`, `.next_page()` e `.auto_paging_iter()`.
- **Tempo limite.** `AssinaVelox(..., timeout=10)` ou, por chamada, `timeout=5`. É o limite de cada operação de rede (conexão e cada leitura).
- **Modelos.** Respostas viram dataclasses imutáveis (`models.Envelope`…), com os nomes de campo da API. A resposta original fica em `.raw`. Corpos de pedido são dicionários comuns, descritos por `TypedDict`.
- **Redirecionamentos** não são seguidos: o token nunca vai para outro endereço. O proxy segue as variáveis de ambiente padrão (`HTTPS_PROXY`, `NO_PROXY`).

## Webhooks de saída

```python
from assinavelox import WebhookSignatureError, webhooks

try:
    event = webhooks.construct_event(request.body, request.headers, os.environ["ASSINAVELOX_WEBHOOK_SECRET"])
except WebhookSignatureError:
    ...  # responda 400
```

`webhooks.verify_signature()` é o mesmo algoritmo do servidor (HMAC-SHA256 de `"{timestamp}.{corpo}"`, janela de 300 s, `hmac.compare_digest`, duas assinaturas durante a rotação do segredo). Passe o corpo **bruto** (bytes), antes de decodificar o JSON.

## Experimentar sem a API real

```bash
python tools/sdkgen/fake_server.py --port 8765
```

Use `base_url="http://127.0.0.1:8765/api/v1"` e qualquer token. O exemplo acima é o teste `test_exemplo_do_readme` em `tests/test_client.py`.

## Testes

```bash
cd sdks/python && python -m unittest discover -s tests -t .   # sobe o servidor falso sozinho
```

`assinavelox/_client.py`, `assinavelox/models.py`, `assinavelox/_version.py` e `tests/test_operations_generated.py` são gerados: não edite, rode `python tools/sdkgen/sdkgen.py generate`.
