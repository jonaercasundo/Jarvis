<?php

namespace App\Services;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class VisualizationBuilder
{
    /** Validate the untrusted visual boundary; never accept executable display options. */
    public function validate(array $input): array
    {
        $text = ['string', 'max:500', 'not_regex:/[<>]|(?:https?:|javascript:|data:|on\w+\s*=)/i'];
        $visual = Validator::make($input, [
            'type' => ['required', 'in:chart,metrics,table,process,timeline,comparison,forecast,email_preview'],
            'chart_type' => ['sometimes', 'in:line,bar,area,donut,pie,scatter'],
            'title' => ['required', ...$text, 'max:120'],
            'subtitle' => ['sometimes', ...$text, 'max:180'],
            'insight' => ['sometimes', ...$text], 'explanation' => ['sometimes', ...$text],
            'source' => ['sometimes', ...$text, 'max:120'], 'is_demo' => ['sometimes', 'boolean'],
            'x_axis' => ['sometimes', 'array:label'], 'x_axis.label' => ['sometimes', ...$text, 'max:80'],
            'y_axis' => ['sometimes', 'array:label,format,currency'], 'y_axis.label' => ['sometimes', ...$text, 'max:80'],
            'y_axis.format' => ['sometimes', 'in:number,currency,percent'], 'y_axis.currency' => ['sometimes', 'regex:/^[A-Z]{3}$/D'],
            'series' => ['required_if:type,chart,comparison,forecast', 'array', 'min:1', 'max:8'],
            'series.*' => ['array:name,data,forecast'], 'series.*.name' => ['required', ...$text, 'max:80'],
            'series.*.forecast' => ['sometimes', 'boolean'], 'series.*.data' => ['required', 'array', 'min:1', 'max:100'],
            'series.*.data.*' => ['array:label,value,x'], 'series.*.data.*.label' => ['required', ...$text, 'max:80'],
            'series.*.data.*.value' => ['present', 'nullable', 'numeric', 'between:-100000000000000,100000000000000'],
            'series.*.data.*.x' => ['sometimes', 'numeric', 'between:-100000000000000,100000000000000'],
            'metrics' => ['required_if:type,metrics', 'array', 'min:1', 'max:12'], 'metrics.*' => ['array:label,value'],
            'metrics.*.label' => ['required', ...$text, 'max:80'], 'metrics.*.value' => ['required', 'numeric', 'between:-100000000000000,100000000000000'],
            'columns' => ['required_if:type,table', 'array', 'min:1', 'max:20'], 'columns.*' => ['required', ...$text, 'max:80'],
            'rows' => ['required_if:type,table', 'array', 'min:1', 'max:100'], 'rows.*' => ['array', 'max:20'],
            'steps' => ['required_if:type,process,timeline', 'array', 'min:1', 'max:20'], 'steps.*' => ['array:label,detail,date'],
            'steps.*.label' => ['required', ...$text, 'max:80'], 'steps.*.detail' => ['sometimes', ...$text], 'steps.*.date' => ['sometimes', ...$text, 'max:80'],
            'items' => ['required_if:type,email_preview', 'array', 'min:1', 'max:100'], 'items.*' => ['array:sender,subject,action'],
            'items.*.sender' => ['required', ...$text, 'max:120'], 'items.*.subject' => ['required', ...$text, 'max:180'], 'items.*.action' => ['sometimes', ...$text, 'max:180'],
            'actions' => ['sometimes', 'array', 'max:2'], 'actions.*' => ['array:label,action'],
            'actions.*.label' => ['required', ...$text, 'max:40'], 'actions.*.action' => ['required', 'in:show_visual_data,show_visual_explanation'],
        ])->validate();
        foreach ($visual['rows'] ?? [] as $row) {
            if (array_diff(array_keys($row), $visual['columns'] ?? []) !== []) {
                throw ValidationException::withMessages(['rows' => 'Rows must match the declared columns.']);
            }
            foreach ($row as $value) {
                if (! is_scalar($value) && $value !== null) {
                    throw ValidationException::withMessages(['rows' => 'Table cells must be plain values.']);
                }
                if (is_string($value)) {
                    Validator::make(['value' => $value], ['value' => $text])->validate();
                }
            }
        }
        $points = 0;
        $hasValue = false;
        foreach ($visual['series'] ?? [] as $seriesIndex => $series) {
            $points += count($series['data']);
            foreach ($series['data'] as &$point) {
                if ($point['value'] !== null) {
                    $point['value'] = (float) $point['value'];
                    $hasValue = true;
                }
                if (isset($point['x'])) {
                    $point['x'] = (float) $point['x'];
                }
            }
            unset($point);
            $visual['series'][$seriesIndex] = $series;
        }
        unset($series);
        if (isset($visual['series']) && (! $hasValue || $points > 400)) {
            throw ValidationException::withMessages(['series' => 'Provide numeric observations, with at most 400 total points.']);
        }
        if (in_array($visual['type'], ['comparison', 'forecast'], true)) {
            $visual['chart_type'] = $visual['type'] === 'comparison' ? 'bar' : 'line';
            $visual['type'] = 'chart';
        }
        if ($visual['type'] === 'chart') {
            $visual['chart_type'] ??= 'line';
            if (in_array($visual['chart_type'], ['donut', 'pie'], true)
                && (count($visual['series']) !== 1 || collect($visual['series'][0]['data'])->contains(fn (array $point): bool => ($point['value'] ?? 0) < 0)
                    || collect($visual['series'][0]['data'])->sum('value') <= 0)) {
                throw ValidationException::withMessages(['series' => 'Distribution charts need one series of nonnegative values with a positive total.']);
            }
            if ($visual['chart_type'] === 'scatter') {
                foreach ($visual['series'] as $series) {
                    foreach ($series['data'] as $point) {
                        if (! isset($point['x'])) {
                            throw ValidationException::withMessages(['series' => 'Scatter observations require numeric x values.']);
                        }
                    }
                }
            }
        }

        return $visual;
    }

