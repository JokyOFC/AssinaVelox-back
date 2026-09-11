<?php

namespace App\Services\Signing;

use App\Enums\AcceptanceAction;
use App\Enums\RecipientRole;
use App\Integrations\Contracts\PdfSigner;
use App\Models\CertificateReference;
use App\Models\Document;
use App\Models\DocumentVersion;
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

    /*
     * Variantes do aviso de privacidade para quem NÃO assina (Fase 2 §2.4): o visualizador não
     * registra aceite nem recusa; o aprovador aprova sem representação visual de assinatura e
     * nenhuma imagem é gravada. O texto do signatário (e da testemunha, que assina) continua o
     * da Fase 1. PENDENTES DE REVISÃO JURÍDICA, como as demais variantes da Fase 2 —
     * mudou uma palavra, nova versão.
     */
    public const PRIVACY_NOTICE_VIEWER_VERSION = 'v1-viewer-2026-09-11';

    public const PRIVACY_NOTICE_APPROVER_VERSION = 'v1-approve-2026-09-11';

    /*
     * Variantes da Fase 2 (docs/fase-2/multi-documento-e-papeis.md §7). Textos escritos de
     * forma conservadora pela engenharia e PENDENTES DE REVISÃO JURÍDICA — mesma regra da
     * variante principal: mudou uma palavra, nova versão. Cada combinação papel × quantidade
     * de documentos tem a sua versão, gravada em `signature_acceptances.terms_version`.
     */
    public const MULTI_DOCUMENT_TERMS_VERSION = 'v1-multi-2026-09-11';

    public const WITNESS_TERMS_VERSION = 'v1-witness-2026-09-11';

    public const WITNESS_MULTI_TERMS_VERSION = 'v1-witness-multi-2026-09-11';

    public const APPROVAL_TERMS_VERSION = 'v1-approve-2026-09-11';

    public const APPROVAL_MULTI_TERMS_VERSION = 'v1-approve-multi-2026-09-11';

    /**
     * Versão vigente do texto.
     *
     * - signatário com um documento (Fase 1): a versão congelada no envio do envelope; na
     *   ausência dela (envelope antigo, dado incompleto), a constante atual;
     * - demais combinações (testemunha, aprovador, vários documentos): a versão da variante.
     */
    public static function versionFor(Envelope $envelope, ?Recipient $recipient = null, int $documentCount = 1): string
    {
        $action = $recipient?->role->acceptanceAction() ?? AcceptanceAction::Sign;
        $multi = $documentCount > 1;

        if ($action === AcceptanceAction::Sign && ! $multi) {
            $version = $envelope->terms_version;

            return is_string($version) && $version !== '' ? $version : self::ACCEPTANCE_TERMS_VERSION;
        }

        return match ($action) {
            AcceptanceAction::Witness => $multi ? self::WITNESS_MULTI_TERMS_VERSION : self::WITNESS_TERMS_VERSION,
            AcceptanceAction::Approve => $multi ? self::APPROVAL_MULTI_TERMS_VERSION : self::APPROVAL_TERMS_VERSION,
            AcceptanceAction::Sign => self::MULTI_DOCUMENT_TERMS_VERSION,
        };
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
    public static function checkboxLabel(Envelope $envelope, ?Recipient $recipient = null, int $documentCount = 1): string
    {
        $action = $recipient?->role->acceptanceAction() ?? AcceptanceAction::Sign;

        if ($action !== AcceptanceAction::Sign || $documentCount > 1) {
            return self::variantCheckboxLabel($envelope, $action, $documentCount);
        }

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
     * Signatário com um documento: o texto da Fase 1, palavra por palavra. Testemunha,
     * aprovador ou vários documentos: a variante correspondente ({@see self::variantStatement()}).
     *
     * @param  string  $documentSha256  resumo dos bytes da versão apresentada (a do primeiro documento)
     * @param  list<array{document: Document, version: DocumentVersion}>  $documents  todos os documentos apresentados (Fase 2)
     */
    public static function statement(
        Envelope $envelope,
        Recipient $recipient,
        Organization $organization,
        string $documentSha256,
        ?bool $withCertificate = null,
        array $documents = [],
    ): string {
        $withCertificate ??= self::operatorCertificateActive();

        $action = $recipient->role->acceptanceAction() ?? AcceptanceAction::Sign;

        if ($action !== AcceptanceAction::Sign || count($documents) > 1) {
            return self::variantStatement($envelope, $recipient, $organization, $documentSha256, $withCertificate, $documents, $action);
        }

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
     * Variantes da Fase 2 — testemunha, aprovador e vários documentos.
     *
     * ⚠ Redação conservadora escrita pela engenharia a partir da minuta da §3; EXIGE REVISÃO
     * JURÍDICA antes de ativar a flag em produção (docs/fase-2/multi-documento-e-papeis.md §7).
     * Princípios mantidos: descreve só fatos registrados; representação visual ≠ aceite ≠
     * assinatura criptográfica; a testemunha não se torna parte; a aprovação não é assinatura.
     *
     * @param  list<array{document: Document, version: DocumentVersion}>  $documents
     */
    private static function variantStatement(
        Envelope $envelope,
        Recipient $recipient,
        Organization $organization,
        string $documentSha256,
        bool $withCertificate,
        array $documents,
        AcceptanceAction $action,
    ): string {
        $sender = $organization->legal_name ?: $organization->name;
        $operator = self::operatorLegalName();
        $verificationUrl = self::verificationUrl();
        $verificationCode = $envelope->formatted_verification_code ?? '—';
        $count = max(1, count($documents));
        $multi = $count > 1;

        $heading = match ($action) {
            AcceptanceAction::Sign => 'Declaração de aceite eletrônico',
            AcceptanceAction::Witness => 'Declaração de aceite eletrônico como testemunha',
            AcceptanceAction::Approve => 'Declaração de aprovação eletrônica',
        };

        $capacity = match ($action) {
            AcceptanceAction::Sign => '',
            AcceptanceAction::Witness => ' na qualidade de testemunha,',
            AcceptanceAction::Approve => ' na qualidade de aprovador(a),',
        };

        // Objeto do item 1: um documento, ou a lista de documentos com seus resumos.
        $object = $multi
            ? sprintf(
                '%d documentos da solicitação "%s", enviados por %s, cujos conteúdos apresentados nesta tela correspondem aos resumos SHA-256 relacionados abaixo',
                $count,
                $envelope->title,
                $sender,
            )
            : sprintf(
                'documento "%s", enviado por %s, cujo conteúdo apresentado nesta tela corresponde ao resumo SHA-256 %s',
                $envelope->title,
                $sender,
                $documentSha256,
            );

        $item1 = match ($action) {
            AcceptanceAction::Sign => sprintf('1. Li integralmente %s %s, e concordo com o conteúdo %s.',
                $multi ? 'os' : 'o', $object, $multi ? 'de cada um deles' : 'do documento'),
            AcceptanceAction::Witness => sprintf('1. Tive acesso integral %s %s, e declaro, na qualidade de testemunha, ter tomado conhecimento do seu conteúdo e da sua celebração por meio desta plataforma. Esta declaração não me torna parte %s nem expressa concordância pessoal com as obrigações nele%s previstas.',
                $multi ? 'aos' : 'ao', $object, $multi ? 'dos documentos' : 'do documento', $multi ? 's' : ''),
            AcceptanceAction::Approve => sprintf('1. Li integralmente %s %s, e aprovo o conteúdo %s.',
                $multi ? 'os' : 'o', $object, $multi ? 'de cada um deles' : 'do documento'),
        };

        $lines = [
            sprintf('%s — versão %s', $heading, self::versionFor($envelope, $recipient, $count)),
            '',
            sprintf(
                'Eu, %s, identificado(a) nesta solicitação pelo e-mail %s,%s declaro que:',
                $recipient->name,
                $recipient->masked_email,
                $capacity,
            ),
            '',
            $item1,
        ];

        if ($multi) {
            foreach ($documents as $index => $row) {
                $lines[] = sprintf('   1.%d. "%s" — SHA-256 %s', $index + 1, $row['document']->name, $row['version']->sha256);
            }
        }

        $lines[] = match ($action) {
            AcceptanceAction::Sign => '2. Os campos que preenchi nesta tela e a imagem de assinatura que forneci (desenhada, '
                .'digitada ou enviada) são de minha autoria. Reconheço que essa imagem é uma '
                .'representação visual e que a minha manifestação de vontade é este aceite.',
            AcceptanceAction::Witness => '2. Os campos que preenchi nesta tela e a imagem de assinatura que forneci (desenhada, '
                .'digitada ou enviada) são de minha autoria. Reconheço que essa imagem é uma '
                .'representação visual e que a minha manifestação é este aceite eletrônico como testemunha.',
            AcceptanceAction::Approve => '2. Esta aprovação não contém representação visual de assinatura e não me torna '
                .'signatário(a) do documento. Os campos que eventualmente preenchi nesta tela são de minha autoria.',
        };

        $subject = $action === AcceptanceAction::Approve ? 'desta aprovação' : 'deste aceite';

        $lines[] = sprintf(
            '3. Estou ciente de que a AssinaVelox registrará, como evidência %s: a data e '
            .'a hora do servidor (UTC), o meu endereço IP, a identificação do meu navegador, o '
            .'método de autenticação (código de uso único confirmado no e-mail acima), a versão '
            .'exata %s, os campos apresentados e os valores preenchidos, e esta declaração.',
            $subject,
            $multi ? 'de cada documento' : 'do documento',
        );

        $lines[] = '4. '.($withCertificate
            ? sprintf(
                'Estou ciente de que, ao final da coleta de todos os aceites, a AssinaVelox (%s) '
                .'consolidará %s com uma página de evidências e aplicará %s '
                .'uma assinatura criptográfica com certificado digital de sua própria titularidade. '
                .'Essa assinatura identifica a AssinaVelox como operadora da plataforma e permite '
                .'detectar alterações posteriores no arquivo; ela não é a minha assinatura pessoal '
                .'nem um certificado digital emitido em meu nome.',
                $operator,
                $multi ? 'cada documento' : 'o documento',
                $multi ? 'a cada arquivo final' : 'ao arquivo final',
            )
            : sprintf(
                'Estou ciente de que, ao final da coleta de todos os aceites, a AssinaVelox (%s) '
                .'consolidará %s com uma página de evidências e %s como aceite '
                .'eletrônico com evidências, sem assinatura criptográfica. A integridade %s '
                .'poderá ser conferida %s, publicado%s na página de '
                .'verificação %s com o código %s.',
                $operator,
                $multi ? 'cada documento' : 'o documento',
                $multi ? 'os concluirá' : 'o concluirá',
                $multi ? 'de cada arquivo final' : 'do arquivo final',
                $multi ? 'pelo respectivo resumo SHA-256' : 'pelo seu resumo SHA-256',
                '',
                $verificationUrl,
                $verificationCode,
            ));

        $lines[] = sprintf(
            '5. Estou ciente de que a validade e os efeitos %s dependem da legislação '
            .'aplicável e do acordo entre as partes, e que a AssinaVelox não garante sua '
            .'aceitação por terceiros.',
            match ($action) {
                AcceptanceAction::Sign => 'deste aceite',
                AcceptanceAction::Witness => 'desta declaração de testemunha',
                AcceptanceAction::Approve => 'desta aprovação',
            },
        );

        $lines[] = sprintf(
            '6. Li o aviso de privacidade exibido nesta página e sei que posso recusar %s '
            .'informando um motivo.',
            match ($action) {
                AcceptanceAction::Sign => 'a assinatura',
                AcceptanceAction::Witness => 'a assinatura como testemunha',
                AcceptanceAction::Approve => 'a aprovação',
            },
        );

        return implode("\n", $lines);
    }

    /**
     * Rótulo da caixa nas variantes da Fase 2 (mesma estrutura do rótulo da §2).
     */
    private static function variantCheckboxLabel(Envelope $envelope, AcceptanceAction $action, int $documentCount): string
    {
        $what = $documentCount > 1
            ? sprintf('os %d documentos de %s', $documentCount, $envelope->title)
            : sprintf('o documento %s', $envelope->title);

        $version = $documentCount > 1 ? 'a versão exata de cada documento' : 'a versão exata do documento';

        return match ($action) {
            AcceptanceAction::Sign => sprintf(
                'Li %s e declaro que concordo com o conteúdo de cada um e que os dados aqui '
                .'registrados — data e hora, endereço IP, navegador, código confirmado por e-mail, '
                .'%s e os campos que preenchi — constituem evidência do meu aceite eletrônico.',
                $what,
                $version,
            ),
            AcceptanceAction::Witness => sprintf(
                'Tive acesso a %s e declaro, na qualidade de testemunha, que os dados aqui '
                .'registrados — data e hora, endereço IP, navegador, código confirmado por e-mail, '
                .'%s e os campos que preenchi — constituem evidência do meu aceite eletrônico como testemunha.',
                $what,
                $version,
            ),
            AcceptanceAction::Approve => sprintf(
                'Li %s e declaro que aprovo seu conteúdo e que os dados aqui registrados — data e '
                .'hora, endereço IP, navegador, código confirmado por e-mail e %s — '
                .'constituem evidência da minha aprovação eletrônica.',
                $what,
                $version,
            ),
        };
    }

    /**
     * Versão do aviso de privacidade exibido a este papel.
     */
    public static function privacyNoticeVersion(?RecipientRole $role = null): string
    {
        return match ($role) {
            RecipientRole::Viewer => self::PRIVACY_NOTICE_VIEWER_VERSION,
            RecipientRole::Approver => self::PRIVACY_NOTICE_APPROVER_VERSION,
            default => self::PRIVACY_NOTICE_VERSION,
        };
    }

    /**
     * Linha-resumo do aviso de privacidade, sempre visível antes do botão "Receber código".
     * O que se registra depende do papel: aceite (signatário e testemunha), aprovação ou só a
     * visualização.
     */
    public static function privacySummary(Organization $organization, ?RecipientRole $role = null): string
    {
        $what = match ($role) {
            RecipientRole::Viewer => 'Para registrar sua visualização',
            RecipientRole::Approver => 'Para registrar sua aprovação',
            default => 'Para registrar seu aceite',
        };

        return sprintf(
            'Este documento foi enviado por %s. %s, a AssinaVelox gravará '
            .'data, IP, navegador, a versão exata do documento e o código confirmado por e-mail.',
            $organization->name,
            $what,
        );
    }

    /**
     * Aviso de privacidade completo (menos de 350 palavras, conforme a minuta jurídica).
     */
    public static function privacyNotice(Organization $organization, ?RecipientRole $role = null): string
    {
        return match ($role) {
            RecipientRole::Viewer => self::viewerPrivacyNotice($organization),
            RecipientRole::Approver => self::approverPrivacyNotice($organization),
            default => self::signerPrivacyNotice($organization),
        };
    }

    private static function viewerPrivacyNotice(Organization $organization): string
    {
        $operator = self::operatorLegalName();
        $support = (string) config('assinavelox.support_email', 'suporte@assinavelox.com.br');

        return implode("\n\n", [
            'Como seus dados são usados nesta página',
            sprintf(
                'Quem é responsável pelos seus dados. Este documento foi enviado por %s, que decidiu '
                .'compartilhá-lo com você para acompanhamento e é a controladora dos seus dados pessoais. '
                .'A AssinaVelox (%s) é a operadora: trata os dados apenas para disponibilizar o documento, '
                .'seguindo as instruções da remetente.',
                $organization->name,
                $operator,
            ),
            'O que registramos e por quê. Para comprovar o acesso ao documento, gravamos: seu nome e '
                .'e-mail (informados pela remetente); o código de confirmação enviado ao seu e-mail '
                .'(guardado apenas de forma irreversível); a data e a hora do servidor (UTC); seu endereço '
                .'IP e a identificação do navegador; e a versão exata do documento que você viu (resumo '
                .'SHA-256). Como visualizador, você não registra aceite, não preenche campos e nenhuma '
                .'imagem de assinatura é gravada.',
            'A abertura deste link é registrada. Ao abrir esta página, registramos data, IP e navegador '
                .'como "abertura detectada". Isso não significa que você leu ou concordou com algo.',
            'Você não precisa fazer nada. Pode simplesmente fechar esta página. Não solicitar o código '
                .'não gera nenhum registro além da abertura.',
            'O que não fazemos. Não pedimos senha, CPF, foto ou localização. Não usamos cookies de '
                .'rastreamento nem anúncios nesta página; apenas cookies essenciais de sessão e segurança.',
            'Por quanto tempo. Os registros são guardados enquanto o documento existir na conta da '
                .'remetente ou enquanto houver obrigação legal ou necessidade de comprovar o acesso.',
            sprintf(
                'Seus direitos. Para acessar, corrigir ou pedir informações sobre seus dados, contate '
                .'primeiro a remetente. Você também pode escrever à AssinaVelox em %s.',
                $support,
            ),
        ]);
    }

    private static function approverPrivacyNotice(Organization $organization): string
    {
        $operator = self::operatorLegalName();
        $support = (string) config('assinavelox.support_email', 'suporte@assinavelox.com.br');

        return implode("\n\n", [
            'Como seus dados são usados nesta página',
            sprintf(
                'Quem é responsável pelos seus dados. Este documento foi enviado por %s, que decidiu '
                .'solicitar a sua aprovação e é a controladora dos seus dados pessoais. A AssinaVelox '
                .'(%s) é a operadora: trata os dados apenas para registrar a aprovação, seguindo as '
                .'instruções da remetente.',
                $organization->name,
                $operator,
            ),
            'O que registramos e por quê. Para que a sua aprovação tenha valor como evidência, gravamos: '
                .'seu nome e e-mail (informados pela remetente); o código de confirmação enviado ao seu '
                .'e-mail (guardado apenas de forma irreversível); a data e a hora do servidor (UTC); seu '
                .'endereço IP e a identificação do navegador; a versão exata do documento que você viu '
                .'(resumo SHA-256); os campos exibidos e os valores que você preencher; e o texto de '
                .'aprovação que você marcar. A aprovação não usa imagem de assinatura. Esses dados compõem '
                .'a página de evidências anexada ao documento final, entregue à remetente e a você.',
            'A abertura deste link é registrada. Ao abrir esta página, registramos data, IP e navegador '
                .'como "abertura detectada". Isso não significa que você leu ou concordou com algo.',
            'Se você não quiser aprovar. Você pode simplesmente fechar esta página, ou recusar e informar '
                .'o motivo, que será enviado à remetente. Não solicitar o código não gera nenhuma aprovação.',
            'O que não fazemos. Não pedimos senha, CPF, foto ou localização. Não usamos cookies de '
                .'rastreamento nem anúncios nesta página; apenas cookies essenciais de sessão e segurança.',
            'Por quanto tempo. As evidências são guardadas enquanto o documento existir na conta da '
                .'remetente ou enquanto houver obrigação legal ou necessidade de comprovar a aprovação.',
            sprintf(
                'Seus direitos. Para acessar, corrigir ou pedir informações sobre seus dados, contate '
                .'primeiro a remetente. Você também pode escrever à AssinaVelox em %s.',
                $support,
            ),
        ]);
    }

    private static function signerPrivacyNotice(Organization $organization): string
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
