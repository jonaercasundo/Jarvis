<?php

namespace App\Services;

use DateTimeImmutable;
use Illuminate\Validation\ValidationException;

class ForecastService
{
    /** Forecast ordered, contiguous monthly or annual observations using a simple linear trend. */
    public function forecast(array $historical, int $horizon): array
    {
        if (count($historical) < 3 || $horizon < 1 || $horizon > 12) {
            throw ValidationException::withMessages(['forecast' => "I don't have enough historical data to forecast this yet. Supply at least three consecutive periods."]);
        }
        $first = (string) $historical[0]['label'];
        $monthly = preg_match('/^\d{4}-(?:0[1-9]|1[0-2])$/D', $first) === 1;
        $annual = preg_match('/^\d{4}$/D', $first) === 1;
        if (! $monthly && ! $annual) {
            throw ValidationException::withMessages(['forecast' => 'Use explicit monthly periods such as 2026-01 or annual periods such as 2026.']);
        }
        $start = new DateTimeImmutable($monthly ? $first.'-01' : $first.'-01-01');
        $unit = $monthly ? 'month' : 'year';
        $format = $monthly ? 'Y-m' : 'Y';
        $values = [];
        foreach ($historical as $index => $point) {
            if ($point['label'] !== $start->modify('+'.$index.' '.$unit)->format($format) || ! is_numeric($point['value'])) {
                throw ValidationException::withMessages(['forecast' => 'The historical periods contain gaps or missing values. Supply consecutive observations; I will not fill them in.']);
            }
            $values[] = (float) $point['value'];
        }
        $count = count($values);
        $meanX = ($count - 1) / 2;
        $meanY = array_sum($values) / $count;
        $numerator = 0.0;
        $denominator = 0.0;
        foreach ($values as $index => $value) {
            $numerator += ($index - $meanX) * ($value - $meanY);
            $denominator += ($index - $meanX) ** 2;
        }
        $slope = $numerator / $denominator;
        $intercept = $meanY - $slope * $meanX;
        $forecast = [];
        for ($index = $count; $index < $count + $horizon; $index++) {
            $forecast[] = ['label' => $start->modify('+'.$index.' '.$unit)->format($format), 'value' => round($intercept + $slope * $index, 2)];
        }

        return ['historical' => $historical, 'forecast' => $forecast, 'method' => 'linear_trend',
            'explanation' => 'A simple linear trend using '.$count.' consecutive '.$unit.'ly observations. It assumes the recent trend continues; estimates are uncertain and do not account for seasonality.'];
    }
}
