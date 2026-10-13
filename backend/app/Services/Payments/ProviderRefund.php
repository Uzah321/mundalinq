<?php

namespace App\Services\Payments;

use App\Enums\RefundStatus;

/**
 * The provider's answer to a refund request.
 */
final readonly class ProviderRefund
{
    /**
     * @param  array<string, mixed>  $raw  What the provider returned, kept for support.
     */
    private function __construct(
        public RefundStatus $status,
        public ?string $reference = null,
        public ?string $reason = null,
        public array $raw = [],
    ) {}

    public static function succeeded(string $reference, array $raw = []): self
    {
        return new self(RefundStatus::Succeeded, $reference, raw: $raw);
    }

    /**
     * Accepted, money not sent yet.
     */
    public static function pending(string $reference, array $raw = []): self
    {
        return new self(RefundStatus::Pending, $reference, raw: $raw);
    }

    public static function failed(string $reason, array $raw = []): self
    {
        return new self(RefundStatus::Failed, reason: $reason, raw: $raw);
    }
}
