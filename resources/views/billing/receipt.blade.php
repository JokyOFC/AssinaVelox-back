{{--
    Recibo interno de pagamento — Blade → DOMPDF (ROUTES §2.15 `billing.payments.receipt`).

    Regra que este template não afrouxa: o documento diz, no topo e no rodapé, que
    **não é documento fiscal** e que não substitui a nota fiscal de serviço. A emissão
    de NFS-e é Fase 2 (RECONCILIACAO §5); o Mercado Pago não expõe API pública de NFS-e
    (docs/integracoes/mercado-pago.md §9).

    Nenhum dado de cartão aparece aqui — nem existe: o Checkout Pro não devolve número,
    validade nem código de segurança, e nada é armazenado.

    Os dados vêm de App\Services\Billing\PaymentReceipt::data().
--}}
@php($r = $receipt)
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Recibo {{ $r['number'] }}</title>
    <style>
        @page { margin: 20mm 16mm 18mm 16mm; }

        body {
            font-family: "DejaVu Sans", sans-serif;
            font-size: 9pt;
            line-height: 1.5;
            color: #14203a;
        }

        h1 { font-size: 15pt; margin: 0 0 1mm 0; }
        h2 { font-size: 9.5pt; margin: 6mm 0 2mm 0; padding-bottom: 1mm; border-bottom: 0.6pt solid #c9d3e4; }
        p  { margin: 0 0 2mm 0; }

        .muted  { color: #5a6885; }
        .small  { font-size: 7.5pt; }
        .mono   { font-family: "DejaVu Sans Mono", monospace; font-size: 7.5pt; word-wrap: break-word; }
        .strong { font-weight: bold; }

        .notice {
            border: 0.8pt solid #b8860b;
            background: #fdf6e3;
            color: #6b4e00;
            padding: 3mm;
            margin: 4mm 0;
            font-size: 8.5pt;
        }

        .sandbox {
            border: 0.8pt solid #a33;
            background: #fdeaea;
            color: #7a1f1f;
            padding: 3mm;
            margin: 0 0 4mm 0;
            font-size: 8.5pt;
        }

        table { width: 100%; border-collapse: collapse; }
        td { text-align: left; vertical-align: top; padding: 1.4mm 0; }
        .rows td { border-bottom: 0.4pt solid #e3e9f3; }
        .rows td.label { width: 42mm; color: #5a6885; }

        .total { font-size: 15pt; font-weight: bold; }

        .foot {
            position: fixed;
            bottom: -12mm;
            left: 0;
            right: 0;
            font-size: 7pt;
            color: #5a6885;
            border-top: 0.4pt solid #c9d3e4;
            padding-top: 1.5mm;
        }
    </style>
</head>
<body>
<div class="foot">
    {{ $r['disclaimer'] }} Documento gerado pela AssinaVelox · recibo {{ $r['number'] }}.
</div>

<table>
    <tr>
        <td>
            <h1>Recibo de pagamento</h1>
            <p class="muted small">{{ $r['operator']['name'] }}@if ($r['operator']['legal_name']) · {{ $r['operator']['legal_name'] }}@endif</p>
            @if ($r['operator']['tax_id'])
                <p class="muted small">CNPJ {{ $r['operator']['tax_id'] }}</p>
            @endif
        </td>
        <td style="text-align: right; width: 60mm;">
            <p class="small muted">Recibo</p>
            <p class="mono">{{ $r['number'] }}</p>
            @if ($r['issued_at'])
                <p class="small muted">{{ $r['issued_at']->format('d/m/Y H:i') }} ({{ $r['timezone'] }})</p>
            @endif
        </td>
    </tr>
</table>

<div class="notice">
    <span class="strong">{{ $r['disclaimer'] }}</span>
    Ele comprova apenas que o pagamento abaixo foi recebido. A nota fiscal de serviço
    é emitida separadamente e ainda não faz parte desta versão do produto.
</div>

@if ($r['plan']['is_sandbox'] || $r['environment']->value === 'sandbox')
    <div class="sandbox">
        <span class="strong">Ambiente de testes.</span>
        Este pagamento foi processado no ambiente <span class="strong">sandbox</span>
        @if ($r['plan']['is_sandbox']) com um plano de preço fictício @endif —
        nenhum valor real foi movimentado.
    </div>
@endif

<h2>Pagador</h2>
<table class="rows">
    <tr>
        <td class="label">Razão social / nome</td>
        <td>{{ $r['customer']['name'] }}</td>
    </tr>
    @if ($r['customer']['document'])
        <tr>
            <td class="label">CPF / CNPJ</td>
            <td>{{ $r['customer']['document'] }}</td>
        </tr>
    @endif
    @if ($r['customer']['address'])
        <tr>
            <td class="label">Endereço</td>
            <td>{{ $r['customer']['address'] }}</td>
        </tr>
    @endif
    @if ($r['customer']['email'])
        <tr>
            <td class="label">E-mail de faturamento</td>
            <td>{{ $r['customer']['email'] }}</td>
        </tr>
    @endif
</table>

<h2>Pagamento</h2>
<table class="rows">
    <tr>
        <td class="label">Descrição</td>
        <td>Plano {{ $r['plan']['name'] }} — AssinaVelox</td>
    </tr>
    <tr>
        <td class="label">Meio de pagamento</td>
        <td>{{ $r['method'] }}</td>
    </tr>
    <tr>
        <td class="label">Provedor</td>
        <td>{{ $r['provider'] === 'mercadopago' ? 'Mercado Pago (Checkout Pro)' : $r['provider'] }}</td>
    </tr>
    @if ($r['provider_payment_id'])
        <tr>
            <td class="label">Identificador no provedor</td>
            <td class="mono">{{ $r['provider_payment_id'] }}</td>
        </tr>
    @endif
    <tr>
        <td class="label">Ambiente</td>
        <td>{{ $r['environment']->label() }}</td>
    </tr>
    <tr>
        <td class="label">Moeda</td>
        <td>{{ $r['amount']['currency'] }}</td>
    </tr>
    <tr>
        <td class="label">Valor pago</td>
        <td class="total">{{ $r['amount']['formatted'] }}</td>
    </tr>
</table>

<p class="small muted" style="margin-top: 5mm;">
    Nenhum dado de cartão é armazenado pela AssinaVelox: o pagamento acontece inteiramente
    dentro do Checkout Pro do Mercado Pago, que não nos devolve número, validade nem
    código de segurança. Também não há cartão salvo — cada ciclo é um pagamento avulso.
</p>
</body>
</html>
