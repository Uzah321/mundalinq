<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGateway;
use App\Services\Payments\Gateways\SandboxGateway;
use Illuminate\Contracts\Foundation\Application;

class PaymentGatewayManager
{
    public function __construct(private Application $app) {}

    /**
     * The provider new payments go to.
     */
    public function defaultProvider(): string
    {
        return config('mundalinq.payments.provider');
    }

    /**
     * Null for unknown providers, and for the sandbox in production: it approves payments without
     * moving money, so it must never be reachable there.
     */
    public function find(string $provider): ?PaymentGateway
    {
        return match ($provider) {
            'sandbox' => $this->app->environment('production') ? null : $this->app->make(SandboxGateway::class),
            default => null,
        };
    }
}
