<?php

namespace App\Services\Signing;

use App\Integrations\Contracts\PdfSigner;
use App\Models\CertificateReference;
use App\Models\Envelope;
use App\Models\Organization;
use App\Models\Recipient;
use Illuminate\Support\Carbon;

/**
 * Textos legais exibidos e gravados (docs/juridico/declaracao-de-aceite.md e
 * aviso-de-privacidade-signatario.md).
 *
 * Regras que este serviço materializa:
 *
 * 1. `ACCEPTANCE_TERMS_VERSION` muda **sempre que uma palavra do texto muda**. O envelope
 *    recebe a versão no envio (`envelopes.terms_version`) e todos os seus signatários veem
 *    aquele texto, mesmo que a constante mude durante a coleta.
 * 2. `signature_acceptances.consent_statement` guarda o texto **já resolvido** — variáveis
 *    substituídas, variante escolhida — exatamente como apareceu na tela. Não é uma
 *    referência a um template: é o que a pessoa leu.
 * 3. A escolha entre a variante (i) e a (ii) é feita **na renderização**, olhando se a
 *    Operadora tem certificado ativo **e** o adaptador de assinatura configurado
 *    ({@see self::operatorCertificateActive()}). Sem os dois, o texto diz que o envelope
 *    conclui como *aceite eletrônico com evidências, sem assinatura criptográfica* — e a
 *    interface diz o mesmo. Nunca se afirma que haverá assinatura que não haverá.
 */
final class ConsentText
{
    /**
     * Versão do texto de aceite exibido junto ao checkbox e gravado em `consent_statement`.
     * Formato v{n}-{AAAA-MM-DD}. Mudou uma palavra do texto → nova versão.
     */
    public const ACCEPTANCE_TERMS_VERSION = 'v1-2026-09-08';

    /** Versão do aviso de privacidade exibido na página pública. */
    public const PRIVACY_NOTICE_VERSION = 'v1-2026-09-08';

    /**
     * Versão vigente para um envelope: a congelada no envio; na ausência dela (envelope
     * antigo, dado incompleto), a constante atual.
     */
    public static function versionFor(Envelope $envelope): string
    {
        $version = $envelope->terms_version;

        return is_string($version) && $version !== '' ? $version : self::ACCEPTANCE_TERMS_VERSION;
    }

    /**
     * A finalização vai mesmo aplicar a assinatura criptográfica da Operadora?
     *
     * Duas condições, e as **duas** precisam valer:
     *
     * 1. existe `certificate_references` da Operadora (`organization_id` nulo) ativo e dentro
     *    da validade — o registro administrativo do certificado;
     * 2. o adaptador que assina está de fato configurado ({@see PdfSigner::isConfigured()}:
     *    `COMPANY_CERT_ENABLED`, arquivo PKCS#12 presente, senha no ambiente do processo e
     *    `pdftool` disponível).
     *
     * A segunda condição não é preciosismo. Uma linha em `certificate_references` é
     * metadado: ela não assina nada. Se o adaptador não estiver pronto, o contêiner entrega
     * o `NullPdfSigner` e o envelope conclui como *aceite eletrônico com evidências* — e foi
     * exatamente essa a combinação encontrada na integração dos incrementos 2 e 3 (o seeder
     * cria o certificado de demonstração; nenhuma variável `COMPANY_CERT_*` existe). Com só
     * a primeira condição, a tela e a declaração gravada prometiam ao signatário uma
     * assinatura criptográfica que nunca seria aplicada — a afirmação que a arquitetura §2
     * proíbe em qualquer circunstância.
     */
    public static function operatorCertificateActive(): bool
    {
        $now = Carbon::now();

        $registered = CertificateReference::query()
            ->whereNull('organization_id')
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('not_before')->orWhere('not_before', '<=', $now))
            ->where(fn ($q) => $q->whereNull('not_after')->orWhere('not_after', '>=', $now))
            ->exists();

