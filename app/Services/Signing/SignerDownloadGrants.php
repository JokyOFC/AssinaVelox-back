<?php

namespace App\Services\Signing;

use App\Enums\AccessLinkPurpose;
use App\Models\RecipientAccessLink;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Autorização de download do signatário (`sign.download`) — arquitetura §4.7.
 *
 * ## Por que existe
 *
 * `sign.download` é a única rota de `assinar/{token}` fora de `signer.verified`, porque o
 * aceite **consome** a sessão de assinatura: no instante seguinte ao clique a pessoa quer
 * o comprovante e já não tem sessão. A regra anterior resolvia isso autorizando "existe um
 * aceite deste destinatário **ou** existe sessão viva" — e o primeiro ramo nunca expirava e
 * não olhava o navegador. Depois que o destinatário assinava, qualquer um com a URL do
 * convite (e-mail encaminhado, caixa compartilhada, computador de uso comum, backup de
 * mailbox, log de proxy) baixava o comprovante de aceite e, quando o envelope concluísse, o
 * PDF final inteiro — sem nunca ter recebido o código por e-mail. Era incoerente com a
 * própria rota vizinha: `sign.document`, que serve o PDF **não** assinado, exige sessão.
 *
 * ## O que substitui
 *
 * Uma autorização **curta e presa ao navegador**, exatamente o `recipient_access_links` com
 * `purpose = download` e `expires_at` que a arquitetura §4.7 já previa. O token bruto fica
 * na sessão Laravel (nunca na URL, nunca em cookie próprio); o banco guarda só o digest.
 * Ela é emitida em dois momentos: no aceite e na confirmação do código. Fora da janela, o
 * download responde 404 como qualquer outro pedido sem identidade confirmada.
 *
 * Quando o envelope conclui, o arquivo final chega por um link de download próprio no
 * e-mail de conclusão (arquitetura §4.7) — é esse o caminho de longo prazo, não a posse
 * indefinida do link de convite.
 */
final class SignerDownloadGrants
{
    public function __construct(private readonly Repository $config) {}

    public function ttlMinutes(): int
    {
        return max(1, (int) $this->config->get('assinavelox.signing_session.download_grant_minutes', 30));
    }

    /**
     * Abre (ou renova) a janela de download deste navegador para este destinatário.
     */
    public function issue(SignerContext $context, ?Request $request = null): ?RecipientAccessLink
    {
        $request ??= request();

        if (! $request->hasSession()) {
            return null;
        }

        $versionId = $context->envelope->sent_document_version_id;

        if ($versionId === null) {
            return null;
        }

        $raw = SignerTokens::generate();

        // Uma janela por destinatário: emitir outra fecha a anterior.
        RecipientAccessLink::withoutOrganizationScope()
            ->where('recipient_id', $context->recipient->getKey())
            ->where('purpose', AccessLinkPurpose::Download->value)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => Carbon::now()]);

        /** @var RecipientAccessLink $link */
        $link = RecipientAccessLink::query()->create([
            'recipient_id' => $context->recipient->getKey(),
            'envelope_id' => $context->envelope->getKey(),
            'document_version_id' => $versionId,
            'organization_id' => $context->envelope->organization_id,
            'token_digest' => SignerTokens::digest($raw),
            'purpose' => AccessLinkPurpose::Download,
            'expires_at' => Carbon::now()->addMinutes($this->ttlMinutes()),
        ]);

        $request->session()->put(self::sessionKey($context->recipient->ulid), $raw);

        return $link;
    }

    /**
     * Janela viva para ESTE destinatário neste navegador, se houver.
     */
    public function current(SignerContext $context, ?Request $request = null): ?RecipientAccessLink
    {
        $request ??= request();

        if (! $request->hasSession()) {
            return null;
        }

        $raw = $request->session()->get(self::sessionKey($context->recipient->ulid));

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        /** @var RecipientAccessLink|null $link */
        $link = RecipientAccessLink::withoutOrganizationScope()
            ->where('token_digest', SignerTokens::digest($raw))
            ->where('purpose', AccessLinkPurpose::Download->value)
            ->first();

        if ($link === null || ! SignerTokens::matches($link->token_digest, $raw)) {
            return null;
        }

        if ($link->recipient_id !== $context->recipient->getKey()
            || $link->envelope_id !== $context->envelope->getKey()) {
            return null;
        }

        return $link->isUsable() ? $link : null;
    }

    /**
     * Chave da sessão Laravel onde fica o token bruto da janela de download.
     */
    public static function sessionKey(string $recipientUlid): string
    {
        return 'signer.downloads.'.$recipientUlid;
    }
}
