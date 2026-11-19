<?php

namespace Tests\Feature;

use App\Ai\Tools\RenderVisualReport;
use App\Services\ArtifactPresentationPlanner;
use App\Services\ArtifactValidationService;
use App\Services\ConversationResponsePresenter;
use App\Services\VisualReportService;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Tools\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProjectStatusArtifactTest extends TestCase
{
    public function test_historical_report_requires_evidence_and_normalizes_equivalent_allocation_units(): void
    {
        $spec = ['type' => 'visual_report', 'title' => 'Project Status', 'blocks' => [['type' => 'kpi_grid', 'items' => [
            ['label' => 'DR receipts recorded', 'value' => 28649, 'unit' => 'DR receipts', 'source_path' => 'counts.total_deliveries_count'],
            ['label' => 'Package allocations', 'value' => 251557, 'unit' => 'allocations', 'source_path' => 'counts.total_packages_count'],
        ]]]];
        $validator = app(ArtifactValidationService::class);
        try {
            $validator->validate($spec, [], $this->source());
            $this->fail('A remembered narrative must not be accepted as evidence.');
        } catch (ValidationException $exception) {
            $this->assertSame(['Report numbers require supplied or retrieved evidence.'], $exception->errors()['value']);
        }
        $result = $validator->validate($spec, $this->evidence(), $this->source());

        $this->assertSame('package allocations', $result['blocks'][0]['items'][1]['unit']);
        $this->assertSame(251557.0, $result['blocks'][0]['items'][1]['value']);
    }

    public function test_project_status_normalizes_numeric_strings_and_preserves_unavailable_metrics(): void
    {
        $spec = $this->spec();
        $spec['pipeline'][0]['value'] = '28649';
        $spec['pipeline'][2]['percentage'] = '0';
        $spec['warehouse_readiness'][0]['percentage'] = '59.36';
        $spec['source'] = ['system' => 'Invented source'];
        $result = app(ArtifactValidationService::class)->validate($spec, $this->evidence(), $this->source());

        $this->assertSame(28649.0, $result['pipeline'][0]['value']);
        $this->assertSame(0.0, $result['pipeline'][2]['percentage']);
        $this->assertSame(59.36, $result['warehouse_readiness'][0]['percentage']);
        $this->assertNull($result['pipeline'][1]['value']);
        $this->assertNull($result['overall_progress']);
        $this->assertSame($this->source(), $result['source']);
    }

    public function test_status_reports_support_long_project_names_without_a_special_prefix(): void
    {
        $evidence = $this->evidence();
        $evidence['project']['project_name'] = str_repeat('Long project name ', 10);
        $result = app(VisualReportService::class)->operations($evidence, 'What is the project status?', $this->source());

        $this->assertNotNull($result);
        $this->assertSame($evidence['project']['project_name'], $result['project']['name']);
    }

    #[DataProvider('invalidChanges')]
    public function test_project_status_rejects_unsafe_or_fabricated_metrics(string $path, mixed $value): void
    {
        $spec = $this->spec();
        data_set($spec, $path, $value);
        $this->expectException(ValidationException::class);
        app(ArtifactValidationService::class)->validate($spec, $this->evidence(), $this->source());
    }

    public static function invalidChanges(): array
    {
        return [['title', '<script>alert(1)</script>'], ['project.name', 'Other project'], ['project.status', 'Completed'],
            ['warehouse_readiness.0.percentage', 101], ['warehouse_readiness.0.percentage', 59], ['warehouse_readiness.0.unit', 'tons'],
            ['pipeline.0.value', 99], ['pipeline.0.key', 'invented'], ['pipeline.0.unit', 'package allocations'],
            ['pipeline.2.total', 1], ['overall_progress', 0], ['pipeline.0.onclick', 'run()']];
    }

    public function test_report_details_do_not_repeat_a_long_natural_language_answer(): void
    {
        $answer = 'The project is at contract signing. '.str_repeat('The recorded operational values are available in the report. ', 20);
        $result = app(ConversationResponsePresenter::class)->present(['success' => true, 'response' => $answer, 'artifact' => $this->spec()], 'detailed');

        $this->assertSame($answer, $result['response']);
        $this->assertSame('project_status', $result['display']['report']['type']);
        $this->assertArrayNotHasKey('details', $result['display']);
    }

    public function test_shared_tool_logs_safe_errors_and_keeps_failure_diagnostics_internal(): void
    {
        $planner = new ArtifactPresentationPlanner;
        Log::spy();
        $tool = new RenderVisualReport(app(ArtifactValidationService::class), $planner, 'Show the project status.');
        $result = json_decode($tool->handle(new Request(['artifact_json' => json_encode([...$this->spec(), 'title' => 'Bearer private-token'])])), true);

        $this->assertFalse($result['success']);
        $this->assertTrue($planner->reportFailed());
        $this->assertNull($planner->visualReport());
        Log::shouldHaveReceived('notice')->once()->with('Artifact validation failed', \Mockery::on(fn (array $context): bool => isset($context['validation_errors']['artifact.title']) && ! str_contains(json_encode($context), 'private-token')));
    }

    public function test_shared_project_tool_accepts_the_same_verified_data_without_any_read(): void
    {
        $planner = new ArtifactPresentationPlanner;
        $planner->rememberRetrievedData($this->evidence(), 'MMC Tracker', $this->source()['retrieved_at']);
        $tool = new RenderVisualReport(app(ArtifactValidationService::class), $planner, 'What is the project status?');
        $result = json_decode($tool->handle(new Request(['artifact_json' => json_encode($this->spec())])), true);

        $this->assertTrue($result['success']);
        $this->assertSame('project_status', $planner->visualReport()['type']);
        $this->assertSame($this->source(), $planner->visualReport()['source']);
    }

    private function source(): array
    {
        return ['system' => 'MMC Tracker', 'retrieved_at' => '2026-10-03T06:18:09Z'];
    }

    #[DataProvider('warehouseRequests')]
    public function test_warehouse_intent_overrides_a_project_status_candidate(string $request): void
    {
        $artifact = app(VisualReportService::class)->operations($this->evidence(), $request, $this->source(), $this->spec());

        $this->assertSame('warehouse_status', $artifact['type']);
        $this->assertSame('progress_group', $artifact['blocks'][0]['type']);
        $this->assertSame(59.36, $artifact['blocks'][0]['items'][0]['value']);
        $this->assertSame($this->source(), $artifact['source']);
    }

    public static function warehouseRequests(): array
    {
        return [['Show me SME 2 warehouse readiness.'], ['How ready is the SME 2 warehouse?'],
            ['What is the warehouse status of SME 2?'], ['Show SME 2 inventory']];
    }

    public function test_shared_tool_adapts_project_status_to_warehouse_intent_without_another_read(): void
    {
        $planner = new ArtifactPresentationPlanner;
        $planner->rememberRetrievedData($this->evidence(), 'MMC Tracker', $this->source()['retrieved_at']);
        $tool = new RenderVisualReport(app(ArtifactValidationService::class), $planner, 'Show me SME 2 warehouse readiness.');

        $result = json_decode($tool->handle(new Request(['artifact_json' => json_encode($this->spec())])), true);

        $this->assertTrue($result['success']);
        $this->assertSame('progress_group', $planner->visualReport()['blocks'][0]['type']);
        $this->assertSame(59.36, $planner->visualReport()['blocks'][0]['items'][0]['value']);
    }

    public function test_project_status_preserves_separate_warehouse_units_and_tracker_values(): void
    {
        $data = $this->evidence();
        $data['warehouse_readiness']['by_unit'] = [['unit' => 'pc', 'percent' => 59.36], ['unit' => 'set', 'percent' => 42], ['unit' => 'pcs', 'percent' => 51.59]];

        $artifact = app(VisualReportService::class)->operations($data, 'What is the status of SME 2?', $this->source());

        $this->assertSame('project_status', $artifact['type']);
        $this->assertSame([59.36, 42.0, 51.59], array_column($artifact['warehouse_readiness'], 'percentage'));
        $this->assertSame(['pc', 'set', 'pcs'], array_column($artifact['warehouse_readiness'], 'unit'));
        $this->assertNull($artifact['overall_progress']);
    }

    public function test_missing_warehouse_readiness_is_reported_without_substituting_operational_metrics(): void
    {
        $data = $this->evidence();
        $data['warehouse_readiness'] = null;

        $artifact = app(VisualReportService::class)->operations($data, 'Show SME 2 warehouse readiness.', $this->source());

        $this->assertSame('text_summary', $artifact['blocks'][0]['type']);
        $this->assertSame('Warehouse readiness is unavailable in the retrieved data.', $artifact['blocks'][0]['text']);
    }

    private function evidence(): array
    {
        return ['project' => ['project_name' => '(SME2) Equipment', 'project_status' => 'For Contract Signing'],
            'counts' => ['total_deliveries_count' => 28649, 'total_packages_count' => 251557],
            'warehouse_readiness' => ['by_unit' => [['unit' => 'pc', 'percent' => 59.36]]], 'overall_progress' => null,
            'operational_pipeline' => ['dr' => ['completed' => 28649, 'unit' => 'DR receipts'], 'stock_out' => ['completed' => null, 'unit' => 'mixed item units'],
                'delivered' => ['completed' => 0, 'total' => 251557, 'percent' => 0, 'unit' => 'DR package allocations']]];
    }

    private function spec(): array
    {
        return ['type' => 'project_status', 'title' => 'SME 2 Project Status', 'project' => ['name' => 'SME 2', 'status' => 'For Contract Signing'],
            'warehouse_readiness' => [['unit' => 'pc', 'percentage' => 59.36]],
            'pipeline' => [['key' => 'dr', 'label' => 'DR', 'value' => 28649], ['key' => 'stock_out', 'label' => 'Stock Out', 'value' => null],
                ['key' => 'delivered', 'label' => 'Delivered', 'value' => 0, 'total' => 251557, 'percentage' => 0]],
            'overall_progress' => null, 'source' => ['system' => 'MMC Tracker']];
    }
}
