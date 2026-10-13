<?php

namespace Tests\Feature;

use App\Services\WhatsApp\CloudWhatsAppGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\Support\InteractsWithOrders;
use Tests\TestCase;

class WhatsAppAlertTest extends TestCase
{
    use InteractsWithOrders, RefreshDatabase;

    public function test_people_who_choose_whatsapp_get_alerts_there_instead_of_by_sms(): void
    {
        [$farmer, $farm] = $this->farmerWithFarm();
        Sanctum::actingAs($farmer);

        $this->patchJson('/api/v1/me/notification-preferences', ['channel' => 'whatsapp'])
            ->assertOk()
            ->assertJsonPath('data.channel', 'whatsapp')
            ->assertJsonPath('data.sms.orders', true);

        $this->addToCart($buyer = $this->buyer(), $this->listing($farm), '5');
        $order = $this->checkout($buyer);

        $this->assertStringStartsWith('New order '.$order->sellerOrders()->sole()->number, $this->whatsApp->to($farmer->phone)[0]);
        $this->assertSame([], $this->smsTo($farmer->phone));
    }

    public function test_alerts_go_by_sms_when_whatsapp_cannot_take_them(): void
    {
        [$farmer, $farm] = $this->farmerWithFarm();
        $farmer->forceFill(['notification_preferences' => ['channel' => 'whatsapp']])->save();
        $this->whatsApp->failing = true;

        $this->addToCart($buyer = $this->buyer(), $this->listing($farm), '5');
        $this->checkout($buyer);

        $this->assertStringStartsWith('New order', $this->smsTo($farmer->phone)[0]);
    }

    public function test_returns_422_for_an_unknown_channel(): void
    {
        [$farmer] = $this->farmerWithFarm();
        Sanctum::actingAs($farmer);

        $this->patchJson('/api/v1/me/notification-preferences', ['channel' => 'telegram'])->assertJsonValidationErrors('channel');
    }

    public function test_the_cloud_driver_sends_the_approved_template_with_the_alert_as_one_line(): void
    {
        config(['mundalinq.whatsapp.cloud' => [
            'token' => 'secret-token',
            'phone_number_id' => '555000',
            'template' => 'mundalinq_alert',
            'language' => 'en',
            'api_version' => 'v21.0',
        ]]);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]])]);

        (new CloudWhatsAppGateway)->send('+263771234567', "New order ML-1:\n2 items,  \$4.00");

        Http::assertSent(fn (ClientRequest $request) => $request->url() === 'https://graph.facebook.com/v21.0/555000/messages'
            && $request->hasHeader('Authorization', 'Bearer secret-token')
            && $request['to'] === '263771234567'
            && $request['template']['name'] === 'mundalinq_alert'
            && $request['template']['components'][0]['parameters'][0]['text'] === 'New order ML-1: 2 items, $4.00');
    }

    public function test_the_cloud_driver_reports_a_refused_message(): void
    {
        config(['mundalinq.whatsapp.cloud.phone_number_id' => '555000']);
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Template not approved']], 400)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Template not approved');

        (new CloudWhatsAppGateway)->send('+263771234567', 'Hello');
    }
}
