<?php

namespace App\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class VisualReportService
{
    public function __construct(private ArtifactValidationService $validator) {}

    public function intent(string $request, ?string $projectReference = null): string
    {
        if ($projectReference !== null && $projectReference !== '') {
            $request = str_ireplace($projectReference, '', $request);
        }
        $request = preg_split('/\s+(?:of|for)\s+(?:project\s+)?/i', $request, 2)[0];

        return match (true) {
            preg_match('/\b(?:warehouse|inventory|stock|readiness)\b/i', $request) === 1 => 'warehouse_status',
            preg_match('/\b(?:billing|billed|paid|collection)\b/i', $request) === 1 => 'billing_status',
            preg_match('/\b(?:logistics|dispatch|transport)\b/i', $request) === 1 => 'logistics_status',
            preg_match('/\b(?:delivery|deliveries|delivered|coverage|schools?|regions?|divisions?|municipalities)\b/i', $request) === 1 => 'delivery_status',
            default => 'project_status',
        };
    }

    /** Instructions describe a shared, extensible report contract, not one project's layout. */
    public function instructions(): string
    {
        return 'When the result benefits from a report, return an optional artifact object: {"type":"visual_report","version":1,"title":"...","blocks":[...]}; otherwise artifact=null. Choose the presentation that answers the user question. Default project status emphasizes operational flow with compact warehouse metrics below it; never average mixed warehouse units. Use warehouse_status for warehouse readiness or inventory, delivery_status for geographic delivery coverage, billing_status for recorded billing groups, logistics_status for logistics, project_comparison for selected projects and project_portfolio for project overviews. These types use the same blocks contract as visual_report. Keep separate readiness bars per item unit; inventory uses item tables. Never infer schools or location counts from DR receipts or package allocations. The application selects automatic artifacts from verified data. For project status use {"type":"project_status","title":"...","project":{"name":"...","status":"..."},"warehouse_readiness":[{"unit":"...","percentage":null}],"pipeline":[{"key":"dr","label":"DR","value":null}],"overall_progress":null}; copy values exactly from the same verified result. Pipeline entries may also have total,percentage,unit,note. Project status source bindings are resolved by the application. Allowed visual_report blocks: kpi_grid (items), progress (label,value), progress_group (items), pipeline (stages), bar_chart/line_chart/pie_chart (unit,series:[{name,data:[{label,value,source_path}]}]), table (columns,rows of cells {value,source_path,unit}), key_value (items), text_summary (text), timeline (events). Items use label,value,unit,source_path,note. Pipeline stages may also use total,total_source_path,percent,percent_source_path. Bind every numerical or status value with source_path to the actual returned data; retain null for unknown values. Use package allocations as the unit for allocation counts. At most 10 blocks, 100 chart points, 100 table rows. Each quantitative chart has exactly one shared unit: never mix DR receipts, package allocations, item quantities or percentages. Line charts require ordered dated observations. Pie charts are small part-to-whole distributions, not stage progress. Respect requested chart/table/progress style. Supply JSON only: no HTML, JavaScript, URLs, code or credentials. Keep the normal answer concise. The application supplies source attribution. Do not retrieve data again to create the report. Presentation failures are internal; finish the business answer without mentioning artifact validation unless the user asks about it.';
    }

    /** Optional presentation must never prevent delivery of a verified textual answer. */
    public function operations(array $data, string $request, array $source, mixed $candidate = null): ?array
    {
        try {
            $projectName = data_get($data, 'project.project_name');
            if (is_string($projectName) && $projectName !== '') {
                $request = str_ireplace($projectName, '', $request);
            }
            $explicit = preg_match('/\b(?:table|chart|graph|visuali[sz]e|timeline)\b/i', $request) === 1;
            if (($data['portfolio'] ?? false) === true) {
                return $this->validator->validate($this->portfolio($data), $data, $source);
            }
            if (isset($data['projects']) && is_array($data['projects']) && count($data['projects']) > 1) {
                return $this->validator->validate($this->comparison($data['projects'], $request), $data, $source);
            }
            $intent = $this->intent($request);
            if (isset($data['project']) && in_array($intent, ['delivery_status', 'logistics_status'], true)) {
                return $this->validator->validate($this->delivery($data, $intent), $data, $source);
            }
            if (isset($data['project']) && $intent === 'warehouse_status' && isset($data['data'])) {
                return $this->validator->validate($this->inventory($data), $data, $source);
            }
            if (isset($data['project']) && $intent === 'billing_status') {
                return $this->validator->validate($this->billing($data), $data, $source);
            }
            $warehouse = preg_match('/\b(?:warehouse|inventory|stock|readiness)\b/i', $request) === 1;
            if (isset($data['project']) && $warehouse) {
                return $this->validator->validate($this->project($data, $request), $data, $source);
            }
            if (! $explicit && isset($data['project']) && (preg_match('/\bstatus\b/i', $request) || ! preg_match('/\bprogress\b/i', $request))) {
                return $this->validator->validate($this->projectStatus($data), $data, $source);
            }
            if (is_array($candidate) && ! $explicit) {
                try {
                    return $this->validator->validate($candidate, $data, $source);
                } catch (Throwable $exception) {
                    Log::notice('Visual report candidate rejected', $this->validator->diagnostics($exception));
                }
            }
            if (! isset($data['project']) || (! $explicit && preg_match('/\bprogress\b/i', $request) !== 1)) {
                return null;
            }
            $spec = $this->project($data, $request);

            return $this->validator->validate($spec, $data, $source);
        } catch (Throwable $exception) {
            Log::warning('Visual report generation failed', $this->validator->diagnostics($exception));

            return null;
        }
    }

