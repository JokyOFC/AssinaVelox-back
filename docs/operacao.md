# AssinaVelox — Operação

> Manual de plantão: **o que observar**, **como diagnosticar** as falhas prováveis, **como
> reprocessar sem estragar evidência** e **o que fazer quando o certificado A1 vence**.
>
> Este documento descreve o comportamento do sistema como ele está implementado. Nada aqui foi
> exercitado em um servidor de produção — o sistema nunca foi implantado. Trate os primeiros
> plantões como calibração.
>
> Implantação e infraestrutura: `docs/implantacao.md`. Configuração: `docs/configuracao.md`.

## Sumário

1. [Painel de plantão](#1-painel-de-plantão)
2. [O que monitorar](#2-o-que-monitorar)
3. [Diagnóstico das falhas prováveis](#3-diagnóstico-das-falhas-prováveis)
4. [Reprocessamento seguro](#4-reprocessamento-seguro)
5. [Rotinas](#5-rotinas)
6. [Certificado A1: vencimento e troca](#6-certificado-a1-vencimento-e-troca)
7. [Regras que não se quebram](#7-regras-que-não-se-quebram)

---

## 1. Painel de plantão

Quatro comandos e um `curl`. Se todos passarem, o sistema está de pé.

```bash
cd /var/www/assinavelox

curl -sf https://app.assinavelox.com.br/up && echo "  ← web OK"

sudo -u assinavelox php artisan assinavelox:doctor          # --json para monitoramento, --strict para CI
sudo -u assinavelox php artisan pdftool:selftest            # pipeline de PDF (Python, assinatura, validação)
sudo -u assinavelox php artisan queue:failed | head -20     # jobs que falharam
systemctl is-active assinavelox-horizon assinavelox-schedule.timer
```

`assinavelox:doctor` verifica configuração da aplicação, banco, disco de documentos, fila e
agendador, e-mail, pdftool, LibreOffice, certificado, Mercado Pago, criptografia em repouso e
checkpoints de auditoria — e **nunca imprime segredos**. Os limiares dos indicadores ficam em
`config('assinavelox.observability.health')` (`ASSINAVELOX_HEALTH_*`). A referência detalhada dos
controles de segurança (cabeçalhos, limitadores, mascaramento no registro, checkpoint de
auditoria, criptografia em repouso) está em [`docs/seguranca-operacional.md`](seguranca-operacional.md).

Nenhum desses comandos substitui olhar o log:

```bash
sudo tail -f /var/www/assinavelox/storage/logs/laravel-$(date +%Y-%m-%d).log
sudo journalctl -u assinavelox-horizon -f
```

---

## 2. O que monitorar

### 2.1 Fila e trabalhos falhos

Em produção a fila é Redis + Horizon. O painel `/horizon` mostra taxa de processamento, tempo de
espera por fila e jobs falhos; o alerta deve vir de fora dele.

| Sinal                               | Onde                                      | Limiar sugerido | Significa                                                        |
| ----------------------------------- | ----------------------------------------- | --------------- | ---------------------------------------------------------------- |
| Horizon parado                      | `systemctl is-active assinavelox-horizon` | qualquer parada | **Nada assíncrono acontece**: conversão, finalização, e-mail.    |
| Espera na fila `finalization`       | `/horizon` → Métricas                     | > 5 min         | Documentos concluídos demorando a ficar prontos.                 |
| Espera na fila `conversions`        | `/horizon`                                | > 5 min         | Uploads presos em "processando".                                 |
| Jobs falhos nas últimas 24 h        | `php artisan queue:failed`                | > 0             | Sempre investigue. Nenhum job deste sistema falha "normalmente". |
| Backlog (driver `database`, só dev) | `SELECT COUNT(*) FROM jobs`               | > 100           | `ASSINAVELOX_HEALTH_QUEUE_BACKLOG`.                              |

As cinco filas e o que cada uma carrega:

| Fila            | Conteúdo                                | Se parar…                                              |
| --------------- | --------------------------------------- | ------------------------------------------------------ |
| `default`       | jobs gerais                             | —                                                      |
| `conversions`   | DOCX/imagem → PDF                       | Documento fica em `converting` para sempre.            |
| `notifications` | convites, avisos de prazo, conclusão    | Signatário nunca recebe o link.                        |
| `finalization`  | consolidação, evidências, assinatura A1 | Envelope fica em `finalizing` e ninguém baixa o final. |
| `billing`       | conciliação de pagamento                | Pagamento aprovado não ativa o plano.                  |

> **`retry_after` × `timeout` dos jobs.** Se um job demorar mais que o `retry_after` da conexão,
> a fila o devolve para processamento **enquanto a primeira execução ainda roda**. `FinalizeEnvelope`
> tem `timeout = 600 s`, e durante toda a Fase 1 o padrão de `retry_after` foi **90 s** — ou seja,
> a finalização era reenfileirada no meio. O finalizador é idempotente e serializa por lock
> (`docs/finalizacao-e-evidencias.md` §6), então o efeito era trabalho desperdiçado, não documento
> corrompido; ainda assim era trabalho desperdiçado no ponto mais caro do sistema.
>
> **Corrigido no fechamento da Fase 1:** o padrão de `DB_QUEUE_RETRY_AFTER` e
> `REDIS_QUEUE_RETRY_AFTER` passou para **900 s**, e o `supervisor-finalization` do Horizon passou
> de 300 para 600, igual ao `timeout` do job. `tests/Feature/Hardening/QueueTimeoutsTest.php` falha
> se algum supervisor ou job voltar a passar do `retry_after` da sua conexão. Ao acrescentar um job
> mais demorado, suba o `retry_after` junto — o teste avisa.

### 2.2 Conversão de documentos

```sql
-- Documentos parados em conversão
SELECT id, ulid, envelope_id, source_type, processing_status, failure_code, updated_at
FROM documents
WHERE processing_status = 'converting'
  AND updated_at < NOW() - INTERVAL 30 MINUTE;

-- Falhas por motivo, últimos 7 dias
SELECT failure_code, COUNT(*) AS total
FROM documents
WHERE processing_status IN ('failed','blocked')
  AND updated_at > NOW() - INTERVAL 7 DAY
GROUP BY failure_code ORDER BY total DESC;
```

Estados: `uploaded` → `converting` → `ready` | `failed` | `blocked`. **`blocked` não é erro**: é
um PDF cifrado, já assinado digitalmente ou inválido — o original é preservado e o usuário recebe
a explicação. Um pico de `blocked` é informação sobre os arquivos dos clientes, não sobre o
servidor. Um pico de `failed` é problema seu.

Limiar: `ASSINAVELOX_HEALTH_CONVERSION_STUCK_MINUTES` (padrão 30).

### 2.3 Finalização

```sql
-- Envelopes presos em finalização
SELECT id, number, organization_id, status, updated_at
FROM envelopes
WHERE status = 'finalizing'
  AND updated_at < NOW() - INTERVAL 30 MINUTE;

-- Concluídos sem registro de verificação (não deveria existir)
SELECT e.id, e.number
FROM envelopes e
LEFT JOIN verification_records v ON v.envelope_id = e.id
WHERE e.status = 'completed' AND v.id IS NULL;

-- Concluídos sem arquivo final vinculado
SELECT id, code, envelope_id
FROM verification_records
WHERE final_document_version_id IS NULL;
```

A segunda e a terceira consulta devem devolver **zero linhas** fora de uma janela de segundos
durante a finalização. Qualquer linha persistente é um envelope que a interface mostra como
concluído e cuja verificação pública não tem o que entregar.

Limiar: `ASSINAVELOX_HEALTH_FINALIZATION_STUCK_MINUTES` (padrão 30).

### 2.4 Entrega de e-mail

**"Enviado" não é "entregue".** A tabela `delivery_attempts` registra o que o transporte
respondeu, nada mais:

| `status`    | Significa                                                                                            |
| ----------- | ---------------------------------------------------------------------------------------------------- |
| `queued`    | Job criado, ainda não despachado.                                                                    |
| `sent`      | O transporte **aceitou** a mensagem. Não prova entrega.                                              |
| `unknown`   | Resposta inconclusiva — mailer `log`/`array`. **Não é sucesso.**                                     |
| `failed`    | Recusa explícita do transporte.                                                                      |
| `delivered` | Só com evidência do provedor. **Nunca é atingido na Fase 1**: não há webhook de entrega configurado. |
| `bounced`   | Idem — nunca atingido na Fase 1.                                                                     |

```sql
-- Falhas de entrega nas últimas 24 h
SELECT purpose, provider, status, COUNT(*) AS total, MAX(error_message) AS exemplo
FROM delivery_attempts
WHERE created_at > NOW() - INTERVAL 1 DAY
GROUP BY purpose, provider, status;

-- Preso em queued/unknown
SELECT id, ulid, purpose, to_address, status, created_at
FROM delivery_attempts
WHERE status IN ('queued','unknown')
  AND created_at < NOW() - INTERVAL 60 MINUTE;
```

**`unknown` em produção quase sempre significa `MAIL_MAILER=log` esquecido** — o sistema está
escrevendo e-mails no arquivo de log em vez de enviá-los. Confira antes de investigar qualquer
outra coisa: `php artisan tinker --execute="echo config('mail.default');"`.

Limiar: `ASSINAVELOX_HEALTH_DELIVERY_STUCK_MINUTES` (padrão 60).

Fora do sistema, monitore o que ele não vê: taxa de rejeição no painel do provedor de e-mail,
SPF/DKIM/DMARC do domínio e reputação do IP de envio. Uma queda de entrega raramente aparece
primeiro aqui.

### 2.5 Conciliação de pagamento

```sql
-- Webhooks recebidos e não processados
SELECT id, topic, action, processing_status, signature_valid, received_at, error
FROM payment_webhook_receipts
WHERE processing_status = 'received'
  AND received_at < NOW() - INTERVAL 30 MINUTE
ORDER BY received_at;

-- Assinatura inválida = tentativa de forjar OU segredo errado
SELECT COUNT(*) FROM payment_webhook_receipts
WHERE signature_valid = 0 AND received_at > NOW() - INTERVAL 1 DAY;

-- Assinaturas em carência ou vencidas
SELECT status, COUNT(*) FROM subscriptions GROUP BY status;
```

Estados do recibo: `received` → `processed` | `ignored` | `failed`. **`ignored` é normal**: tópicos
que reconhecemos e não usamos (`merchant_order`, contestações) são gravados e descartados de
propósito.

Sinal grave: **muitos `signature_valid = 0`**. Ou o `MERCADOPAGO_WEBHOOK_SECRET` está errado (e
nenhum pagamento está sendo conciliado), ou alguém está tentando forjar notificação. Nos dois
casos a aplicação responde 401 e **não processa nada** — o dinheiro não se move, mas o plano do
cliente que pagou também não é ativado.

Limiar: `ASSINAVELOX_HEALTH_WEBHOOK_STUCK_MINUTES` (padrão 30).

### 2.6 Expirações e agendador

```bash
sudo -u assinavelox php artisan schedule:list
systemctl list-timers assinavelox-schedule.timer
```

```sql
-- Vencidos que ainda não foram expirados: o agendador não está rodando
SELECT id, number, status, expires_at
FROM envelopes
WHERE status IN ('ready','in_progress')
  AND expires_at < NOW() - INTERVAL 1 HOUR;
```

Se essa consulta devolve linhas, `envelopes:expire` não rodou. A expiração também é revalidada a
cada acesso do signatário, então o signatário vê o estado certo — mas a lista do remetente, os
contadores e os avisos de prazo ficam errados, e `billing:dunning` (diário) provavelmente também
não rodou.

### 2.7 Espaço em disco

O crescimento é dominado por `storage/app/documents` — cada envelope guarda o original, a versão
convertida e o arquivo final consolidado.

```bash
df -h /var/www/assinavelox /var/backups
du -sh /var/www/assinavelox/storage/app/documents
du -sh /var/www/assinavelox/storage/app/tmp        # deve ser pequeno e voltar a zero
du -sh /var/www/assinavelox/storage/logs
```

Alerte em **80%** e trate como incidente em **90%**. Encher o disco quebra tudo de uma vez:
upload, conversão, finalização, log e o próprio MySQL.

**`storage/app/tmp/pdftool` deve estar praticamente vazio.** Cada operação cria um diretório
exclusivo (permissão 0700) e o remove em `finally`. Sobras acumuladas significam processos mortos
no meio — e a sobra pode conter páginas do documento do cliente. Limpe só o que estiver
comprovadamente órfão:

```bash
sudo find /var/www/assinavelox/storage/app/tmp/pdftool -mindepth 1 -maxdepth 1 \
     -type d -mmin +120 -exec rm -rf {} +
```

Purga de dados antigos **não existe na Fase 1**: nada é apagado automaticamente. Retenção
configurável é item da Fase 2 (`docs/roadmap.md` §2.19). Planeje o disco para crescer.

### 2.8 Validade do certificado A1

```sql
SELECT name, environment, subject, not_after, is_active,
       DATEDIFF(not_after, NOW()) AS dias_restantes
FROM certificate_references
WHERE kind = 'company_a1'
ORDER BY not_after DESC;
```

`assinavelox:doctor` avisa a partir de `ASSINAVELOX_HEALTH_CERT_WARNING_DAYS` (padrão 30). Trate
**90 dias** como o momento de iniciar a renovação — ver §6.

### 2.9 Alerta mínimo

Se você só puder configurar cinco alertas:

1. `/up` não responde 200.
2. `assinavelox-horizon` inativo.
3. `queue:failed` > 0.
4. Disco > 85%.
5. Certificado A1 vence em menos de 30 dias.

`assinavelox:doctor --json --strict` cobre 2, 4 e 5 e devolve exit code diferente de zero — é o
caminho mais curto para um _check_ externo.

---

## 3. Diagnóstico das falhas prováveis

### 3.1 Documento preso em "processando"

**Sintoma.** O usuário envia um DOCX ou uma imagem e a tela fica no indicador indeterminado.
`documents.processing_status = 'converting'` há mais de 30 minutos.

O caminho é: upload → `ProcessDocumentUpload` na fila `conversions` → conversor por tipo (DOCX =
LibreOffice; imagem = `pdftool image2pdf`; PDF = passthrough) → `ready`.

**Investigue nesta ordem** — pare no primeiro que falhar:

```bash
# 1. O worker está de pé?
systemctl is-active assinavelox-horizon

# 2. O job falhou e virou failed_job?
sudo -u assinavelox php artisan queue:failed | grep -i ProcessDocumentUpload

# 3. O pipeline de PDF está funcional? (venv apagado num deploy é a causa mais comum)
sudo -u assinavelox-worker php artisan pdftool:selftest

# 4. O LibreOffice está configurado e responde?
sudo -u assinavelox php artisan tinker --execute="var_dump(config('pdftool.libreoffice.binary'));"
sudo -u assinavelox-worker timeout 60 /usr/bin/soffice --headless --version

# 5. Há espaço e permissão no temporário?
df -h /var/www/assinavelox
sudo -u assinavelox-worker touch /var/www/assinavelox/storage/app/tmp/pdftool/.teste && echo "escrita OK"
```

| Causa                                     | Como se apresenta                                           | Correção                                                               |
| ----------------------------------------- | ----------------------------------------------------------- | ---------------------------------------------------------------------- |
| Horizon parado                            | Nada na fila avança, várias filas paradas ao mesmo tempo    | `systemctl start assinavelox-horizon`; os jobs retomam sozinhos.       |
| `.venv` do pdftool ausente (deploy)       | `pdftool:selftest` falha; toda operação de PDF quebra       | Recriar o venv (`docs/implantacao.md` §6).                             |
| `LIBREOFFICE_BIN` vazio ou binário errado | Só DOCX falha; imagens e PDFs passam                        | Apontar o `soffice` correto; `config:cache`; `reload` do Horizon.      |
| Timeout do LibreOffice                    | Job falha com `timeout` depois de 120 s; documentos grandes | Subir `LIBREOFFICE_TIMEOUT_SECONDS`; conferir CPU do servidor.         |
| Perfil isolado sem permissão de escrita   | `soffice` sai com erro imediato                             | Corrigir permissões de `storage/app/tmp` para o usuário do worker.     |
| **Job travou sem falhar**                 | `converting` parado, nada em `failed_jobs`, worker vivo     | O `uniqueFor` de 900 s solta a trava; depois disso, reprocesse (§4.2). |
| Disco cheio                               | Falhas simultâneas em tudo                                  | Liberar espaço antes de qualquer reprocessamento.                      |

**Antes de reprocessar**, confirme que o documento não terminou: se `processing_status` já é
`ready`, o problema é o _polling_ da interface, não a conversão — recarregar a página resolve.

### 3.2 Finalização falhando

**Sintoma.** Todos os signatários aceitaram, o envelope está em `finalizing` e não sai.

O pipeline é: consolidar (compose dos campos) → gerar a página de evidências (Blade → DOMPDF) →
anexar ao documento → assinar com o A1 **se configurado** → calcular o hash final → gravar o
`verification_record` → `completed`. Ele é **idempotente e retomável**: cada etapa registra o que
concluiu, e uma nova tentativa continua de onde parou em vez de refazer tudo.

```bash
# O que a última tentativa disse
sudo grep -i "Finalização do envelope" \
  /var/www/assinavelox/storage/logs/laravel-*.log | tail -20

sudo -u assinavelox php artisan queue:failed | grep -i FinalizeEnvelope
sudo -u assinavelox php artisan queue:failed --json | jq '.[] | select(.name|test("Finalize"))'
```

| Causa                                | Sinal                                                        | Correção                                                                                                                                         |
| ------------------------------------ | ------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------ |
| pdftool indisponível                 | `pdftool:selftest` falha                                     | Recriar o venv; reprocessar (§4.3).                                                                                                              |
| Certificado ligado e mal configurado | `SignerNotConfiguredException`, ou senha ausente no ambiente | **A causa mais comum:** `config:cache` faz o Laravel ignorar o `.env`, e a senha do PFX precisa estar no `EnvironmentFile` do Horizon. Ver §3.5. |
| PFX vencido                          | `pdftool sign` recusa                                        | §6. Enquanto isso, considere concluir sem assinatura (§6.4).                                                                                     |
| PDF de origem cifrado ou corrompido  | `encrypted_pdf` / `invalid_pdf` no log                       | O documento não pode ser assinado; o envelope precisa ser refeito com um arquivo válido.                                                         |
| Fonte sem cobertura Unicode          | Acentos viram `?` na página de evidências                    | `ASSINAVELOX_EVIDENCE_FONT` deve continuar `DejaVu Sans`.                                                                                        |
| Timeout do job                       | Job falha aos 600 s                                          | Documento muito grande ou servidor saturado. Não suba o timeout sem entender por quê.                                                            |
| Disco cheio                          | Falha ao gravar a versão final                               | Liberar espaço, depois reprocessar.                                                                                                              |

**Nunca "conclua" um envelope na mão** com um `UPDATE envelopes SET status='completed'`. Sem o
arquivo final, sem o hash e sem o `verification_record`, a verificação pública fica sem lastro e
a página de evidências mente. Se a finalização não pode ser concluída, o envelope fica onde está
e o problema é resolvido; não há atalho.

### 3.3 Webhook não chegando

**Sintoma.** O cliente pagou, o Mercado Pago mostra aprovado, e o plano não ativou.

Isto é esperado por desenho: **o retorno do checkout não ativa o plano** — só o webhook
autenticado ativa (`docs/cobranca.md` §3). O cliente voltar para a tela de sucesso não é prova de
pagamento.

```sql
-- Chegou alguma coisa?
SELECT COUNT(*), MAX(received_at) FROM payment_webhook_receipts
WHERE received_at > NOW() - INTERVAL 1 DAY;

-- Chegou e foi recusado por assinatura?
SELECT id, topic, action, signature_valid, processing_status, error, received_at
FROM payment_webhook_receipts
ORDER BY received_at DESC LIMIT 20;
```

Árvore de decisão:

**Nenhum recibo nas últimas 24 h** — a notificação não chega ao servidor:

1. `MERCADOPAGO_NOTIFICATION_URL` precisa ser HTTPS, pública, com no máximo 248 caracteres e sem
   `localhost`. Confira o valor efetivo no painel do Mercado Pago **e** no `.env`.
2. A URL responde de fora? `curl -i -X POST https://app.assinavelox.com.br/webhooks/mercadopago`
   deve devolver **401** (sem assinatura), nunca 404 nem 502. 404 significa rota errada; 502,
   PHP-FPM fora.
3. Firewall/WAF bloqueando a origem.
4. `MERCADOPAGO_DRIVER` em `fake` — nesse caso nada real acontece, de propósito.

**Recibos com `signature_valid = 0`** — chega e é recusado:

1. `MERCADOPAGO_WEBHOOK_SECRET` errado ou vazio. **É por ambiente**: o segredo do sandbox não vale
   em produção. Sem ele, a resposta é 401 e nada é processado.
2. Relógio do servidor fora de sincronia: a assinatura tem tolerância de
   `MERCADOPAGO_WEBHOOK_TOLERANCE_SECONDS` (300 s). `timedatectl` deve mostrar
   `System clock synchronized: yes`.

**Recibos `received` que não viram `processed`** — chega, autentica, e trava:

1. A fila `billing` está parada (`SyncMercadoPagoPayment`). Ver §2.1.
2. A consulta ao Mercado Pago está falhando (timeout, credencial revogada): o job tem 5
   tentativas com espera crescente até 15 min.
3. `MERCADOPAGO_ENVIRONMENT` não bate com o `live_mode` da credencial — a aplicação recusa de
   propósito, para nunca misturar sandbox com produção.

**Reprocessar é seguro.** A ativação é idempotente pela impressão do evento
(`provider` + `event_fingerprint`, com índice único): reenviar a mesma notificação pelo painel do
Mercado Pago não cobra nem ativa duas vezes.

### 3.4 E-mail não entregue

**Sintoma.** O signatário diz que não recebeu o convite.

```sql
SELECT id, ulid, purpose, to_address, status, provider, error_message, created_at, sent_at
FROM delivery_attempts
WHERE to_address = 'pessoa@exemplo.com'
ORDER BY created_at DESC LIMIT 10;
```

| O que a tabela diz | O que aconteceu                    | O que fazer                                                                                    |
| ------------------ | ---------------------------------- | ---------------------------------------------------------------------------------------------- |
| Nenhuma linha      | A notificação nunca foi despachada | Fila `notifications` parada, ou o envio falhou antes de enfileirar. Use "Lembrar pendentes".   |
| `queued`           | Enfileirado e não processado       | Ver §2.1.                                                                                      |
| `unknown`          | O mailer não transmite nada        | **`MAIL_MAILER=log` em produção.** Corrija e reenvie.                                          |
| `failed`           | O transporte recusou               | Leia `error_message`: autenticação, TLS, destinatário inválido, limite do provedor.            |
| `sent`             | O transporte **aceitou**           | O problema está fora daqui: caixa de spam, filtro corporativo, endereço com erro de digitação. |

Com `sent`, o rastro continua no painel do provedor de e-mail, pelo `provider_message_id`. O
sistema não sabe mais do que isso: não há webhook de entrega na Fase 1, então `delivered` nunca
aparece — e a interface não deve dar a entender que aparece.

Reenvio pela interface: "Lembrar" no destinatário (mínimo de 10 minutos entre reenvios,
`ASSINAVELOX_MAX_RESENDS` no total) ou "Lembrar todos os pendentes" (1× por hora por organização).
Prefira sempre a interface ao reprocessamento manual: ela respeita os limites, registra na trilha
e reemite o link corretamente.

**O link do convite nunca é recuperável do banco.** Só o digest é guardado. Se o signatário perdeu
o e-mail, a única saída é reenviar.

### 3.5 O certificado "sumiu" depois de um deploy

Sintoma clássico e vale um item próprio: a finalização passa a concluir como _aceite eletrônico
com evidências_ (`signature_status = none`) sem ninguém ter mudado nada.

Quase sempre é isto: **`php artisan config:cache` faz o Laravel parar de ler o `.env`**. A senha do
PFX é lida do **ambiente do processo**, sob o nome guardado em `COMPANY_CERT_PASSWORD_ENV`. Se ela
estava só no `.env`, o worker não a enxerga mais.

```bash
sudo -u assinavelox php artisan pdftool:selftest        # mostra o estado do certificado
sudo systemctl show assinavelox-horizon -p EnvironmentFiles
sudo -u assinavelox-worker ls -l /var/lib/assinavelox/certs/operadora.pfx
```

Correção: a variável de senha vai no `EnvironmentFile` da unit do Horizon
(`/etc/assinavelox/worker.env`), o arquivo `.pfx` fica legível pelo usuário do worker, e depois
`systemctl restart assinavelox-horizon` + `pdftool:selftest`.

**O comportamento em si está correto.** Sem certificado, o sistema não simula assinatura: ele
conclui como aceite eletrônico com evidências e a interface diz isso. O defeito é a configuração,
não a saída.

---

## 4. Reprocessamento seguro

### 4.1 Regras

1. **Diagnostique antes.** Reprocessar sem corrigir a causa só repete a falha e polui a trilha.
2. **Prefira a interface.** Reenvio de convite, lembrete e cancelamento têm caminho próprio, com
   limites e auditoria. Um `UPDATE` na mão não tem.
3. **Nunca altere `audit_events`, `signature_acceptances`, `document_versions` ou
   `verification_records`.** São tabelas de evidência: a aplicação só insere e lê, e o usuário
   MySQL de runtime não tem permissão de `UPDATE`/`DELETE` em `audit_events`. Se você precisar
   editar uma dessas tabelas para "consertar" alguma coisa, pare: o conserto está errado.
4. **Reprocessar é quase sempre seguro** porque os jobs deste sistema são idempotentes — a
   finalização retoma de onde parou, a conversão sobrescreve a versão derivada, a conciliação de
   pagamento é chaveada pela impressão do evento.

### 4.2 Documento preso na conversão

```bash
# Um documento específico (o id numérico vem da consulta do §2.2)
sudo -u assinavelox php artisan tinker
```

```php
$doc = App\Models\Document::withoutOrganizationScope()->findOrFail(123);
$doc->processing_status;   // confirme que ainda é 'converting'

App\Jobs\Documents\ProcessDocumentUpload::dispatch($doc->id, $doc->organization_id);
```

A trava de unicidade (`uniqueFor = 900 s`) impede duas execuções simultâneas do mesmo documento —
se o _dispatch_ parecer não fazer nada, é porque a trava anterior ainda está de pé. Espere e
repita.

### 4.3 Envelope preso na finalização

```php
$env = App\Models\Envelope::withoutOrganizationScope()->findOrFail(456);
$env->status;   // 'finalizing'

App\Jobs\Envelopes\FinalizeEnvelope::dispatch($env->id, $env->organization_id);
```

O pipeline retoma da etapa incompleta: se a consolidação e a página de evidências já existem, ele
não as refaz. `uniqueFor = 1800 s`.

### 4.4 Jobs falhos

```bash
sudo -u assinavelox php artisan queue:failed             # veja o uuid e a exceção
sudo -u assinavelox php artisan queue:retry <uuid>       # um
sudo -u assinavelox php artisan queue:retry all          # todos — só depois de corrigir a causa
sudo -u assinavelox php artisan queue:forget <uuid>      # descartar um job que não deve repetir
```

Não rode `queue:retry all` sem ler a lista. Um deploy revertido costuma deixar jobs com payload
que o código atual não entende: repeti-los em massa gera um segundo lote de falhas e esconde as
originais.

### 4.5 Webhook de pagamento

Reenvie pelo painel do Mercado Pago (é o caminho suportado, e é idempotente). Para reprocessar um
recibo já gravado:

```php
$r = App\Models\PaymentWebhookReceipt::findOrFail(789);
$r->processing_status;                 // 'received' ou 'failed'
$idNoProvedor = (string) data_get($r->payload, 'data.id');

// Redespacha a conciliação. O primeiro argumento é o id do pagamento NO PROVEDOR
// (string); o segundo, opcional, liga o resultado de volta a este recibo.
App\Jobs\Billing\SyncMercadoPagoPayment::dispatch($idNoProvedor, $r->id);
```

**Nunca ative um plano na mão.** `subscriptions` e `plan_consumptions` são o ledger da cobrança;
uma linha inventada desalinha a cota e a inadimplência, e o próximo `billing:dunning` faz algo
inesperado. Se um cliente pagou e o webhook não chega, o caminho é consertar o webhook.

### 4.6 Reexecutar um comando agendado

Todos são idempotentes e podem ser rodados à mão:

```bash
sudo -u assinavelox php artisan envelopes:expire
sudo -u assinavelox php artisan envelopes:notify-expiring
sudo -u assinavelox php artisan billing:dunning
```

`envelopes:expire` processa `ASSINAVELOX_EXPIRATION_BATCH_SIZE` (200) por execução: depois de uma
parada longa, rode várias vezes até a consulta do §2.6 zerar. `envelopes:notify-expiring` marca
`settings.expiring_warned_at` e por isso não avisa duas vezes.

---

## 5. Rotinas

### Diária (5 minutos)

- [ ] `assinavelox:doctor` sem falhas.
- [ ] `queue:failed` vazio.
- [ ] Envelopes em `finalizing` e documentos em `converting` há mais de 30 min: zero.
- [ ] Webhooks `received` parados: zero.
- [ ] Disco abaixo de 80%.
- [ ] O backup de ontem existe e tem tamanho plausível.

### Semanal

- [ ] Falhas de entrega de e-mail por motivo (§2.4); reputação no painel do provedor.
- [ ] `documents.failure_code` agrupado: um código novo em alta é um bug ou um formato novo.
- [ ] `signature_valid = 0` em `payment_webhook_receipts`.
- [ ] Crescimento de `storage/app/documents` — projete quando o disco acaba.
- [ ] `storage/app/tmp/pdftool` vazio.
- [ ] `certbot renew --dry-run`.

### Mensal

- [ ] `dias_restantes` do certificado A1 (§2.8).
- [ ] Atualizações de segurança do sistema (`unattended-upgrades` ou `apt upgrade` em janela).
- [ ] `composer audit` e `npm audit` no ambiente de desenvolvimento; avalie o que atualizar.
- [ ] Revisão dos acessos: quem tem `sudo` no servidor, quem é _platform admin_ no `/admin`.

### Trimestral

- [ ] **Ensaio de restauração completo** em servidor de teste, com o script de coerência
      (`docs/implantacao.md` §14.4) terminando em exit 0. Sem esse ensaio, o backup é uma hipótese.
- [ ] Revisar limiares de alerta contra o que realmente aconteceu no trimestre.

---

## 6. Certificado A1: vencimento e troca

Um A1 costuma valer 1 ano. O vencimento **não invalida** os documentos já assinados — invalida a
sua capacidade de assinar novos. Mas há uma consequência que é fácil ignorar: **sem a cadeia da AC
e sem o certificado antigo guardados, a validação das assinaturas antigas fica prejudicada**. Não
há LTV/DSS embutido no arquivo e não há carimbo do tempo (o perfil é PAdES **B-B**), então a
verificação futura depende do que você guardou.

### 6.1 Linha do tempo

| Quando falta | O que fazer                                                                                            |
| ------------ | ------------------------------------------------------------------------------------------------------ |
| **90 dias**  | Iniciar a renovação com a AC. A emissão de A1 exige validação presencial ou por vídeo e leva dias.     |
| **30 dias**  | `assinavelox:doctor` começa a avisar. O novo `.pfx` já deveria estar em mãos.                          |
| **7 dias**   | Troca executada e verificada (§6.2). Não deixe para o último dia: se a emissão atrasar, você fica sem. |
| **Venceu**   | A assinatura para de funcionar. §6.4.                                                                  |

### 6.2 Troca

```bash
# 1. Inspecionar o novo certificado ANTES de instalar — sem assinar nada, sem expor a senha
export NOVO_PFX_PASS='...'
cd /var/www/assinavelox/tools/pdftool
sudo -u assinavelox-worker ./.venv/bin/python -m pdftool cert-info \
  --pfx /caminho/novo.pfx --pass-env NOVO_PFX_PASS
# Confira: titular (razão social correta), emissor (AC da ICP-Brasil), validade, impressão digital.

# 2. Instalar, legível só pelo worker
sudo install -o assinavelox-worker -g assinavelox -m 400 \
  /caminho/novo.pfx /var/lib/assinavelox/certs/operadora.pfx

# 3. Cadeia da AC (para PDFTOOL_TRUST_ROOTS)
sudo install -o assinavelox-worker -g assinavelox -m 444 \
  /caminho/cadeia-ac.pem /var/lib/assinavelox/certs/cadeia-ac.pem

# 4. Senha nova no ambiente do worker
sudo nano /etc/assinavelox/worker.env      # COMPANY_CERT_PASSWORD=...
sudo chmod 600 /etc/assinavelox/worker.env

# 5. Recarregar
cd /var/www/assinavelox
sudo -u assinavelox php artisan config:cache
sudo systemctl restart assinavelox-horizon

# 6. Verificar
sudo -u assinavelox-worker php artisan pdftool:selftest
```

**Guarde o `.pfx` antigo e a cadeia antiga permanentemente**, junto com a senha antiga, em cofre.
São eles que permitem explicar e validar uma assinatura feita no ano passado.

**Verificação de verdade** é assinar um envelope real e conferir o resultado. `pdftool:selftest`
exercita o pipeline; ele não prova que a cadeia da AC valida em um verificador oficial.

### 6.3 `test` × `production`

`COMPANY_CERT_ENVIRONMENT` rotula o certificado, e o rótulo atravessa `SignResult`,
`certificate_references.environment` e a interface.

- `test` — certificado de `gen-test-cert` ou de homologação. **Autoassinado, com `TESTE` no CN,
  sem validade jurídica.** A interface exibe "assinatura de teste" e nunca "ICP-Brasil".
- `production` — **somente** A1 emitido por AC da ICP-Brasil em nome da operadora. Mesmo assim, o
  que se afirma é: "assinatura digital da empresa operadora, PAdES B-B, sem carimbo do tempo".

Marcar um certificado de teste como `production` é fazer o sistema mentir na página de evidências
e na verificação pública. Não faça isso nem "temporariamente".

### 6.4 Certificado vencido e a assinatura parou

Duas opções, ambas legítimas — a errada é a terceira, que não existe:

**(a) Segurar os envelopes.** Não conclua nada até o certificado novo estar no lugar. Os envelopes
ficam em `finalizing` e retomam sozinhos assim que a fila voltar a funcionar. Escolha esta se os
clientes esperam o arquivo assinado.

**(b) Concluir como aceite eletrônico com evidências.** `COMPANY_CERT_ENABLED=false` →
`config:cache` → `restart` do Horizon. Os envelopes concluem com `signature_status = none`, e a
interface, a página de evidências e a verificação pública **dizem exatamente isso**. É uma entrega
honesta, de qualidade probatória diferente. Registre a janela: quais envelopes concluíram sem
assinatura criptográfica, e por quê.

**O que nunca se faz:** manter a assinatura ligada com certificado vencido, reaproveitar
certificado de outra entidade, apresentar certificado de teste como se fosse ICP-Brasil, ou dizer
ao cliente que o documento tem assinatura que ele não tem. A semântica de `docs/arquitetura.md` §2
não é negociável em incidente.

---

## 7. Regras que não se quebram

1. **Nunca conclua um envelope na mão.** Sem arquivo final, hash e registro de verificação, a
   verificação pública fica sem lastro.
2. **Nunca altere as tabelas de evidência** (`audit_events`, `signature_acceptances`,
   `document_versions`, `verification_records`). São _append-only_ por desenho, e o privilégio do
   banco existe para impedir o acidente.
3. **Nunca ative um plano na mão.** Conserte o webhook.
4. **Nunca apresente assinatura de teste como ICP-Brasil**, nem prometa validade universal.
5. **Nunca coloque um segredo em log, em ticket ou em mensagem.** O log já redige as chaves
   sensíveis; não desfaça isso colando o valor na mão.
6. **Nunca ligue `APP_DEBUG=true` em produção** para investigar. Use o log, o
   `assinavelox:doctor` e o identificador de correlação (`X-Correlation-Id`) — a página de
   diagnóstico do Laravel expõe configuração e trechos de código para quem estiver olhando.
7. **Nunca rode `migrate:fresh`, `db:wipe` ou `migrate:rollback` em produção** sem backup
   verificado e uma decisão consciente. `migrate:fresh` apaga tudo.
