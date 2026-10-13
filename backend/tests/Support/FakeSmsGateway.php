<?php

namespace Tests\Support;

use App\Contracts\SmsGateway;

/**
 * Captures SMS messages in memory so tests can read the codes that were sent.
 */
class FakeSmsGateway implements SmsGateway
{
    /** @var list<array{phone: string, message: string}> */
    public array $messages = [];

    public function send(string $phone, string $message): void
    {
        $this->messages[] = ['phone' => $phone, 'message' => $message];
    }

    public function lastCodeFor(string $phone): ?string
    {
        foreach (array_reverse($this->messages) as $sms) {
            if ($sms['phone'] === $phone && preg_match('/\b(\d{6})\b/', $sms['message'], $match)) {
                return $match[1];
            }
        }

        return null;
    }

    public function countFor(string $phone): int
    {
        return count(array_filter($this->messages, fn (array $sms) => $sms['phone'] === $phone));
    }
}
