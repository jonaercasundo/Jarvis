<?php

namespace App\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ArtifactValidationService
{
    public const REPORT_TYPES = ['visual_report', 'delivery_status', 'warehouse_status', 'billing_status', 'logistics_status', 'project_comparison', 'project_portfolio'];

    public const BLOCK_TYPES = ['kpi_grid', 'progress', 'progress_group', 'pipeline', 'bar_chart', 'line_chart', 'pie_chart', 'table', 'key_value', 'text_summary', 'timeline'];

    /** Source metadata is supplied by the application, never the model. */
    public function validate(array $input, array $evidence, array $source, array $suppliedNumbers = []): array
    {
        if (($input['type'] ?? null) === 'project_status') {
            return $this->validateProjectStatus($input, $evidence, $source, $suppliedNumbers);
        }
        $text = $this->textRules();
        $artifact = Validator::make(['artifact' => $input], [
            'artifact' => ['array:type,version,title,subtitle,blocks,source'], 'artifact.type' => ['required', 'in:'.implode(',', self::REPORT_TYPES)],
            'artifact.version' => ['sometimes', 'integer', 'in:1'], 'artifact.title' => ['required', ...$text, 'max:160'],
            'artifact.subtitle' => ['sometimes', ...$text, 'max:200'], 'artifact.blocks' => ['required', 'array', 'list', 'min:1', 'max:10'],
            'artifact.source' => ['sometimes', 'array:system,retrieved_at'], 'artifact.source.system' => ['sometimes', ...$text, 'max:100'],
            'artifact.source.retrieved_at' => ['sometimes', 'date'],
        ])->validate()['artifact'];
        $points = 0;
        $rows = 0;
        foreach ($artifact['blocks'] as $index => $block) {
            if (! is_array($block) || ! in_array($block['type'] ?? null, self::BLOCK_TYPES, true)) {
                $this->invalid('type', 'Unsupported report block.');
            }
            $type = $block['type'];
            $list = match ($type) {
                'kpi_grid', 'progress_group', 'key_value' => 'items', 'pipeline' => 'stages', 'timeline' => 'events', default => null,
            };
            $fields = $list ?? match ($type) {
                'progress' => 'label,value,unit,source_path,note', 'text_summary' => 'text', 'table' => 'columns,rows',
                default => 'unit,series,chart_type',
            };
            $rules = ['block' => ['array:type,title,'.$fields], 'block.title' => ['sometimes', ...$text, 'max:160']];
            if ($list) {
                $rules['block.'.$list] = ['required', 'array', 'list', 'min:1', 'max:12'];
                $rules += $this->itemRules('block.'.$list.'.*', $type);
            } elseif ($type === 'progress') {
                $rules += $this->itemRules('block', $type, false);
            } elseif ($type === 'text_summary') {
                $rules['block.text'] = ['required', ...$text];
            } elseif ($type === 'table') {
                $rules += ['block.columns' => ['required', 'array', 'list', 'min:1', 'max:12'], 'block.columns.*' => ['required', ...$text, 'max:160'],
                    'block.rows' => ['required', 'array', 'list', 'min:1', 'max:100'], 'block.rows.*' => ['required', 'array', 'list']];
            } else {
                $rules += ['block.unit' => ['required', ...$text, 'max:60'], 'block.chart_type' => ['sometimes', 'in:pie,donut'],
                    'block.series' => ['required', 'array', 'list', 'min:1', 'max:8'], 'block.series.*' => ['array:name,unit,data'],
                    'block.series.*.name' => ['required', ...$text, 'max:160'], 'block.series.*.unit' => ['sometimes', ...$text, 'max:60'],
                    'block.series.*.data' => ['required', 'array', 'list', 'min:1', 'max:100']];
                $rules += $this->itemRules('block.series.*.data.*', 'chart');
            }
            $block = Validator::make(['block' => $block], $rules)->validate()['block'];
            if (str_ends_with($type, '_chart')) {
                foreach ($block['series'] as $series) {
                    if (isset($series['unit']) && $this->canonicalUnit($series['unit']) !== $this->canonicalUnit($block['unit'])) {
                        $this->invalid('unit', 'All chart series must use the same unit.');
                    }
                    $points += count($series['data']);
                    $previous = null;
                    foreach ($series['data'] as $point) {
                        $this->verifyValue($point, $evidence, $suppliedNumbers, $block['unit']);
                        if ($type === 'line_chart') {
                            if (! preg_match('/^\d{4}(?:-\d{2}(?:-\d{2})?)?$/D', $point['label']) || ($previous !== null && strcmp($previous, $point['label']) >= 0)) {
                                $this->invalid('series', 'Line charts require ordered, unique dated observations.');
                            }
                            $previous = $point['label'];
                        }
                    }
                }
                if ($type === 'pie_chart') {
                    $values = array_column($block['series'][0]['data'], 'value');
                    if (count($block['series']) !== 1 || count($values) > 8 || in_array(null, $values, true) || min($values) < 0 || array_sum($values) <= 0 || ($block['unit'] === '%' && abs(array_sum($values) - 100) > 0.01)) {
                        $this->invalid('series', 'Pie charts require a small, nonnegative part-to-whole distribution.');
                    }
                }
            } elseif ($type === 'table') {
                $rows += count($block['rows']);
                foreach ($block['rows'] as $row) {
                    if (count($row) !== count($block['columns'])) {
                        $this->invalid('rows', 'Table rows must match the columns.');
                    }
                    foreach ($row as $cell) {
                        $cell = Validator::make(['cell' => $cell], $this->itemRules('cell', 'cell'))->validate()['cell'];
                        $this->verifyValue($cell, $evidence, $suppliedNumbers);
                    }
                }
            } elseif ($type === 'progress') {
                $this->verifyValue($block, $evidence, $suppliedNumbers, '%');
            } elseif ($list && $type !== 'timeline') {
                foreach ($block[$list] as $item) {
                    $this->verifyValue($item, $evidence, $suppliedNumbers, $type === 'progress_group' ? '%' : null);
                    foreach (['total', 'percent'] as $field) {
                        if (array_key_exists($field, $item)) {
                            $this->verifyValue(['value' => $item[$field], 'source_path' => $item[$field.'_source_path'] ?? null], $evidence, $suppliedNumbers, $field === 'percent' ? '%' : ($item['unit'] ?? null));
                        }
                    }
                }
            } elseif ($type === 'timeline') {
                foreach ($block['events'] as $event) {
                    if ($evidence !== [] && (! isset($event['source_path']) || data_get($evidence, $event['source_path']) !== $event['date'])) {
                        $this->invalid('date', 'Timeline dates must match retrieved evidence.');
                    }
                }
            }
            array_walk_recursive($block, function (mixed &$value, mixed $key): void {
                if (in_array($key, ['value', 'total', 'percent'], true) && is_numeric($value)) {
                    $value = (float) $value;
                }
                if ($key === 'unit' && is_string($value)) {
                    $value = $this->canonicalUnit($value);
                }
            });
            $artifact['blocks'][$index] = $block;
        }
        if ($points > 100) {
            $this->invalid('series', 'Reports support at most 100 chart points.');
        }
        if ($rows > 100) {
            $this->invalid('rows', 'Reports support at most 100 table rows.');
        }
        $source = Validator::make($source, ['system' => ['required', ...$text, 'max:100'], 'retrieved_at' => ['required', 'date']])->validate();

        return [...$artifact, 'version' => 1, 'source' => $source];
    }

