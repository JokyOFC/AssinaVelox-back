<?php

namespace App\Services\Signing;

use App\Enums\AcceptanceAction;
use App\Enums\RecipientRole;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Envelope;
use App\Models\Organization;
use App\Models\Recipient;
use App\Models\RecipientAccessLink;
use App\Services\Documents\EnvelopeDocuments;

/**
 * Resultado da resolução do link, injetado na requisição pelo middleware
 * `App\Http\Middleware\ResolveSignerToken` e lido por todos os controllers de `Sign\`.
 *
 * `state` é o estado do CONVITE, decidido sem olhar a sessão do navegador:
 *
 * | state                           | significa                                                  |
 * |---------------------------------|------------------------------------------------------------|
 * | `active`                        | é a vez desta pessoa e ela ainda não respondeu             |
 * | `completed`                     | ela já aceitou e o envelope está concluído                  |
 * | `already_signed_pending_others` | ela já aceitou; faltam outros                               |
 * | `finalizing`                    | ela já aceitou, ninguém falta, o arquivo final está sendo preparado |
 * | `refused`                       | ela mesma recusou                                           |
 * | `expired`                       | prazo encerrado (dela ou do envelope)                       |
 * | `canceled`                      | o remetente cancelou, ou o envelope foi encerrado por outro |
 *
 * A escolha entre `identify` e `sign` é do controller, porque depende de haver sessão
 * autenticada — e a sessão é do navegador, não do link.
 *
 * O token bruto fica aqui apenas para montar as URLs da própria página; ele nunca é
 * gravado, nem em log, nem em auditoria.
 */
final class SignerContext
{
    public const STATE_ACTIVE = 'active';

    public const STATE_COMPLETED = 'completed';

    public const STATE_SIGNED_PENDING_OTHERS = 'already_signed_pending_others';

    /**
     * Todos assinaram e a finalização está em curso.
     *
     * Existe porque `already_signed_pending_others` mentia para quem assinava por último:
     * com nenhum pendente, a tela ainda prometia "você receberá o arquivo final quando
     * todos os participantes concluírem" — todos já tinham concluído.
     */
    public const STATE_FINALIZING = 'finalizing';

    public const STATE_REFUSED = 'refused';

    public const STATE_EXPIRED = 'expired';

    public const STATE_CANCELED = 'canceled';

    public function __construct(
        public readonly string $token,
        public readonly RecipientAccessLink $link,
        public readonly Recipient $recipient,
        public readonly Envelope $envelope,
        public readonly Organization $organization,
        public readonly string $state,
    ) {}

    public function isActive(): bool
    {
        return $this->state === self::STATE_ACTIVE;
    }

    public function hasSigned(): bool
    {
        return in_array($this->state, [
            self::STATE_COMPLETED,
            self::STATE_FINALIZING,
            self::STATE_SIGNED_PENDING_OTHERS,
        ], true);
    }

    /**
     * Versão do documento congelada no envio — a única que o signatário pode ver e a
     * única que um aceite pode referenciar (arquitetura §3.3).
     */
    public function sentVersion(): ?DocumentVersion
    {
        if ($this->envelope->sent_document_version_id === null) {
            return null;
        }

        return DocumentVersion::withoutOrganizationScope()
            ->whereKey($this->envelope->sent_document_version_id)
            ->first();
    }

    /**
     * TODOS os documentos do envelope com a versão congelada de cada um, na ordem de
     * apresentação (Fase 2 §2.3). Com um documento só, a lista tem um item e a versão é a
     * mesma de {@see self::sentVersion()}.
     *
     * @return list<array{document: Document, version: DocumentVersion}>
     */
    public function sentDocuments(): array
    {
        return EnvelopeDocuments::sent($this->envelope);
    }

    /**
     * Visualizador (Fase 2 §2.4): vê o documento depois do código, não registra aceite.
     */
    public function isViewer(): bool
    {
        return $this->recipient->role === RecipientRole::Viewer;
    }

    /**
     * O que o aceite desta pessoa registra (sign | witness | approve); `null` para o
     * visualizador.
     */
    public function action(): ?AcceptanceAction
    {
        return $this->recipient->role->acceptanceAction();
    }

    /**
     * Reconstrói o contexto com o envelope/destinatário recarregados (usado depois de
     * transições, para que as props reflitam o que acabou de ser gravado).
     */
    public function refreshed(): self
    {
        $envelope = Envelope::withoutOrganizationScope()->whereKey($this->envelope->getKey())->first() ?? $this->envelope;
        $recipient = Recipient::withoutOrganizationScope()->whereKey($this->recipient->getKey())->first() ?? $this->recipient;

        return new self(
            $this->token,
            $this->link,
            $recipient,
            $envelope,
            $this->organization,
            SignerLinkResolver::stateFor($envelope, $recipient) ?? $this->state,
        );
    }
}
