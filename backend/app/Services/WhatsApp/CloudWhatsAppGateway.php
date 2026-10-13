<?php

namespace App\Services\WhatsApp;

use App\Contracts\WhatsAppGateway;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * WhatsApp Business Platform (Meta Cloud API). Messages a business starts must use a template Meta has
 * approved, so every alert goes through one template whose single body variable carries the alert text.
 */
class CloudWhatsAppGateway implements WhatsAppGateway
{
    public function send(string $phone, string $message): void
    {
        $config = config('mundalinq.whatsapp.cloud');

        $response = Http::withToken((string) $config['token'])->timeout(15)->post(
            "https://graph.facebook.com/{$config['api_version']}/{$config['phone_number_id']}/messages",
            [
                'messaging_product' => 'whatsapp',
                'to' => ltrim($phone, '+'),
                'type' => 'template',
                'template' => [
                    'name' => $config['template'],
                    'language' => ['code' => $config['language']],
                    'components' => [[
                        'type' => 'body',
                        'parameters' => [['type' => 'text', 'text' => $this->templateText($message)]],
                    ]],
                ],
            ],
        );

        if ($response->failed()) {
            throw new RuntimeException('WhatsApp did not take the message: HTTP '.$response->status().' '.$response->json('error.message', ''));
        }
    }

    /**
     * Template variables may not contain new lines, tabs or runs of spaces.
     */
    private function templateText(string $message): string
    {
        return Str::limit(trim((string) preg_replace('/\s+/', ' ', $message)), 1000);
    }
}