    /** Validate the project contract against the same verified Tracker result. */
    private function validateProjectStatus(array $input, array $evidence, array $source, array $suppliedNumbers): array
    {
        $text = $this->textRules();
        $metric = ['present', 'nullable', 'numeric', 'min:0', 'max:100000000000000'];
        $percentage = ['present', 'nullable', 'numeric', 'between:0,100'];
        $artifact = Validator::make(['artifact' => $input], [
            'artifact' => ['array:type,version,title,project,warehouse_readiness,pipeline,overall_progress,source'],
            'artifact.type' => ['required', 'in:project_status'], 'artifact.version' => ['sometimes', 'integer', 'in:1'],
            'artifact.title' => ['required', ...$text, 'max:160'],
            'artifact.project' => ['required', 'array:name,status'],
            'artifact.project.name' => ['required', ...$text], 'artifact.project.status' => ['required', ...$text, 'max:160'],
            'artifact.warehouse_readiness' => ['present', 'array', 'list', 'max:12'],
            'artifact.warehouse_readiness.*' => ['array:unit,percentage'],
            'artifact.warehouse_readiness.*.unit' => ['required', ...$text, 'max:60', 'distinct:strict'],
            'artifact.warehouse_readiness.*.percentage' => $percentage,
            'artifact.pipeline' => ['required', 'array', 'list', 'min:1', 'max:12'],
            'artifact.pipeline.*' => ['array:key,label,value,status,total,percentage,unit,note'],
            'artifact.pipeline.*.key' => ['required', 'string', 'max:60', 'regex:/^[A-Za-z0-9_]+$/D', 'distinct:strict'],
            'artifact.pipeline.*.label' => ['required', ...$text, 'max:160'], 'artifact.pipeline.*.value' => $metric,
            'artifact.pipeline.*.total' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100000000000000'],
            'artifact.pipeline.*.percentage' => ['sometimes', 'nullable', 'numeric', 'between:0,100'],
            'artifact.pipeline.*.unit' => ['sometimes', ...$text, 'max:60'],
            'artifact.pipeline.*.status' => ['sometimes', ...$text, 'max:160'], 'artifact.pipeline.*.note' => ['sometimes', ...$text],
            'artifact.overall_progress' => $percentage,
            'artifact.source' => ['sometimes', 'array:system,retrieved_at'],
            'artifact.source.system' => ['sometimes', ...$text, 'max:100'], 'artifact.source.retrieved_at' => ['sometimes', 'date'],
        ])->validate()['artifact'];

        if ($evidence !== []) {
            $actualName = data_get($evidence, 'project.project_name');
            $names = is_string($actualName) ? [$actualName] : [];
            if (is_string($actualName) && preg_match('/^\(([^)]+)\)/u', $actualName, $match)) {
                $names[] = $match[1];
            }
            $normalizeName = fn (string $name): string => mb_strtolower(preg_replace('/[^\p{L}\p{N}]/u', '', $name) ?? '');
            if (! in_array($normalizeName($artifact['project']['name']), array_map($normalizeName, $names), true)
                || $artifact['project']['status'] !== data_get($evidence, 'project.project_status')) {
                $this->invalid('artifact.project', 'Project identity and status must match retrieved evidence.');
            }
        }
        foreach ($artifact['warehouse_readiness'] as $index => &$row) {
            $rows = data_get($evidence, 'warehouse_readiness.by_unit', []);
            $matched = array_keys(array_filter(is_array($rows) ? $rows : [], fn (array $actual): bool => ($actual['unit'] ?? null) === $row['unit']));
            $path = $matched !== [] ? 'warehouse_readiness.by_unit.'.$matched[0].'.percent' : null;
            $this->verifyValue(['value' => $row['percentage'], 'source_path' => $path], $evidence, $suppliedNumbers, '%');
            $row['percentage'] = $row['percentage'] === null ? null : (float) $row['percentage'];
        }
        unset($row);
        foreach ($artifact['pipeline'] as &$stage) {
            $base = 'operational_pipeline.'.$stage['key'];
            $unit = data_get($evidence, $base.'.unit');
            if (is_string($unit)) {
                if (isset($stage['unit']) && $this->canonicalUnit($stage['unit']) !== $this->canonicalUnit($unit)) {
                    $this->invalid('artifact.pipeline', 'Pipeline units must match the source.');
                }
                $stage['unit'] = $unit;
            }
            foreach (['value' => 'completed', 'total' => 'total', 'percentage' => 'percent'] as $field => $sourceField) {
                if (array_key_exists($field, $stage)) {
                    $this->verifyValue(['value' => $stage[$field], 'source_path' => $base.'.'.$sourceField], $evidence, $suppliedNumbers, $field === 'percentage' ? '%' : $unit);
                    $stage[$field] = $stage[$field] === null ? null : (float) $stage[$field];
                }
            }
        }
        unset($stage);
        $this->verifyValue(['value' => $artifact['overall_progress'], 'source_path' => 'overall_progress'], $evidence, $suppliedNumbers, '%');
        $artifact['overall_progress'] = $artifact['overall_progress'] === null ? null : (float) $artifact['overall_progress'];
        $source = Validator::make($source, ['system' => ['required', ...$text, 'max:100'], 'retrieved_at' => ['required', 'date']])->validate();

        return [...$artifact, 'version' => 1, 'source' => $source];
    }

