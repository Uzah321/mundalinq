<?php

namespace App\Providers;

use App\Contracts\SmsGateway;
use App\Contracts\WhatsAppGateway;
use App\Models\PersonalAccessToken;
use App\Services\Sms\LogSmsGateway;
use App\Services\WhatsApp\CloudWhatsAppGateway;
use App\Services\WhatsApp\LogWhatsAppGateway;
use App\Support\Phone;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(SmsGateway::class, fn () => match (config('mundalinq.sms.driver')) {
            'log' => new LogSmsGateway,
            default => throw new InvalidArgumentException('Unsupported SMS driver ['.config('mundalinq.sms.driver').'].'),
        });

        $this->app->singleton(WhatsAppGateway::class, fn () => match (config('mundalinq.whatsapp.driver')) {
            'log' => new LogWhatsAppGateway,
            'cloud' => new CloudWhatsAppGateway,
            default => throw new InvalidArgumentException('Unsupported WhatsApp driver ['.config('mundalinq.whatsapp.driver').'].'),
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Surface N+1 queries in development and tests: list endpoints must eager-load what they show.
        Model::preventLazyLoading(! $this->app->isProduction());

        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));

        // Each new payment sends the buyer a prompt; status polls are cheap but should not be hammered.
        RateLimiter::for('payments', fn (Request $request) => Limit::perMinute(5)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('payment-status', fn (Request $request) => Limit::perMinute(60)->by($request->user()?->id ?: $request->ip()));

        // Every message can trigger notifications (and an SMS), so writing is rate limited per sender.
        RateLimiter::for('messages', fn (Request $request) => Limit::perMinute(20)->by($request->user()?->id ?: $request->ip()));

        // Handover codes are six digits: keep guessing impractical.
        RateLimiter::for('handover', fn (Request $request) => Limit::perMinute(10)->by(($request->user()?->id ?: $request->ip()).'|'.$request->path()));

        // SMS costs money and can be abused, so limit codes per recipient as well as per IP.
        RateLimiter::for('otp', function (Request $request) {
            $recipient = $request->user()?->phone ?? Phone::normalize($request->input('phone'));
            $key = is_string($recipient) ? $recipient : $request->ip();

            return [
                Limit::perMinute(1)->by('otp-minute:'.$key),
                Limit::perHour(5)->by('otp-hour:'.$key),
                Limit::perHour(20)->by('otp-ip:'.$request->ip()),
            ];
        });
    }
}
