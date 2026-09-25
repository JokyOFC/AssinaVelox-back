# Site institucional (assinavelox.com.br) — integração com o app

O site institucional é um projeto separado (React + Vite, estático), publicado em
**assinavelox.com.br**. O app — este repositório — vive em **app.assinavelox.com.br** e não tem
página inicial própria: a rota `home` redireciona para o login (`HomeController`). Este documento
descreve os três pontos de contato entre os dois.

---

## 1. Para onde o logo leva

Nas cascas de autenticação (`resources/js/layouts/auth-layout.tsx` — login, cadastro,
recuperação de senha, 2FA) e pública (`public-layout.tsx` — verificação, termos, privacidade), o
logo é um link para `config('assinavelox.site_url')` (`ASSINAVELOX_SITE_URL`, padrão
`https://assinavelox.com.br`), entregue ao front na prop compartilhada `site_url`
(`HandleInertiaRequests`) e lido pelo hook `useSiteUrl()`. Antes ele apontava para `home`, que
redireciona para a própria tela de login — um logo que "não faz nada".

A rota `home` continua existindo com o mesmo comportamento: é o destino de logout, exclusão de
conta e páginas de erro, e para o usuário autenticado leva ao painel (`LandingRoute`).

O caminho inverso está no site: o botão "Login" abre `{app}/login` e todos os botões de criar
conta abrem `{app}/register`.

## 2. API do site (`/api/site/*`)

`routes/site.php`, carregado por `bootstrap/app.php` (`withRouting(then: …)`) **fora** do grupo
`web` — sem sessão, sem cookie e sem CSRF — e fora da pilha `api` de `routes/api.php` (que exige
token Bearer e é a API v1 das organizações). O prefixo `api/` faz os erros saírem em
`application/problem+json` (`ApiProblem`) e coloca as rotas na cobertura do CORS padrão do
framework (`paths: api/*`, qualquer origem, sem credenciais): as respostas são públicas por
definição, então uma origem aberta não expõe nada que a página do app já não exponha.

| Rota                                       | Nome                     | Limite                            | O que devolve                                                                                          |
| ------------------------------------------ | ------------------------ | --------------------------------- | ------------------------------------------------------------------------------------------------------ |
| `GET /api/site/planos`                     | `site.plans`             | `throttle:public` (60/min por IP) | `{ data: [plano…] }` — ver §2.1. `Cache-Control: public, max-age=300`.                                 |
| `GET /api/site/verificar/{code}`           | `site.verify.show`       | + `20/min` por IP                 | `{ code, found, result }` — o mesmo `result` da página `/verificar` (§2.2). `Cache-Control: no-store`. |
| `POST /api/site/verificar/{code}/conferir` | `site.verify.check_file` | + `10/min` por IP                 | `{ code, file_check }` — conferência de um resumo SHA-256 já calculado (§2.3).                         |

### 2.1 Catálogo de planos

`Site\PlanCatalogController`. A regra de visibilidade é a da tela interna (`Billing\PlanController`):
planos **ativos e públicos**; os planos sandbox do `PlanSeeder` (preços de desenvolvimento) só em
`local`/`testing`, e sempre com `price_is_placeholder: true`, para que o site não os anuncie como
oferta. Cada item:

```json
{
    "code": "free",
    "name": "Grátis",
    "description": "Para experimentar: 5 documentos por mês e 1 usuário.",
    "currency": "BRL",
    "billing_period": "monthly",
    "billing_period_label": "Mensal",
    "price_cents": 0,
    "price_cents_monthly": 0,
    "price_formatted": "Grátis",
    "is_free": true,
    "features": [
        "5 documentos/mês",
        "Código por e-mail",
        "Página de evidências",
        "1 usuário"
    ],
    "limits": { "envelopes_per_month": 5, "members": 1 },
    "highlighted": false,
    "is_sandbox": false,
    "price_is_placeholder": false,
    "cta": "register",
    "register_url": "https://app.assinavelox.com.br/register"
}
```

`features` são os rótulos de `PlanController::featureLabels()` — inclusive a regra de só anunciar
a assinatura criptográfica da operadora quando há certificado ativo. `cta` é `register` (o botão
abre o cadastro do app); `contact` fica reservado para um plano futuro sem preço público (o site
o renderiza como "Sob consulta" com botão para o contato). `highlighted` marca o plano Profissional.

O catálogo anunciado é o da tabela `plans` (Painel interno › Planos, `docs/cobranca.md` §16 e
§18): hoje Básico, Profissional e Empresarial; o Grátis é privado e não sai aqui. Se a API falhar ou vier vazia, o site mostra o catálogo de referência embutido nele
(`FALLBACK_PLANS` em `src/pages/Planos.jsx`) com um aviso — mantenha-o igual ao seeder.

### 2.2 Verificação por código

`Site\VerificationController@show`. `result` é **exatamente** `PublicVerification::result()`
(o teste `SiteVerificationTest` compara com as props da página `/verificar`), com o contrato
fechado de chaves e as proibições de `docs/verificacao-publica.md` §1.3–§1.5 — e a mesma resposta
uniforme (`found: false`, `result: null`, HTTP 200) para código inexistente, rascunho, excluído ou
revogado. `SecurityHeaders` aplica `X-Robots-Tag: noindex, nofollow, noarchive` e
`Referrer-Policy: no-referrer` também em `api/site/verificar/*`.

O site normaliza o código antes de chamar (hifens, espaços e caixa são irrelevantes) e trata o
404 do formato inválido (menos de 12 caracteres) como "não encontrado".

### 2.3 Conferência de arquivo

Mesma decisão da página do app (`docs/verificacao-publica.md` §2): a comparação padrão acontece
**no navegador do visitante** — o site calcula o SHA-256 com WebCrypto e compara com os resumos
que já vieram em `result.hashes` (e em `result.documents`, quando há vários arquivos). O arquivo
nunca sai do computador de quem confere. A rota `conferir` aceita só um resumo de 64 hexadecimais
(422 em problem+json para qualquer outra coisa) e responde `signed`, `original`, `signed_previous`
ou `none` — para quem colou um resumo calculado por conta própria.

## 3. Configuração

| Onde | Variável               | Padrão                           | Papel                                              |
| ---- | ---------------------- | -------------------------------- | -------------------------------------------------- |
| app  | `ASSINAVELOX_SITE_URL` | `https://assinavelox.com.br`     | destino do logo (§1)                               |
| site | `VITE_APP_URL`         | `https://app.assinavelox.com.br` | base de `/login`, `/register` e `/api/site/*` (§2) |

Em desenvolvimento, `VITE_APP_URL=http://localhost:8000` no `.env` do site aponta o catálogo e a
verificação para o `php artisan serve` local.

## 4. Testes

`tests/Feature/Site/SitePlanCatalogTest.php` (visibilidade por ambiente, chaves fechadas,
rótulos, CORS e cache) e `tests/Feature/Site/SiteVerificationTest.php` (igualdade com a página,
resposta uniforme, conferência e erro 422).
