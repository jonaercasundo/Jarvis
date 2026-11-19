<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class ForexService
{
    public function usdToPhpRate(): ?float
    {
        $cacheKey = 'forex.usd_php_rate';

        try {
            $response = Http::connectTimeout(5)
                ->timeout(10)
                ->acceptJson()
                ->get('https://api.frankfurter.app/latest', [
                    'from' => 'USD',
                    'to' => 'PHP',
                ]);

            if (! $response->successful()) {
                throw new \RuntimeException('Frankfurter returned an unsuccessful response.');
            }

            $rate = (float) data_get($response->json(), 'rates.PHP');

            if ($rate <= 0) {
                throw new \RuntimeException('Frankfurter returned an invalid USD/PHP exchange rate.');
            }

            Cache::put($cacheKey, $rate, now()->addDays(7));

            return $rate;
        } catch (Throwable $exception) {
            $cachedRate = null;

            try {
                $cachedRate = Cache::get($cacheKey);
            } catch (Throwable $cacheException) {
                Log::warning('JARVIS could not read cached USD/PHP exchange rate', [
                    'exception' => $cacheException,
                ]);
            }

            Log::warning('JARVIS could not refresh USD/PHP exchange rate', [
                'exception' => $exception,
                'using_cached_rate' => is_numeric($cachedRate) && (float) $cachedRate > 0,
            ]);

            return is_numeric($cachedRate) && (float) $cachedRate > 0
                ? (float) $cachedRate
                : null;
        }
    }
}
