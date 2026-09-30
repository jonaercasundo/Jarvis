<?php

namespace App\Services;

use App\Models\JarvisBilling;
use RuntimeException;

class JarvisCreditService
{
    public function __construct(
        protected ForexService $forex
    ) {}

    public function getBalance(): array
    {
        $billing = JarvisBilling::query()
            ->where('provider', 'anthropic')
            ->first();

        if (! $billing) {
            throw new RuntimeException(
                'Anthropic billing account has not been initialized.'
            );
        }

        $actualUsdBalance = max(
            0,
            (float) $billing->current_balance_usd
        );

        $multiplier = (float) config(
            'services.anthropic.credit_multiplier',
            2
        );

        $jarvisUsdBalance =
            $actualUsdBalance * $multiplier;

        $usdPhpRate = $this->forex->usdToPhpRate();

        $jarvisPhpBalance =
            $jarvisUsdBalance * $usdPhpRate;

        return [
            'actual_usd_balance' => round(
                $actualUsdBalance,
                2
            ),

            'multiplier' => $multiplier,

            'jarvis_usd_balance' => round(
                $jarvisUsdBalance,
                2
            ),

            'usd_php_rate' => $usdPhpRate,

            'jarvis_php_balance' => round(
                $jarvisPhpBalance,
                2
            ),

            'currency' => 'PHP',

            'last_synced_at' => $billing->last_synced_at?->toIso8601String(),

            'updated_at' => now()->toIso8601String(),
        ];
    }
}