    /** Known equivalent labels are normalized without combining different measurement units. */
    private function canonicalUnit(string $unit): string
    {
        return $unit === 'allocations' ? 'package allocations' : $unit;
    }

    /** Log only validated field names and redacted rule messages, never the input payload. */
    public function diagnostics(\Throwable $exception): array
    {
        $errors = $exception instanceof ValidationException ? $exception->errors() : [];
        $safeErrors = [];
        foreach (array_slice($errors, 0, 20, true) as $field => $messages) {
            $safeField = preg_replace('/[^A-Za-z0-9_.]/', '', (string) $field) ?: 'artifact';
            $safeErrors[$safeField] = array_map(fn (string $message): string => mb_substr(app(SourceSecretRedactor::class)->text($message), 0, 300), array_slice($messages, 0, 3));
        }

        return ['exception_class' => $exception::class, 'validation_errors' => $safeErrors];
    }

    private function textRules(): array
    {
        return ['string', 'max:500', 'not_regex:~[<>]|https?:|javascript:|data:|\b(?:eval|Function)\s*\(|\bBearer\s|on\w+\s*=~i'];
    }

    private function itemRules(string $base, string $type, bool $checkKeys = true): array
    {
        $text = $this->textRules();
        $path = ['sometimes', 'string', 'max:200', 'regex:/^[A-Za-z0-9_]+(?:\.[A-Za-z0-9_]+)*$/D', 'not_regex:/token|password|secret|authorization|api_key/i'];
        $rules = [$base.'.value' => ['present', 'nullable', function (string $attribute, mixed $value, \Closure $fail) use ($text, $type): void {
            if (in_array($type, ['key_value', 'cell', 'timeline'], true)) {
                if (! is_scalar($value) || (is_string($value) && Validator::make(['text' => $value], ['text' => $text])->fails())) {
                    $fail('Use a safe plain value.');
                }
            } elseif (! is_numeric($value) || ! is_finite((float) $value) || abs((float) $value) > 100000000000000) {
                $fail('Use a finite numerical value.');
            } elseif (in_array($type, ['progress', 'progress_group'], true) && ($value < 0 || $value > 100)) {
                $fail('Progress must be between 0 and 100.');
            }
        }], $base.'.source_path' => $path];
        if ($type !== 'cell') {
            $rules[$base.'.label'] = ['required', ...$text, 'max:160'];
        }
        if ($type !== 'chart') {
            $rules[$base.'.unit'] = ['sometimes', ...$text, 'max:60'];
            $rules[$base.'.note'] = ['sometimes', ...$text];
        }
        $keys = $type === 'cell' ? 'value,unit,source_path' : 'label,value,unit,source_path,note';
        if ($type === 'chart') {
            $keys = 'label,value,source_path';
        } elseif ($type === 'timeline') {
            $keys .= ',date';
            $rules[$base.'.date'] = ['required', 'date'];
        } elseif ($type === 'pipeline') {
            $keys .= ',total,total_source_path,percent,percent_source_path';
            $rules[$base.'.total'] = ['sometimes', 'nullable', 'numeric', 'min:0'];
            $rules[$base.'.percent'] = ['sometimes', 'nullable', 'numeric', 'between:0,100'];
            $rules[$base.'.total_source_path'] = $path;
            $rules[$base.'.percent_source_path'] = $path;
        }
        if ($checkKeys) {
            $rules[$base] = ['required', 'array:'.$keys];
        }

        return $rules;
    }

