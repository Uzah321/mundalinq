<?php

namespace App\Services\Payments;

use App\Enums\ProviderState;

final readonly class ProviderStatus
{
    /**
     * @param  array<string, mixed>  $raw  What the provider returned, kept for the payment log.
     */
    private function __construct(
        public ProviderState $state,
        public ?int $amountMinor = null,
        public ?string $reason = null,
        public array $raw = [],
    ) {}

    public static function pending(array $raw = []): self
    {
        return new self(ProviderState::Pending, raw: $raw);
    }

    public static function paid(int $amountMinor, array $raw = []): self
    {
        return new self(ProviderState::Paid, $amountMinor, raw: $raw);
    }

    public static function failed(string $reason, array $raw = []): self
    {
        return new self(ProviderState::Failed, reason: $reason, raw: $raw);
    }
}