    private function projectLabel(array $data): string
    {
        $name = (string) data_get($data, 'project.project_name', 'Project');
        if (preg_match('/^\(([^)]+)\)/u', $name, $match)) {
            return Str::limit($match[1], 80, '');
        }

        return Str::limit($name, 80, '');
    }

    private function projectStatus(array $data): array
    {
        $readiness = array_map(fn (array $row): array => ['unit' => $row['unit'], 'percentage' => $row['percent']], data_get($data, 'warehouse_readiness.by_unit', []) ?? []);
        $pipeline = [];
        foreach ($data['operational_pipeline'] ?? [] as $key => $stage) {
            $pipeline[] = ['key' => $key, 'label' => $stage['label'] ?? Str::headline($key), 'value' => $stage['completed'] ?? null,
                ...Arr::only($stage, ['total', 'unit']),
                ...(array_key_exists('percent', $stage) ? ['percentage' => $stage['percent']] : []),
                ...(isset($stage['context']) ? ['note' => $stage['context']] : [])];
        }

        return ['type' => 'project_status', 'title' => $this->projectLabel($data).' Project Status',
            'project' => ['name' => data_get($data, 'project.project_name'), 'status' => data_get($data, 'project.project_status')],
            'warehouse_readiness' => $readiness, 'pipeline' => $pipeline, 'overall_progress' => $data['overall_progress'] ?? null];
    }

    private function item(array $data, string $path, string $label, ?string $unit = null): array
    {
        return ['label' => $label, 'value' => data_get($data, $path), 'source_path' => $path, ...($unit !== null ? ['unit' => $unit] : [])];
    }

    private function project(array $data, string $request): array
    {
        $blocks = [];
        $kpis = [];
        foreach (['counts.total_deliveries_count' => ['DR recorded', 'DR receipts'], 'counts.total_package_allocations_count' => ['Package allocations', 'package allocations'],
            'delivery_progress_percent' => ['Delivery progress', '%'], 'counts.billing_groups_count' => ['Billing groups', 'DR/group records']] as $path => [$label, $unit]) {
            if (Arr::has($data, $path)) {
                $item = $this->item($data, $path, $label, $unit);
                if ($path === 'counts.billing_groups_count' && $item['value'] === 0) {
                    $item['note'] = 'No recorded billing groups; billing progress is unknown.';
                }
                $kpis[] = $item;
            }
        }
        $readiness = [];
        foreach (data_get($data, 'warehouse_readiness.by_unit', []) ?? [] as $index => $row) {
            $readiness[] = $this->item($data, 'warehouse_readiness.by_unit.'.$index.'.percent', $row['unit'].' readiness', '%');
        }
        $stages = [];
        foreach ($data['operational_pipeline'] ?? [] as $key => $stage) {
            $base = 'operational_pipeline.'.$key;
            $item = $this->item($data, $base.'.completed', $stage['label'] ?? Str::headline($key), $stage['unit'] ?? '');
            foreach (['total', 'percent'] as $field) {
                if (array_key_exists($field, $stage)) {
                    $item[$field] = $stage[$field];
                    $item[$field.'_source_path'] = $base.'.'.$field;
                }
            }
            $item['note'] = $stage['context'] ?? '';
            $stages[] = $item;
        }
        $table = $this->detailTable($data, [...$kpis, ...$readiness]);
        if (preg_match('/\btable\b/i', $request)) {
            $blocks[] = $table;
        } elseif (preg_match('/\b(?:chart|graph)\b/i', $request)) {
            if ($readiness !== []) {
                $blocks[] = ['type' => 'bar_chart', 'title' => 'Warehouse readiness', 'unit' => '%', 'series' => [['name' => 'Readiness', 'data' => array_map(fn (array $item): array => Arr::only($item, ['label', 'value', 'source_path']), $readiness)]]];
            }
            if (isset($data['delivery_progress_percent'])) {
                $blocks[] = ['type' => 'progress', 'title' => 'Delivery progress', ...$this->item($data, 'delivery_progress_percent', 'Delivered and accepted allocations', '%')];
            }
            if ($blocks === []) {
                $blocks[] = $table;
            }
        } elseif (preg_match('/\b(?:warehouse|inventory|stock|readiness)\b/i', $request)) {
            $blocks[] = $readiness !== []
                ? ['type' => 'progress_group', 'title' => 'Warehouse readiness', 'items' => $readiness]
                : ['type' => 'text_summary', 'text' => 'Warehouse readiness is unavailable in the retrieved data.'];
        } else {
            if ($kpis !== []) {
                $blocks[] = ['type' => 'kpi_grid', 'items' => $kpis];
            }
            if ($readiness !== []) {
                $blocks[] = ['type' => 'progress_group', 'title' => 'Warehouse readiness', 'items' => $readiness];
            }
            if ($stages !== []) {
                $blocks[] = ['type' => 'pipeline', 'title' => 'Operational pipeline', 'stages' => $stages];
            }
            $blocks[] = $table;
        }

        return ['type' => $this->intent($request) === 'warehouse_status' ? 'warehouse_status' : 'visual_report', 'title' => $this->projectLabel($data).' Operational Report',
            'subtitle' => (string) data_get($data, 'project.project_status', 'Project progress'), 'blocks' => $blocks];
    }

