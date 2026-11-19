<?php

namespace Tests\Feature;

use App\Models\JarvisBilling;
use App\Services\ForexService;
use App\Services\JarvisCreditService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class JarvisCreditServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_credit_balance_uses_a_cached_exchange_rate_when_frankfurter_is_unavailable(): void
    {
        Cache::put('forex.usd_php_rate', 56.25, now()->addDay());
        Http::fake(['api.frankfurter.app/latest*' => Http::response([], 503)]);
        Http::preventStrayRequests();
        JarvisBilling::query()->create([
            'provider' => 'anthropic',
            'current_balance_usd' => 5,
        ]);

        $credit = app(JarvisCreditService::class)->getBalance();

        $this->assertSame(56.25, $credit['usd_php_rate']);
        $this->assertSame(10.0, $credit['jarvis_usd_balance']);
        $this->assertSame(562.5, $credit['jarvis_php_balance']);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'api.frankfurter.app/latest'));
    }

    public function test_credit_balance_returns_usd_without_conversion_when_no_exchange_rate_is_available(): void
    {
        Cache::forget('forex.usd_php_rate');
        Http::fake(['api.frankfurter.app/latest*' => Http::response([], 503)]);
        Http::preventStrayRequests();
        JarvisBilling::query()->create([
            'provider' => 'anthropic',
            'current_balance_usd' => 5,
        ]);

        $credit = app(JarvisCreditService::class)->getBalance();

        $this->assertSame(10.0, $credit['jarvis_usd_balance']);
        $this->assertNull($credit['usd_php_rate']);
        $this->assertNull($credit['jarvis_php_balance']);
        $this->assertNull($credit['currency']);
    }

    public function test_forex_service_returns_null_after_a_connection_failure_without_a_cached_rate(): void
    {
        Cache::forget('forex.usd_php_rate');
        $requestWasAttempted = false;
        Http::fake([
            'api.frankfurter.app/latest*' => function (Request $request) use (&$requestWasAttempted) {
                $requestWasAttempted = true;

                return Http::failedConnection();
            },
        ]);
        Http::preventStrayRequests();

        $rate = app(ForexService::class)->usdToPhpRate();

        $this->assertNull($rate);
        $this->assertTrue($requestWasAttempted);
    }

    public function test_credit_endpoint_returns_success_when_php_conversion_is_unavailable(): void
    {
        Cache::forget('forex.usd_php_rate');
        Http::fake(['api.frankfurter.app/*' => Http::response([], 503)]);
        Http::preventStrayRequests();
        JarvisBilling::query()->create([
            'provider' => 'anthropic',
            'current_balance_usd' => 5,
        ]);

        $this->getJson('/jarvis/credits')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('credit.jarvis_usd_balance', 10)
            ->assertJsonPath('credit.jarvis_php_balance', null);
    }
}
