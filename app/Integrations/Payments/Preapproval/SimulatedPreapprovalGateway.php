<?php

namespace App\Integrations\Payments\Preapproval;

use Illuminate\Support\Str;

/**
 * Simulador IDENTIFICADO de assinaturas recorrentes (classe B). **Não é o Mercado Pago.**
 *
 * - `name()` = `preapproval_simulada`, `isSimulated()` = true, `simulated = true` em todo resultado;
 * - o `init_point` aponta para um domínio `.invalid` (RFC 2606), que nunca resolve;
 * - recusa operar em produção, qualquer que seja a configuração;
 * - não faz chamada de rede e não cobra nada.
 */
final class SimulatedPreapprovalGateway implements PreapprovalGateway
{
    public const NAME = 'preapproval_simulada';

    public const CHECKOUT_HOST = 'https://assinatura-falsa.assinavelox.invalid';

    /** @var array<string, PreapprovalResult> */
    private array $preapprovals = [];

    public function __construct(private readonly bool $production) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function isEnabled(): bool
    {
        return ! $this->production;
    }

    public function isSimulated(): bool
    {
        return true;
    }

    public function unavailableReason(): ?string
    {
        return $this->production ? PreapprovalUnavailable::PRODUCTION_DISABLED : null;
    }

    public function create(PreapprovalRequest $request): PreapprovalResult
    {
        $this->guard();

        $id = 'simulada-'.Str::lower((string) Str::ulid());

        return $this->preapprovals[$id] = new PreapprovalResult(
            preapprovalId: $id,
            status: 'pending',
            externalReference: $request->externalReference,
            initPoint: self::CHECKOUT_HOST.'/assinatura/'.$id,
            simulated: true,
        );
    }

    public function get(string $preapprovalId): PreapprovalResult
    {
        $this->guard();

        return $this->preapprovals[$preapprovalId] ?? throw PreapprovalUnavailable::unknown($preapprovalId);
    }

    public function cancel(string $preapprovalId): PreapprovalResult
    {
        $current = $this->get($preapprovalId);

        return $this->preapprovals[$preapprovalId] = new PreapprovalResult(
            preapprovalId: $current->preapprovalId,
            status: 'cancelled',
            externalReference: $current->externalReference,
            initPoint: null,
            simulated: true,
        );
    }

    private function guard(): void
    {
        if ($this->production) {
            throw PreapprovalUnavailable::simulatorOutsideProduction();
        }
    }
}