    private function detailTable(array $data, array $metrics): array
    {
        $rows = [];
        if (Arr::has($data, 'project.project_status')) {
            $rows[] = [['value' => 'Project status'], ['value' => data_get($data, 'project.project_status'), 'source_path' => 'project.project_status']];
        }
        foreach ($metrics as $item) {
            $rows[] = [['value' => $item['label']], Arr::only($item, ['value', 'unit', 'source_path'])];
        }

        return ['type' => 'table', 'title' => 'Project details', 'columns' => ['Metric', 'Value'], 'rows' => $rows];
    }

    private function portfolio(array $data): array
    {
        $rows = [];
        foreach (array_slice($data['projects'], 0, 100) as $index => $project) {
            $base = 'projects.'.$index.'.';
            $rows[] = [
                ['value' => data_get($project, 'project.project_name'), 'source_path' => $base.'project.project_name'],
                ['value' => data_get($project, 'project.project_status'), 'source_path' => $base.'project.project_status'],
                ['value' => $project['delivery_progress_percent'], 'unit' => '%', 'source_path' => $base.'delivery_progress_percent'],
                ['value' => $project['billing_progress_percent'], 'unit' => '%', 'source_path' => $base.'billing_progress_percent'],
                ['value' => $project['overall_progress'], 'unit' => '%', 'source_path' => $base.'overall_progress'],
                ['value' => $project['is_active'], 'source_path' => $base.'is_active'],
                ['value' => $project['project']['project_id'], 'source_path' => $base.'project.project_id'],
            ];
        }
        $blocks = $rows === [] ? [['type' => 'text_summary', 'text' => 'No projects matched the requested filters.']]
            : [['type' => 'table', 'columns' => ['Project', 'Status', 'Delivery completion', 'Billing completion', 'Overall progress', 'Active', 'Project ID'], 'rows' => $rows]];
        if (count($data['projects']) > 100) {
            $blocks[] = ['type' => 'text_summary', 'text' => 'Showing the first 100 projects. Narrow the Tracker filters to review more projects.'];
        }

        return ['type' => 'project_portfolio', 'title' => 'Project Portfolio', 'blocks' => $blocks];
    }

    private function delivery(array $data, string $intent): array
    {
        $blocks = [];
        $coverage = $data['delivery_coverage'] ?? null;
        if (is_array($coverage)) {
            $items = [];
            foreach (['schools_delivered' => 'Schools delivered', 'schools_partial' => 'Schools partially delivered', 'schools_pending' => 'Schools pending',
                'regions_reached' => 'Regions reached', 'divisions_reached' => 'Divisions reached', 'municipalities_reached' => 'Municipalities reached'] as $field => $label) {
                $items[] = $this->item($data, 'delivery_coverage.'.$field, $label);
            }
            $blocks[] = ['type' => 'kpi_grid', 'title' => 'Delivery coverage in retrieved records', 'items' => $items];
        }
        if (is_array($data['data'] ?? null) && $data['data'] !== []) {
            $rows = [];
            foreach (array_slice($data['data'], 0, 100) as $index => $row) {
                $cells = [];
                foreach (['region', 'division', 'municipality', 'school_name', 'delivery_status', 'delivered_date'] as $field) {
                    $cells[] = array_key_exists($field, $row) ? ['value' => $row[$field], 'source_path' => 'data.'.$index.'.'.$field] : ['value' => 'Unavailable'];
                }
                $rows[] = $cells;
            }
            $blocks[] = ['type' => 'table', 'title' => 'Delivery locations and recorded row status',
                'columns' => ['Region', 'Division', 'Municipality', 'School / Location', 'Row status', 'Delivered date'], 'rows' => $rows];
            $blocks[] = ['type' => 'text_summary', 'text' => 'Location completion uses expected package allocations when recorded, otherwise delivery row status. Row status and package completion may differ.'];
        } else {
            $blocks[] = ['type' => 'text_summary', 'text' => 'Geographic delivery coverage is unavailable in this Tracker result. Package allocation percentages are not delivered-location counts.'];
        }
        if (data_get($data, 'meta.pagination.has_more', false)
            || data_get($data, 'meta.pagination.current_page', 1) < data_get($data, 'meta.pagination.last_page', 1)) {
            $blocks[] = ['type' => 'text_summary', 'text' => 'Partial coverage: this is one page of delivery records. Counts describe only these records, not the entire project. Request a region, division or municipality to narrow the view.'];
        }

        return ['type' => $intent, 'title' => $this->projectLabel($data).($intent === 'logistics_status' ? ' Logistics Status' : ' Delivery Status'), 'blocks' => $blocks];
    }