    /** @param array<int, array{label: string, value: float|int|null}> $data */
    public function buildLineChart(array $data, string $title, array $options = []): array
    {
        return $this->validate([...$options, 'type' => 'chart', 'chart_type' => 'line', 'title' => $title, 'series' => [['name' => 'Actual', 'data' => $data]]]);
    }

    public function buildBarChart(array $data, string $title, array $options = []): array
    {
        return $this->validate([...$options, 'type' => 'chart', 'chart_type' => 'bar', 'title' => $title, 'series' => [['name' => 'Comparison', 'data' => $data]]]);
    }

    public function buildDonutChart(array $data, string $title, array $options = []): array
    {
        return $this->validate([...$options, 'type' => 'chart', 'chart_type' => 'donut', 'title' => $title, 'series' => [['name' => 'Distribution', 'data' => $data]]]);
    }

    /** Convert calculated service results directly, preserving every supplied period and value. */
    public function buildForecastChart(array $historical, array $forecast, string $title, array $options = []): array
    {
        if ($historical === [] || $forecast === []) {
            throw ValidationException::withMessages(['forecast' => 'Historical observations and calculated forecast values are required.']);
        }

        return $this->validate([...$options, 'type' => 'chart', 'chart_type' => 'line', 'title' => $title,
            'series' => [['name' => 'Actual', 'data' => $historical], ['name' => 'Forecast', 'forecast' => true, 'data' => [...array_slice($historical, -1), ...$forecast]]]]);
    }

    public function buildMetrics(array $metrics, string $title): array
    {
        return $this->validate(['type' => 'metrics', 'title' => $title, 'metrics' => $metrics]);
    }

    public function buildTable(array $columns, array $rows, string $title): array
    {
        return $this->validate(['type' => 'table', 'title' => $title, 'columns' => $columns, 'rows' => $rows]);
    }

    public function buildProcess(array $steps, string $title): array
    {
        return $this->validate(['type' => 'process', 'title' => $title, 'steps' => $steps]);
    }

    /** Adapt existing server-produced chart artifacts without changing their stored representation. */
    public function fromChartArtifact(array $artifact): array
    {
        $data = $artifact['data'] ?? [];
        if (isset($data['series'][0]['data'])) {
            return $this->validate([...$data, 'type' => 'chart', 'title' => $artifact['title'] ?? 'Trend chart', 'source' => $artifact['source'] ?? '']);
        }
        $entries = $data['series'] ?? array_map(fn (string $label, int $index): array => ['label' => $label, 'value' => $data['values'][$index] ?? null], $data['labels'] ?? [], array_keys($data['labels'] ?? []));
        $actual = [];
        $forecast = [];
        foreach ($entries as $index => $entry) {
            $point = ['label' => $entry['label'] ?? '', 'value' => $entry['value'] ?? null];
            if (($entry['kind'] ?? '') === 'forecast') {
                if ($forecast === [] && $index > 0) {
                    $forecast[] = ['label' => $entries[$index - 1]['label'], 'value' => $entries[$index - 1]['value'] ?? null];
                }
                $forecast[] = $point;
            } else {
                $actual[] = $point;
            }
        }
        $type = $data['chart_type'] ?? $data['format'] ?? ($forecast !== [] || in_array($data['purpose'] ?? '', ['trend', 'forecast'], true) ? 'line' : 'bar');
        $options = ['source' => $artifact['source'] ?? '', 'is_demo' => (bool) ($artifact['is_mock'] ?? $data['is_sample'] ?? false)];
        if (is_string($data['assumptions'] ?? null)) {
            $options['explanation'] = $data['assumptions'];
        }
        if (count($entries) === 1 && $forecast === []) {
            return $this->validate([...$options, 'type' => 'metrics', 'title' => $artifact['title'] ?? 'Result', 'metrics' => [$actual[0]]]);
        }
        $series = [];
        if ($actual !== []) {
            $series[] = ['name' => 'Actual', 'data' => $actual];
        }
        if ($forecast !== []) {
            $series[] = ['name' => 'Forecast', 'forecast' => true, 'data' => $forecast];
        }

        return $this->validate([...$options, 'type' => 'chart', 'chart_type' => $type === 'forecast' ? 'line' : $type, 'title' => $artifact['title'] ?? 'Trend chart', 'series' => $series]);
    }

    /** Extract evidence including common business abbreviations; never interpret model output as evidence. */
    public function suppliedNumbers(string $text): array
    {
        preg_match_all('/(?<![\p{L}\d])(-?\d[\d,]*(?:\.\d+)?)\s*(million|billion|thousand|[kmb]\b)?/iu', $text, $matches, PREG_SET_ORDER);

        return array_slice(array_map(function (array $match): float {
            $multiplier = match (mb_strtolower($match[2] ?? '')) {
                'm', 'million' => 1000000, 'b', 'billion' => 1000000000, 'k', 'thousand' => 1000, default => 1,
            };

            return (float) str_replace(',', '', $match[1]) * $multiplier;
        }, $matches), 0, 600);
    }

    public function isSampleRequest(string $text): bool
    {
        return preg_match('/\b(?:sample|demo|mock|hypothetical|illustrative)\b/i', $text) === 1
            && ! $this->rejectsSamples($text);
    }

    public function rejectsSamples(string $text): bool
    {
        return preg_match('/\b(?:no|not|never|without)\b[^.!?]{0,30}\b(?:sample|demo|mock|hypothetical|illustrative)\b|\b(?:real|actual|company) (?:data|records)\b/i', $text) === 1;
    }
}
