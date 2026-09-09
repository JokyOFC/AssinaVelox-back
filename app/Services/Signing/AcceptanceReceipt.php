<?php

namespace App\Services\Signing;

use App\Models\SignatureAcceptance;
use Illuminate\Support\Carbon;

/**
 * Comprovante do aceite entregue ao signatário logo depois de assinar.
 *
 * ## Por que texto, e por que agora
 *
 * A **página de evidências** — a peça formal, em PDF, com a linha do tempo, todos os
 * participantes e os quatro resumos criptográficos — só existe depois da finalização
 * (incremento 4), porque três dos quatro hashes só passam a existir lá. Entregar antes um
 * PDF parecido com ela seria fabricar um documento que a plataforma ainda não tem como
 * sustentar.
 *
 * O que a plataforma tem, imediatamente, é o registro do aceite desta pessoa. Este
 * comprovante é exatamente isso e nada além: texto simples, gerado no servidor a partir das
 * colunas de `signature_acceptances`, com um aviso explícito do que ele é e do que não é.
 * Nenhuma linha aqui afirma assinatura criptográfica, e o resumo do arquivo final aparece
 * como "ainda não publicado" enquanto não existir.
 *
 * Dados de terceiros não entram. O IP obedece `settings.evidence_show_ip`.
 */
final class AcceptanceReceipt
{
    public function filename(SignerContext $context): string
    {
        return sprintf('comprovante-%s.txt', strtolower($context->envelope->display_code));
    }

    public function render(SignerContext $context, SignatureAcceptance $acceptance): string
    {
        $timezone = $context->organization->timezone ?: 'America/Sao_Paulo';
        $acceptedAt = $acceptance->accepted_at;
        $mode = (string) $context->organization->setting('evidence_show_ip', 'masked');
        $ip = SignerRequestFacts::displayIp($acceptance->ip_address, $mode);

        $finalHash = $context->envelope->final_document_version_id === null
            ? 'ainda não publicado (o arquivo final é gerado quando todos concluírem)'
            : (string) ($context->envelope->finalVersion()->withoutGlobalScopes()->value('sha256')
                ?? 'ainda não publicado');

        $lines = [
            'COMPROVANTE DE ACEITE ELETRÔNICO',
            str_repeat('=', 64),
            '',
            'Documento: '.$context->envelope->title,
            'Identificação: '.$context->envelope->display_code,
            'Enviado por: '.$context->organization->name,
            'Código de verificação: '.($context->envelope->formatted_verification_code ?? '—'),
            '',
            'Participante: '.$context->recipient->name,
            'E-mail (mascarado): '.$context->recipient->masked_email,
            'Método de autenticação: '.$acceptance->auth_method->label(),
            '',
            'Aceite registrado em: '.$acceptedAt->copy()->setTimezone($timezone)->format('d/m/Y H:i:s').' ('.$timezone.')',
            'Equivalente em UTC: '.$acceptedAt->copy()->utc()->format('Y-m-d H:i:s').' UTC',
            'Endereço IP registrado: '.($ip ?? 'não exibido por configuração da organização'),
            'Navegador: '.($acceptance->user_agent ?? 'não informado'),
            'Versão do texto de aceite: '.($acceptance->terms_version ?? '—'),
            '',
            'Resumo SHA-256 do documento apresentado a você:',
            '  '.$acceptance->document_sha256,
            'Resumo SHA-256 do arquivo final:',
            '  '.$finalHash,
            '',
            str_repeat('-', 64),
            'DECLARAÇÃO QUE VOCÊ ACEITOU',
            str_repeat('-', 64),
            '',
            $acceptance->consent_statement,
            '',
            str_repeat('-', 64),
            'O QUE ESTE COMPROVANTE É — E O QUE NÃO É',
            str_repeat('-', 64),
            '',
            'Este arquivo é um comprovante em texto, gerado pela AssinaVelox a partir do',
            'registro do seu aceite. Ele NÃO é a página de evidências do documento, NÃO é um',
            'certificado digital e NÃO possui assinatura criptográfica. Um resumo SHA-256 não',
            'é assinatura: ele serve apenas para conferir se dois arquivos são idênticos.',
            '',
            'A página de evidências completa, com todos os participantes e os resumos do',
            'arquivo final, é gerada quando todos concluírem e fica anexada ao documento final.',
            '',
            'Verifique este documento em '.ConsentText::verificationUrl(),
            'Código: '.($context->envelope->formatted_verification_code ?? '—'),
            '',
            'Comprovante gerado em '.Carbon::now()->utc()->format('Y-m-d H:i:s').' UTC.',
        ];

        return implode("\r\n", $lines)."\r\n";
    }
}