    private function verifyValue(array $item, array $evidence, array $suppliedNumbers, ?string $unit = null): void
    {
        $value = $item['value'];
        $path = $item['source_path'] ?? null;
        if (is_string($value) && ! is_numeric($value) && $path === null) {
            return;
        }
        if ($evidence !== []) {
            if (! is_string($path) || ! Arr::has($evidence, $path)) {
                $this->invalid('source_path', 'Bind report values to retrieved data.');
            }
            $actual = data_get($evidence, $path);
            if ($actual !== $value && ! (is_numeric($actual) && is_numeric($value) && (float) $actual === (float) $value)) {
                $this->invalid('value', 'Report values must match the source exactly.');
            }
            $expectedUnit = $this->sourceUnit($path, $evidence);
            $providedUnit = $unit ?? $item['unit'] ?? null;
            if ($expectedUnit !== null && (! is_string($providedUnit) || $this->canonicalUnit($providedUnit) !== $this->canonicalUnit($expectedUnit))) {
                $this->invalid('unit', 'Report units must match the source.');
            }
        } elseif (is_numeric($value) && ! in_array((float) $value, $suppliedNumbers, true)) {
            $this->invalid('value', 'Report numbers require supplied or retrieved evidence.');
        }
    }

    private function sourceUnit(string $path, array $evidence): ?string
    {
        return match (true) {
            preg_match('/percent$|overall_progress$/', $path) === 1 => '%',
            preg_match('/(?:packages|package_allocations)_count$/', $path) === 1 => 'package allocations',
            str_ends_with($path, 'delivery_rows_count') => 'delivery rows',
            str_ends_with($path, 'deliveries_count') => 'DR receipts',
            str_ends_with($path, 'groups_count') => 'DR/group records',
            preg_match('/\.(?:completed|total|required|available|stock_in|stock_out)$/', $path) === 1 => data_get($evidence, substr($path, 0, strrpos($path, '.')).'.unit'),
            default => null,
        };
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
