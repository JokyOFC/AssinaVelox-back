<?php

namespace App\Integrations\Payments\Dto;

/**
 * Página de GET /v1/payments/search: `paging.{total,limit,offset}` + `results[]`.
 */
final readonly class GatewayPaymentPage
{
    /**
     * @param  list<GatewayPaymentSummary>  $results
     */
    public function __construct(
        public array $results,
        public int $total,
        public int $offset,
        public int $limit,
    ) {}

    public function hasMore(): bool
    {
        return $this->offset + count($this->results) < $this->total && $this->results !== [];
    }
}