        return $registered && app(PdfSigner::class)->isConfigured();
    }

    /**
     * Rótulo exibido ao lado da caixa (§2 do documento jurídico). A caixa nunca vem marcada.
     */
    public static function checkboxLabel(Envelope $envelope): string
    {
        return sprintf(
            'Li o documento %s e declaro que concordo com seu conteúdo e que os dados aqui '
            .'registrados — data e hora, endereço IP, navegador, código confirmado por e-mail, '
            .'a versão exata do documento e os campos que preenchi — constituem evidência do '
            .'meu aceite eletrônico.',
            $envelope->title,
        );
    }

    /**
     * Declaração completa (§3), já resolvida. É este texto que vai para `consent_statement`.
     *
     * @param  string  $documentSha256  resumo dos bytes da versão apresentada
     */
    public static function statement(
        Envelope $envelope,
        Recipient $recipient,
        Organization $organization,
        string $documentSha256,
        ?bool $withCertificate = null,
    ): string {
        $withCertificate ??= self::operatorCertificateActive();

        $sender = $organization->legal_name ?: $organization->name;
        $operator = self::operatorLegalName();
        $verificationUrl = self::verificationUrl();
        $verificationCode = $envelope->formatted_verification_code ?? '—';

        $item4 = $withCertificate
            ? sprintf(
                'Estou ciente de que, ao final da coleta de todos os aceites, a AssinaVelox (%s) '
                .'consolidará o documento com uma página de evidências e aplicará ao arquivo final '
                .'uma assinatura criptográfica com certificado digital de sua própria titularidade. '
                .'Essa assinatura identifica a AssinaVelox como operadora da plataforma e permite '
                .'detectar alterações posteriores no arquivo; ela não é a minha assinatura pessoal '
                .'nem um certificado digital emitido em meu nome.',
                $operator,
            )
            : sprintf(
                'Estou ciente de que, ao final da coleta de todos os aceites, a AssinaVelox (%s) '
                .'consolidará o documento com uma página de evidências e o concluirá como aceite '
                .'eletrônico com evidências, sem assinatura criptográfica. A integridade do arquivo '
                .'final poderá ser conferida pelo seu resumo SHA-256, publicado na página de '
                .'verificação %s com o código %s.',
                $operator,
                $verificationUrl,
                $verificationCode,
            );

        $lines = [
            sprintf('Declaração de aceite eletrônico — versão %s', self::versionFor($envelope)),
            '',
            sprintf(
                'Eu, %s, identificado(a) nesta solicitação pelo e-mail %s, declaro que:',
                $recipient->name,
                $recipient->masked_email,
            ),
            '',
            sprintf(
                '1. Li integralmente o documento "%s", enviado por %s, cujo conteúdo apresentado '
                .'nesta tela corresponde ao resumo SHA-256 %s, e concordo com o seu conteúdo.',
                $envelope->title,
                $sender,
                $documentSha256,
            ),
            '2. Os campos que preenchi nesta tela e a imagem de assinatura que forneci (desenhada, '
                .'digitada ou enviada) são de minha autoria. Reconheço que essa imagem é uma '
                .'representação visual e que a minha manifestação de vontade é este aceite.',
            '3. Estou ciente de que a AssinaVelox registrará, como evidência deste aceite: a data e '
                .'a hora do servidor (UTC), o meu endereço IP, a identificação do meu navegador, o '
                .'método de autenticação (código de uso único confirmado no e-mail acima), a versão '
                .'exata do documento, os campos apresentados e os valores preenchidos, e esta declaração.',
            '4. '.$item4,
            '5. Estou ciente de que a validade e os efeitos deste aceite dependem da legislação '
                .'aplicável e do acordo entre as partes, e que a AssinaVelox não garante sua '
                .'aceitação por terceiros.',
            '6. Li o aviso de privacidade exibido nesta página e sei que posso recusar a assinatura '
                .'informando um motivo.',
        ];

        return implode("\n", $lines);
    }

    /**
     * Linha-resumo do aviso de privacidade, sempre visível antes do botão "Receber código".
     */
    public static function privacySummary(Organization $organization): string
    {
        return sprintf(
            'Este documento foi enviado por %s. Para registrar seu aceite, a AssinaVelox gravará '
            .'data, IP, navegador, a versão exata do documento e o código confirmado por e-mail.',
            $organization->name,
        );
    }

    /**
     * Aviso de privacidade completo (menos de 350 palavras, conforme a minuta jurídica).
     */
    public static function privacyNotice(Organization $organization): string
    {
        $sender = $organization->name;
        $operator = self::operatorLegalName();
        $support = (string) config('assinavelox.support_email', 'suporte@assinavelox.com.br');

        return implode("\n\n", [
            'Como seus dados são usados nesta página',
            sprintf(
                'Quem é responsável pelos seus dados. Este documento foi enviado por %s, que decidiu '
                .'solicitar a sua assinatura e é a controladora dos seus dados pessoais. A AssinaVelox '
                .'(%s) é a operadora: trata os dados apenas para executar a assinatura, seguindo as '
                .'instruções da remetente.',
                $sender,
                $operator,
            ),
            'O que registramos e por quê. Para que o seu aceite tenha valor como evidência, gravamos: '
                .'seu nome e e-mail (informados pela remetente); o código de confirmação enviado ao seu '
                .'e-mail (guardado apenas de forma irreversível); a data e a hora do servidor (UTC); seu '
                .'endereço IP e a identificação do navegador; a versão exata do documento que você viu '
                .'(resumo SHA-256); os campos exibidos e os valores que você preencher; a imagem da sua '
                .'assinatura (desenhada, digitada ou enviada); e o texto de aceite que você marcar. Esses '
                .'dados compõem a página de evidências anexada ao documento final, entregue à remetente e a você.',
            'A abertura deste link é registrada. Ao abrir esta página, registramos data, IP e navegador '
                .'como "abertura detectada". Isso não significa que você leu ou concordou com algo.',
            'Se você não quiser assinar. Você pode simplesmente fechar esta página, ou usar "Recusar '
                .'assinatura" e informar o motivo, que será enviado à remetente. Não solicitar o código '
                .'não gera nenhum aceite.',
            'O que não fazemos. Não pedimos senha, CPF, foto ou localização. Não usamos cookies de '
                .'rastreamento nem anúncios nesta página; apenas cookies essenciais de sessão e segurança.',
            'Por quanto tempo. As evidências são guardadas enquanto o documento existir na conta da '
                .'remetente ou enquanto houver obrigação legal ou necessidade de comprovar o aceite.',
            sprintf(
                'Seus direitos. Para acessar, corrigir ou pedir informações sobre seus dados, contate '
                .'primeiro a remetente. Você também pode escrever à AssinaVelox em %s.',
                $support,
            ),
        ]);
    }

    /**
     * O que a plataforma **afirma** sobre a conclusão, exibido junto ao aceite. Sem
     * certificado configurado, a frase é literalmente "aceite eletrônico com evidências".
     */
    public static function completionNotice(?bool $withCertificate = null): string
    {
        $withCertificate ??= self::operatorCertificateActive();

        return $withCertificate
            ? 'Ao final, a AssinaVelox aplicará ao arquivo uma assinatura criptográfica com '
                .'certificado de sua própria titularidade. Ela identifica a operadora e detecta '
                .'alterações posteriores; não é a sua assinatura pessoal.'
            : 'Ao final, este documento será concluído como aceite eletrônico com evidências, '
                .'sem assinatura criptográfica. A integridade do arquivo é conferida pelo resumo '
                .'SHA-256 publicado na página de verificação.';
    }

    public static function operatorLegalName(): string
    {
        return (string) config('app.name', 'AssinaVelox');
    }

    public static function verificationUrl(): string
    {
        return rtrim((string) config('app.url'), '/').'/verificar';
    }
}
