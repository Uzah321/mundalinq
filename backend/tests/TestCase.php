<?php

namespace Tests;

use App\Contracts\SmsGateway;
use App\Contracts\WhatsAppGateway;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\FakeSmsGateway;
use Tests\Support\FakeWhatsAppGateway;

abstract class TestCase extends BaseTestCase
{
    protected FakeSmsGateway $sms;

    protected FakeWhatsAppGateway $whatsApp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sms = new FakeSmsGateway;
        $this->app->instance(SmsGateway::class, $this->sms);
        $this->whatsApp = new FakeWhatsAppGateway;
        $this->app->instance(WhatsAppGateway::class, $this->whatsApp);
    }
}
