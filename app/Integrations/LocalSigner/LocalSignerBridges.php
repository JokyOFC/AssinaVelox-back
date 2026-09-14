<?php

namespace App\Integrations\LocalSigner;

use App\Enums\LocalSignerComponent;
use App\Integrations\LocalSigner\Contracts\LocalSignerBridge;

/**
 * Componentes conhecidos, por nome. Resolução explícita (sem registro no container), para
 * que um nome vindo da requisição nunca instancie nada além destes.
 *
 * Por padrão: `simulated` → {@see FakeLocalSigner}; `nexu` → {@see NexuLocalSigner}
 * (produção desabilitada). Os parâmetros opcionais existem para os testes trocarem a
 * implementação de um componente por um dublê identificado — nunca por configuração.
 */
final class LocalSignerBridges
{
    private readonly LocalSignerBridge $simulated;

    private readonly LocalSignerBridge $nexu;

    public function __construct(
        private readonly NexuLocalSigner $nexuDescription,
        ?LocalSignerBridge $simulated = null,
        ?LocalSignerBridge $nexu = null,
    ) {
        $this->simulated = $simulated ?? app(FakeLocalSigner::class);
        $this->nexu = $nexu ?? $nexuDescription;
    }

    public function for(LocalSignerComponent $component): LocalSignerBridge
    {
        return match ($component) {
            LocalSignerComponent::Simulated => $this->simulated,
            LocalSignerComponent::Nexu => $this->nexu,
        };
    }

    public function tryFrom(?string $name): ?LocalSignerBridge
    {
        $component = LocalSignerComponent::tryFrom((string) $name);

        return $component === null ? null : $this->for($component);
    }

    public function simulator(): LocalSignerBridge
    {
        return $this->simulated;
    }

    /**
     * Protocolo da API local do NexU e o que falta para ligá-lo (para a UI orientar).
     *
     * @return array<string, mixed>
     */
    public function nexuProtocol(): array
    {
        return $this->nexuDescription->protocol();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function statuses(): array
    {
        return array_map(
            fn (LocalSignerComponent $component): array => $this->for($component)->detect()->toArray(),
            LocalSignerComponent::cases(),
        );
    }
}
