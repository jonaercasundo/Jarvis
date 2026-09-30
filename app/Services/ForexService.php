<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class ForexService
{
    public function usdToPhpRate(): float
    {
        $response = Http::timeout(10)
            ->acceptJson()
            ->get('https://api.frankfurter.app/latest', [
                'from' => 'USD',
                'to' => 'PHP',
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('Unable to retrieve USD/PHP exchange rate.');
        }

        $rate = (float) data_get($response->json(), 'rates.PHP');

        if ($rate <= 0) {
            throw new RuntimeException('Invalid USD/PHP exchange rate.');
        }

        return $rate;
    }
}
