<?php

namespace App\Http\Controllers\Sign;

use App\Enums\AuditEventType;
use App\Enums\DocumentVersionKind;
use App\Enums\EnvelopeStatus;
use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveSignerToken;
use App\Models\DocumentVersion;
use App\Models\SignatureAcceptance;
use App\Services\Documents\DocumentStorage;
use App\Services\Signing\AcceptanceReceipt;
use App\Services\Signing\SignerAudit;
use App\Services\Signing\SignerContext;
use App\Services\Signing\SignerDownloadGrants;
use App\Services\Signing\SignerSessions;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cópia para o signatário (ROUTES §1.3 `sign.download`).
 *
 * Dois tipos:
 *
 * - **`evidence`** — comprovante do aceite desta pessoa, sempre disponível depois que ela
 *   assina. Ver {@see AcceptanceReceipt} para o que ele é e o que ele deliberadamente não é.
 * - **`signed`** — o PDF final. Só existe depois da finalização (incremento 4): enquanto o
 *   envelope não estiver `completed` **e** `final_document_version_id` não apontar para uma
 *   versão `final` presente no disco, a resposta é 404 com uma frase que diz a verdade em vez
 *   de entregar o documento enviado fingindo ser o final.
 *
 * ## Autorização (divergência registrada em relação a ROUTES §1.3)
 *
 * A tabela de rotas coloca `sign.download` atrás de `signer.verified`. Isso não funciona na
 * prática: o aceite **consome** a sessão de assinatura (é o que impede um segundo aceite na
 * mesma aba), então no instante seguinte ao clique não existe mais sessão — e é justamente
 * aí que a pessoa quer baixar o comprovante.
 *
 * A autorização, então, é uma destas duas, **nas duas presa ao navegador**:
 *
 * - sessão de assinatura viva ({@see SignerSessions::current()}), ou
 * - janela de download aberta ({@see SignerDownloadGrants}) — o link `purpose=download`
 *   com expiração da arquitetura §4.7, emitido no aceite e na confirmação do código.
 *
 * O que NÃO autoriza é a mera existência de um aceite. Essa era a regra anterior, e ela
 * nunca expirava nem olhava o navegador: quem tivesse a URL do convite — e-mail
 * encaminhado, caixa compartilhada, backup de mailbox — baixava o comprovante e, na
 * conclusão, o PDF final assinado, sem jamais ter recebido o código por e-mail.
 *
 * Pela mesma razão, `envelope.downloaded` só é gravado quando o pedido está autorizado: um
 * GET anônimo não pode fabricar evidência de que o signatário baixou o arquivo.
 */
class DownloadController extends Controller
{
    public function __construct(
        private readonly DocumentStorage $storage,
        private readonly AcceptanceReceipt $receipt,
        private readonly SignerSessions $sessions,
        private readonly SignerDownloadGrants $grants,
    ) {}

    public function show(Request $request, string $token, string $type): Response
    {
        abort_unless(in_array($type, ['signed', 'evidence'], true), 404, 'Arquivo indisponível.');

        $context = ResolveSignerToken::context($request);

        $authorized = $this->sessions->current($context, $request) !== null
            || $this->grants->current($context, $request) !== null;

        abort_unless($authorized, 404, 'Arquivo indisponível.');

        /** @var SignatureAcceptance|null $acceptance */
        $acceptance = SignatureAcceptance::withoutOrganizationScope()
            ->where('recipient_id', $context->recipient->getKey())
            ->first();

        return $type === 'evidence'
            ? $this->evidence($context, $acceptance)
            : $this->signed($context);
    }

    private function evidence(SignerContext $context, ?SignatureAcceptance $acceptance): Response
    {
        abort_if(
            $acceptance === null,
            404,
            'O comprovante fica disponível depois que você registrar o aceite.',
        );

        $body = $this->receipt->render($context, $acceptance);

        SignerAudit::record($context->envelope, $context->recipient, AuditEventType::EnvelopeDownloaded, [
            'type' => 'evidence',
            'format' => 'text',
        ]);

        return response($body, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$this->receipt->filename($context).'"',
            'Content-Length' => (string) strlen($body),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
        ]);
    }

    private function signed(SignerContext $context): Response
    {
        $version = $context->envelope->status === EnvelopeStatus::Completed
            && $context->envelope->final_document_version_id !== null
                ? DocumentVersion::withoutOrganizationScope()
                    ->whereKey($context->envelope->final_document_version_id)
                    ->where('kind', DocumentVersionKind::Final->value)
                    ->first()
                : null;

        abort_if(
            $version === null,
            404,
            'O arquivo final ainda não está disponível. Ele é gerado quando todos os participantes concluírem.',
        );

        abort_unless($this->storage->exists($version), 404, 'O arquivo final ainda não está disponível.');

        SignerAudit::record($context->envelope, $context->recipient, AuditEventType::EnvelopeDownloaded, [
            'type' => 'signed',
            'document_version_ulid' => $version->ulid,
        ]);

        $filename = $this->storage->downloadFilename(
            $context->envelope->title.' (assinado)',
            $context->envelope->display_code,
            'pdf',
        );

        return $this->storage->stream($version, $filename, 'attachment', 'application/pdf');
    }
}