    private function inventory(array $data): array
    {
        $rows = [];
        foreach (array_slice($data['data'], 0, 100) as $index => $row) {
            $cells = [];
            foreach (['item_name', 'unit', 'qty', 'warehouse_name', 'inventory_status'] as $field) {
                $cells[] = array_key_exists($field, $row) ? ['value' => $row[$field], 'source_path' => 'data.'.$index.'.'.$field] : ['value' => 'Unavailable'];
            }
            $rows[] = $cells;
        }
        $blocks = $rows === [] ? [['type' => 'text_summary', 'text' => 'No inventory records were returned for this project.']]
            : [['type' => 'table', 'title' => 'Recorded inventory', 'columns' => ['Item', 'Unit', 'Recorded stock', 'Warehouse', 'Approval'], 'rows' => $rows]];
        if (data_get($data, 'meta.pagination.has_more', false)) {
            $blocks[] = ['type' => 'text_summary', 'text' => 'This inventory page is incomplete. Quantities retain their individual item units; no mixed-unit total is calculated.'];
        }

        return ['type' => 'warehouse_status', 'title' => $this->projectLabel($data).' Warehouse Status', 'blocks' => $blocks];
    }

    private function billing(array $data): array
    {
        $items = [];
        foreach (['counts.for_billing_groups_count' => 'For billing', 'counts.billed_groups_count' => 'Billed', 'counts.paid_groups_count' => 'Paid'] as $path => $label) {
            if (Arr::has($data, $path)) {
                $items[] = $this->item($data, $path, $label, 'DR/group records');
            }
        }
        $blocks = $items === [] ? [['type' => 'text_summary', 'text' => 'Billing stage counts are unavailable in this Tracker result.']]
            : [['type' => 'kpi_grid', 'title' => 'Recorded billing groups', 'items' => $items]];
        if (Arr::has($data, 'billing_progress_percent')) {
            $blocks[] = ['type' => 'progress', 'title' => 'Billing completion', ...$this->item($data, 'billing_progress_percent', 'Billed and paid recorded groups', '%')];
        }
        $blocks[] = ['type' => 'text_summary', 'text' => 'Missing billing groups do not establish zero billing completion. Unknown percentages remain unavailable.'];

        return ['type' => 'billing_status', 'title' => $this->projectLabel($data).' Billing Status', 'blocks' => $blocks];
    }

    private function comparison(array $projects, string $request): array
    {
        $blocks = [];
        foreach (['delivery_progress_percent' => ['Delivery progress', '%'], 'counts.total_deliveries_count' => ['DR recorded', 'DR receipts'], 'counts.total_package_allocations_count' => ['Package allocations', 'package allocations']] as $path => [$title, $unit]) {
            $points = [];
            foreach ($projects as $index => $project) {
                if (Arr::has($project, $path)) {
                    $points[] = ['label' => $this->projectLabel($project), 'value' => data_get($project, $path), 'source_path' => 'projects.'.$index.'.'.$path];
                }
            }
            if ($points === []) {
                continue;
            }
            if (preg_match('/\btable\b/i', $request)) {
                $blocks[] = ['type' => 'table', 'title' => $title, 'columns' => ['Project', 'Value'], 'rows' => array_map(fn (array $point): array => [['value' => $point['label']], ['value' => $point['value'], 'unit' => $unit, 'source_path' => $point['source_path']]], $points)];
            } else {
                $blocks[] = ['type' => 'bar_chart', 'title' => $title, 'unit' => $unit, 'series' => [['name' => $title, 'data' => $points]]];
            }
        }

        return ['type' => 'project_comparison', 'title' => 'Project comparison', 'blocks' => $blocks];
    }
}
