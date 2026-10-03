<?php

namespace App\Services\Dealers;

/**
 * One credit decision. Order submit, the preview API, and both UIs read this object.
 */
final class DealerCreditAssessment
{
    public const STATUS_NO_LIMIT = 'no_limit_set';

    public const STATUS_SAFE = 'safe';

    public const STATUS_NEAR_LIMIT = 'near_limit';

    public const STATUS_LIMIT_REACHED = 'limit_reached';

    public const STATUS_ORDER_EXCEEDS = 'order_exceeds_limit';

    public const NEAR_UTILIZATION_PERCENT = 80.0;

    /**
     * @param  list<string>  $pendingStatuses
     */
    public function __construct(
        public readonly int $dealerId,
        public readonly bool $limitSet,
        public readonly string $status,
        public readonly string $statusLabel,
        public readonly float $currentOutstanding,
        public readonly float $pendingExposure,
        public readonly float $newOrderAmount,
        public readonly float $projectedExposure,
        public readonly ?float $baseLimit,
        public readonly float $extensionAmount,
        public readonly bool $extensionActive,
        public readonly bool $extensionExpired,
        public readonly ?string $extensionValidUntil,
        public readonly ?float $effectiveLimit,
        public readonly ?float $availableLimit,
        public readonly ?float $utilizationPercent,
        public readonly float $exceededBy,
        public readonly bool $blocksOrder,
        public readonly ?string $lastUpdatedBy,
        public readonly ?string $lastUpdatedAt,
        public readonly array $pendingStatuses,
    ) {}

    public function blocksOrder(): bool
    {
        return $this->blocksOrder;
    }

    public function message(): string
    {
        if (! $this->limitSet) {
            return 'Credit Limit: Not Set';
        }

        if ($this->blocksOrder) {
            return 'Credit Limit Exceeded. Contact Manager for Limit Extension.';
        }

        return $this->statusLabel;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'dealer_id' => $this->dealerId,
            'limit_set' => $this->limitSet,
            'status' => $this->status,
            'status_label' => $this->statusLabel,
            'current_outstanding' => $this->currentOutstanding,
            'pending_exposure' => $this->pendingExposure,
            'new_order_amount' => $this->newOrderAmount,
            'projected_exposure' => $this->projectedExposure,
            'base_limit' => $this->baseLimit,
            'extension_amount' => $this->extensionAmount,
            'extension_active' => $this->extensionActive,
            'extension_expired' => $this->extensionExpired,
            'extension_valid_until' => $this->extensionValidUntil,
            'effective_limit' => $this->effectiveLimit,
            'available_limit' => $this->availableLimit,
            'utilization_percent' => $this->utilizationPercent,
            'exceeded_by' => $this->exceededBy,
            'blocks_order' => $this->blocksOrder,
            'message' => $this->message(),
            'contact_message' => $this->blocksOrder ? 'Contact Manager for Limit Extension' : null,
            'last_updated_by' => $this->lastUpdatedBy,
            'last_updated_at' => $this->lastUpdatedAt,
            'pending_statuses' => $this->pendingStatuses,
        ];
    }
}
