{{--
    Página de evidências — Blade → DOMPDF (arquitetura §5 item 7, docs/juridico §5).

    Regras que este template respeita e que não podem ser afrouxadas:
      * o hash FINAL não aparece aqui (ele só existe depois de o arquivo estar pronto);
      * o documento não é apresentado como certificado digital nem como assinatura pessoal;
      * o bloco de assinatura descreve o resultado REAL da finalização;
      * o rodapé impresso traz apenas URL de verificação e código — sem nomes, e-mails, IPs
        ou resumos.

    Os dados vêm de App\Services\Envelopes\Finalization\EvidenceData.
--}}
@php($e = $evidence)
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Página de evidências — {{ $e['envelope']['display_code'] }}</title>
    <style>
        @page { margin: 22mm 16mm 20mm 16mm; }

        body {
            font-family: "DejaVu Sans", sans-serif;
            font-size: 8.5pt;
            line-height: 1.45;
            color: #14203a;
        }

        h1 { font-size: 14pt; margin: 0 0 2mm 0; }
        h2 { font-size: 9.5pt; margin: 6mm 0 2mm 0; padding-bottom: 1mm; border-bottom: 0.6pt solid #c9d3e4; }
        p  { margin: 0 0 2mm 0; }

        .muted { color: #5a6885; }
        .small { font-size: 7.5pt; }
        .mono  { font-family: "DejaVu Sans Mono", monospace; font-size: 7pt; word-wrap: break-word; }
        .strong { font-weight: bold; }

        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; vertical-align: top; padding: 1.4mm 2mm; }
        thead th { background: #eef2f9; font-size: 7.5pt; border-bottom: 0.6pt solid #c9d3e4; }
        tbody tr td { border-bottom: 0.4pt solid #e3e9f3; }
        /* As tabelas de participantes, linha do tempo e resumos atravessam páginas. Sem
           `table-header-group` o DOMPDF deixa as colunas órfãs do próprio cabeçalho na
           página seguinte; sem `page-break-inside: avoid` uma linha de participante pode
           ser partida ao meio. */
        thead { display: table-header-group; }
        tfoot { display: table-footer-group; }
        tbody tr { page-break-inside: avoid; }

        .head-table td { padding: 0; vertical-align: top; }
        .qr { width: 32mm; }
        .qr img { width: 28mm; height: 28mm; }

        .kv td:first-child { width: 34mm; color: #5a6885; }

        .box { border: 0.6pt solid #c9d3e4; background: #f7f9fd; padding: 3mm; margin: 2mm 0 3mm 0; }
        .box-warn { border-color: #e0b400; background: #fff8e1; }

        .code { font-size: 11pt; letter-spacing: 0.6pt; font-weight: bold; }

        .footer {
            position: fixed;
            bottom: -14mm; left: 0; right: 0;
            text-align: center;
            font-size: 6.5pt;
            color: #5a6885;
        }
    </style>
</head>
<body>

<div class="footer">{{ $e['verification']['footer_line'] }}</div>

<table class="head-table">
    <tr>
        <td>
            <h1>Página de evidências do aceite eletrônico</h1>
            <p class="muted">
                Documento consolidado por {{ $e['operator']['name'] }} · gerada automaticamente em
                {{ $e['generated_at']['utc'] }} (UTC)
            </p>
            <p class="code">{{ $e['verification']['code'] }}</p>
            <p class="small muted">Confira este documento em {{ $e['verification']['url'] }}</p>
        </td>
        <td class="qr">
            <img src="{{ $e['verification']['qr'] }}" alt="QR code da página de verificação">
        </td>
    </tr>
</table>

<h2>1. Identificação</h2>
<table class="kv">
    <tr><td>Documento</td><td class="strong">{{ $e['envelope']['title'] }}</td></tr>
    <tr><td>Identificação interna</td><td>{{ $e['envelope']['display_code'] }}</td></tr>
    <tr><td>Código de verificação</td><td class="strong">{{ $e['envelope']['verification_code'] }}</td></tr>
    <tr><td>Organização remetente</td><td>{{ $e['organization']['legal_name'] ?: $e['organization']['name'] }}</td></tr>
    <tr><td>Criado em</td><td>{{ $e['envelope']['created_at'] ?? '—' }}</td></tr>
    <tr><td>Enviado em</td><td>{{ $e['envelope']['sent_at'] ?? '—' }}</td></tr>
    <tr><td>Concluído em</td><td>{{ $e['envelope']['completed_at'] ?? '—' }} ({{ $e['organization']['timezone'] }})</td></tr>
    <tr><td>Ordem de assinatura</td><td>{{ $e['envelope']['signing_order'] === 'sequential' ? 'Sequencial' : 'Paralela' }}</td></tr>
    <tr><td>Páginas do documento</td><td>{{ $e['envelope']['page_count'] }}</td></tr>
    <tr><td>Versão do texto de aceite</td><td>{{ $e['envelope']['terms_version'] }}</td></tr>
</table>

<h2>2. Participantes</h2>
<table>
    <thead>
    <tr>
        <th style="width: 30%">Participante</th>
        <th style="width: 20%">Situação</th>
        <th style="width: 22%">Aceite (servidor)</th>
        <th style="width: 28%">Autenticação e origem</th>
    </tr>
    </thead>
    <tbody>
    @foreach ($e['participants'] as $participant)
        <tr>
            <td>
                <span class="strong">{{ $participant['name'] }}</span><br>
                <span class="small muted">{{ $participant['email'] }}</span>
            </td>
            <td>
                {{ $participant['status_label'] }}
                @if ($participant['refused_at'])
                    <br><span class="small muted">Recusado em {{ $participant['refused_at'] }}</span>
                    @if ($participant['refusal_reason'])
                        <br><span class="small muted">Motivo: “{{ $participant['refusal_reason'] }}”</span>
                    @endif
                @endif
            </td>
            <td>
                @if ($participant['signed_at'])
                    {{ $participant['signed_at'] }}<br>
                    <span class="small muted">{{ $participant['signed_at_utc'] }} UTC</span>
                @else
                    —
                @endif
            </td>
            <td class="small">
                {{ $participant['auth_method'] }}
                @if ($participant['ip'])
                    <br>IP {{ $participant['ip'] }}
                @endif
                @if ($participant['user_agent'])
                    <br><span class="muted">{{ \Illuminate\Support\Str::limit($participant['user_agent'], 90) }}</span>
                @endif
                @if ($participant['signature_kind'])
                    <br><span class="muted">Representação visual: {{ $participant['signature_kind'] }}@if ($participant['typed_name']) (“{{ $participant['typed_name'] }}”)@endif</span>
                @endif
            </td>
        </tr>
    @endforeach
    </tbody>
</table>
<p class="small muted">
    A imagem de assinatura (desenhada, digitada ou enviada) é uma <span class="strong">representação visual</span>
    e não prova, por si só, a autoria. A manifestação de vontade é o aceite eletrônico registrado acima.
</p>

<h2>3. Linha do tempo</h2>
@if (count($e['timeline']) === 0)
    <p class="muted">Sem eventos registrados.</p>
@else
    <table>
        <thead>
        <tr>
            <th style="width: 26%">Quando ({{ $e['organization']['timezone'] }})</th>
            <th style="width: 40%">Evento</th>
            <th style="width: 34%">Participante</th>
        </tr>
        </thead>
        <tbody>
        @foreach ($e['timeline'] as $event)
            <tr>
                <td class="small">
                    {{ $event['at'] ?? '—' }}
                    @if ($event['at_utc'])
                        <br><span class="muted">{{ $event['at_utc'] }} UTC</span>
                    @endif
                </td>
                <td>{{ $event['label'] }}</td>
                <td class="small">{{ $event['recipient'] ?? ($event['actor'] === 'system' ? 'Plataforma' : '—') }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endif

<h2>4. Como ler os resumos criptográficos (SHA-256)</h2>
<p>
    Um resumo SHA-256 (<span class="strong">hash</span>) é uma sequência de 64 caracteres que identifica um arquivo
    byte a byte: qualquer alteração no arquivo, por menor que seja, produz um resumo completamente diferente.
    <span class="strong">Um resumo não é uma assinatura</span>: ele permite conferir se dois arquivos são idênticos,
    e nada mais. Cada resumo abaixo foi calculado sobre bytes distintos.
</p>
<table>
    <thead>
    <tr>
        <th style="width: 22%">Resumo</th>
        <th>Do que foi calculado</th>
    </tr>
    </thead>
    <tbody>
    <tr>
        <td>
            <span class="strong">Original</span><br>
            <span class="mono">{{ $e['hashes']['original'] ?? '—' }}</span>
        </td>
        <td>
            Dos bytes do arquivo exatamente como foi enviado pela organização remetente à plataforma
            (PDF, DOCX ou imagem), antes de qualquer conversão.
        </td>
    </tr>
    <tr>
        <td>
            <span class="strong">Enviado</span><br>
            <span class="mono">{{ $e['hashes']['sent'] ?? '—' }}</span>
        </td>
        <td>
            Dos bytes da versão em PDF congelada no envio e <span class="strong">apresentada a todos os
            signatários</span>. É este resumo que cada declaração de aceite referencia. Se o original já era um
            PDF sem conversão, pode coincidir com o resumo original.
        </td>
    </tr>
    <tr>
        <td>
            <span class="strong">Consolidado</span><br>
            <span class="mono">{{ $e['hashes']['consolidated'] ?? '—' }}</span>
        </td>
        <td>
            Dos bytes do PDF gerado após a coleta, com os campos preenchidos e as representações visuais de
            assinatura incorporados às páginas (“achatados”), <span class="strong">antes</span> do acréscimo
            desta página de evidências.
        </td>
    </tr>
    <tr>
        <td><span class="strong">Final</span></td>
        <td>
            Dos bytes do arquivo final completo (documento consolidado + esta página de evidências + assinatura
            criptográfica da operadora, quando aplicada). <span class="strong">Este resumo é calculado depois de o
            arquivo estar pronto e, por isso, não pode constar dentro do próprio arquivo.</span> Ele é publicado
            exclusivamente na página de verificação {{ $e['verification']['url'] }}, sob o código
            {{ $e['verification']['code'] }}. Para conferir o arquivo que você tem em mãos, calcule o SHA-256 dele
            e compare com o valor publicado.
        </td>
    </tr>
    </tbody>
</table>

<h2>5. Sobre a assinatura criptográfica deste arquivo</h2>
@if ($e['signature']['signed'])
    <div class="box">
        <p>
            Este arquivo recebeu uma assinatura digital no perfil
            <span class="strong">{{ $e['signature']['profile'] ?? 'PAdES-B-B' }}</span> (PAdES), aplicada pela
            <span class="strong">{{ $e['operator']['name'] }}</span> com certificado digital de
            <span class="strong">sua própria titularidade</span>@if ($e['signature']['certificate'])
                (titular: {{ $e['signature']['certificate']['subject'] ?? '—' }};
                emissor: {{ $e['signature']['certificate']['issuer'] ?? '—' }};
                validade: {{ $e['signature']['certificate']['not_before'] ?? '—' }} a
                {{ $e['signature']['certificate']['not_after'] ?? '—' }})@endif.
            Essa assinatura tem duas funções: identificar a {{ $e['operator']['name'] }} como a operadora que
            consolidou e lacrou este arquivo, e permitir que leitores de PDF detectem qualquer alteração feita
            depois do lacre.
        </p>
        <p>
            <span class="strong">Ela não é a assinatura pessoal de nenhum dos participantes e não é um certificado
            digital emitido em nome deles.</span> A manifestação de vontade de cada participante é o
            <span class="strong">aceite eletrônico</span> descrito acima, sustentado pelas evidências desta página
            (data, IP, navegador, autenticação por código enviado ao e-mail, versão do documento e campos preenchidos).
        </p>
        {{--
            Resultado técnico da validação (declaração de aceite §5.2). Ele é apurado sobre o
            ARQUIVO PRONTO, depois de esta página já estar dentro dele e de a assinatura ter
            sido aplicada — a mesma razão pela qual o resumo Final não consta daqui. Por isso
            o texto abaixo aponta onde o resultado é publicado em vez de fabricá-lo. O ramo
            positivo fica: se um dia a apuração acontecer antes da renderização, ela é impressa.
        --}}
        @if ($e['signature']['validation_summary'])
            <p class="small">
                Resultado técnico da validação no momento da conclusão: {{ $e['signature']['validation_summary'] }}
                A verificação da cadeia de certificação por terceiros depende das ferramentas e das políticas de
                confiança que eles utilizarem.
            </p>
        @else
            <p class="small">
                <span class="strong">Resultado técnico da validação:</span> ele é apurado sobre o arquivo já
                pronto e assinado — isto é, depois de esta página existir — e por isso, tal como o resumo
                <span class="strong">Final</span>, não pode constar de dentro do próprio arquivo. Ele é
                publicado na página de verificação {{ $e['verification']['url'] }}, sob o código
                {{ $e['verification']['code'] }}, com o que foi e o que não foi verificado (integridade,
                cadeia de certificação e revogação). A verificação da cadeia de certificação por terceiros
                depende das ferramentas e das políticas de confiança que eles utilizarem.
            </p>
        @endif
        <p class="small muted">
            Este perfil não inclui carimbo do tempo de terceiro (B-T) nem informações de validação de longo prazo
            (LTV/LTA). A data indicada é a do servidor no momento da assinatura.
        </p>
    </div>
    @if (($e['signature']['certificate']['environment'] ?? null) === 'test')
        <div class="box box-warn">
            <p class="strong">Certificado de ambiente de teste.</p>
            <p>
                A assinatura deste arquivo foi aplicada com um certificado de teste, sem valor para uso em produção.
                Este arquivo não deve ser utilizado para fins reais e o certificado não é ICP-Brasil.
            </p>
        </div>
    @endif
@else
    <div class="box">
        <p>
            <span class="strong">Este arquivo não possui assinatura criptográfica.</span> O envelope foi concluído
            como <span class="strong">aceite eletrônico com evidências</span>: a manifestação de vontade de cada
            participante está registrada nesta página (data, IP, navegador, autenticação por código enviado ao
            e-mail, versão do documento e campos preenchidos), e a integridade do arquivo pode ser conferida
            comparando o seu resumo SHA-256 com o resumo <span class="strong">Final</span> publicado em
            {{ $e['verification']['url'] }} sob o código {{ $e['verification']['code'] }}. Nenhum certificado
            digital foi utilizado, e nenhuma indicação de “assinatura digital” deve ser esperada em leitores de PDF.
        </p>
    </div>
@endif

<p class="small muted">
    Esta página de evidências foi gerada automaticamente pela {{ $e['operator']['name'] }} em
    {{ $e['generated_at']['utc'] }} (UTC). Ela <span class="strong">não é um certificado digital</span> e não
    substitui a análise das partes ou de seus assessores sobre a validade do ato documentado. Referências
    normativas a serem avaliadas caso a caso: Lei nº 14.063/2020 e MP nº 2.200-2/2001.
    Verifique este documento em <span class="strong">{{ $e['verification']['url'] }}</span> · código
    <span class="strong">{{ $e['verification']['code'] }}</span>.
</p>

</body>
</html>
