<?php

namespace App\Integrations\LocalSigner\Dto;

use App\Enums\LocalSignerComponent;

/**
 * Resultado de `detect()`: o componente pode ser usado agora? `reason` explica o "não"
 * (`production_disabled`, `environment_not_allowed`, `disabled`, `pfx_not_configured`, ...).
 */
final readonly class LocalSignerStatus
{
    public function __construct(
        public LocalSignerComponent $component,
        public bool $available,
        public bool $simulated,
        public bool $productionEnabled,
        public ?string $version = null,
        public ?string $reason = null,
    ) {}

    /**
     * @return array{component: string, label: string, available: bool, simulated: bool, production_enabled: bool, version: string|null, reason: string|null}
     */
    public function toArray(): array
    {
        return [
            'component' => $this->component->value,
            'label' => $this->component->label(),
            'available' => $this->available,
            'simulated' => $this->simulated,
            'production_enabled' => $this->productionEnabled,
            'version' => $this->version,
            'reason' => $this->reason,
        ];
    }
}
