<?php

namespace Tests\Support;

use App\Contracts\WhatsAppGateway;
use RuntimeException;

/**
 * Captures WhatsApp messages in memory; set $failing to make sends fail (and alerts fall back to SMS).
 */
class FakeWhatsAppGateway implements WhatsAppGateway
{
    /** @var list<array{phone: string, message: string}> */
    public array $messages = [];

    public bool $failing = false;

    public function send(string $phone, string $message): void
    {
        if ($this->failing) {
            throw new RuntimeException('WhatsApp is down.');
        }

        $this->messages[] = ['phone' => $phone, 'message' => $message];
    }

    /**
     * @return list<string>
     */
    public function to(string $phone): array
    {
        return array_values(array_map(fn (array $message) => $message['message'], array_filter($this->messages, fn (array $message) => $message['phone'] === $phone)));
    }
}
