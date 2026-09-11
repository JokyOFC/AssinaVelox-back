<?php

namespace App\Services\PublicForms;

/**
 * Aviso de privacidade da página pública do formulário.
 *
 * ⚠️ TEXTO PENDENTE DE REVISÃO JURÍDICA. Base: docs/juridico/aviso-de-privacidade-signatario.md
 * (mesma divisão controladora/operadora) e docs/juridico/politica-de-privacidade.md. Nenhuma
 * frase aqui promete o que o sistema não faz: os prazos citados são os de
 * {@see PublicFormsConfig} e a ausência de rastreadores é a da página (sem script, fonte,
 * pixel ou iframe de terceiros).
 *
 * A versão vai para `public_form_submissions.privacy_notice_version` e para o evento
 * `public_form.submission_confirmed`: fica registrado qual texto a pessoa viu.
 */
final class PrivacyNotice
{
    public const VERSION = 'v1-public-form-2026-09-11';

    public static function summary(string $organization): string
    {
        return "Os dados deste formulário são recebidos por {$organization}, que os usa para preparar o documento e pedir a sua assinatura. A AssinaVelox processa os dados em nome dela.";
    }

    /**
     * @return list<array{title: string, body: string}>
     */
    public static function sections(string $organization): array
    {
        $ttl = PublicFormsConfig::confirmationTtlMinutes();

        return [
            [
                'title' => 'Quem é responsável pelos seus dados',
                'body' => "{$organization} publicou este formulário e decide o que fazer com as respostas: é a controladora dos dados. A AssinaVelox é a operadora e trata os dados apenas para gerar o documento e conduzir a assinatura, seguindo as instruções de {$organization}.",
            ],
            [
                'title' => 'O que registramos',
                'body' => 'Seu nome, seu e-mail e as respostas deste formulário; o endereço IP, a identificação do navegador e a data e hora do envio. As respostas entram no documento que será assinado.',
            ],
            [
                'title' => 'Confirmação do e-mail',
                'body' => "Enviamos um link para o e-mail informado. Nada é gerado antes da confirmação. Se o link não for usado em {$ttl} minutos, o envio e os dados digitados são apagados. Confirmar o e-mail mostra que você tem acesso a ele; não comprova quem você é.",
            ],
            [
                'title' => 'O que não fazemos',
                'body' => 'Esta página não usa cookies de rastreamento, anúncios nem ferramentas de terceiros; usa apenas cookies essenciais de sessão e segurança. Não pedimos arquivos, senha nem foto.',
            ],
            [
                'title' => 'Seus direitos',
                'body' => "Para acessar, corrigir ou pedir a exclusão dos seus dados, fale primeiro com {$organization}. Você também pode escrever à AssinaVelox pelos canais indicados na Política de Privacidade.",
            ],
        ];
    }
}
