<?php

namespace Tests\Feature;

use App\Ai\Agents\JcAgent;
use App\Models\User;
use App\Services\ArtifactValidationService;
use App\Services\ConversationResponsePresenter;
use App\Services\VisualReportService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Responses\Data\ToolCall;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class VisualReportTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[DataProvider('reportRequests')]
    public function test_report_layout_respects_requested_style_and_preserves_source_values(string $request, string $firstType): void
    {
        $data = $this->project();

        $artifact = app(VisualReportService::class)->operations($data, $request, $this->source());

        $this->assertNotNull($artifact);
        $this->assertSame($firstType, $artifact['blocks'][0]['type']);
        $this->assertSame('Another Project Operational Report', $artifact['title']);
        $this->assertSame($this->source(), $artifact['source']);
        $this->assertStringContainsString(in_array($firstType, ['bar_chart', 'progress_group'], true) ? '59' : '251557', json_encode($artifact));
    }

    public static function reportRequests(): array
    {
        return [['What is the project progress?', 'kpi_grid'], ['Show progress as a chart.', 'bar_chart'],
            ['Show a table of project status.', 'table'], ['Visualize project warehouse readiness.', 'progress_group']];
    }

    public function test_unavailable_pipeline_values_are_not_converted_to_zero(): void
    {
        $artifact = app(VisualReportService::class)->operations($this->project(), 'What is the progress?', $this->source());

        $this->assertSame(null, $artifact['blocks'][2]['stages'][1]['value']);
        $this->assertSame(null, $artifact['blocks'][2]['stages'][1]['percent']);
        $this->assertSame('mixed item units', $artifact['blocks'][2]['stages'][1]['unit']);
    }

    public function test_comparison_uses_separate_charts_for_incompatible_units(): void
    {
        $data = ['projects' => [$this->project(), [...$this->project(), 'project' => ['project_name' => 'Second Project']]]];

        $artifact = app(VisualReportService::class)->operations($data, 'Compare these projects.', $this->source());

        $this->assertNotNull($artifact);
        $this->assertSame(['%', 'DR receipts', 'package allocations'], array_column($artifact['blocks'], 'unit'));
        $this->assertCount(2, $artifact['blocks'][0]['series'][0]['data']);
    }

    public function test_validated_agent_layout_is_used_and_invalid_layout_falls_back_without_losing_data(): void
    {
        $candidate = ['type' => 'visual_report', 'title' => 'Agent-selected summary', 'blocks' => [['type' => 'key_value', 'items' => [
            ['label' => 'Status', 'value' => 'Implementation', 'source_path' => 'project.project_status'],
        ]]]];
        $reports = app(VisualReportService::class);

        $chosen = $reports->operations($this->project(), 'What is the progress?', $this->source(), $candidate);
        $this->assertSame('key_value', $chosen['blocks'][0]['type']);
        $this->assertSame('Agent-selected summary', $chosen['title']);

        $candidate['blocks'][0]['items'][0]['value'] = 'Invented status';
        $fallback = $reports->operations($this->project(), 'What is the progress?', $this->source(), $candidate);
        $this->assertSame('kpi_grid', $fallback['blocks'][0]['type']);
        $this->assertSame(28649.0, $fallback['blocks'][0]['items'][0]['value']);
    }

    public function test_report_preserves_the_answer_when_an_older_optional_visual_fails(): void
    {
        $artifact = app(VisualReportService::class)->operations($this->project(), 'What is the progress?', $this->source());

        $response = app(ConversationResponsePresenter::class)->present(['success' => true, 'response' => 'Verified project answer.',
            'artifact' => $artifact, 'visual_error' => true]);

        $this->assertSame('Verified project answer.', $response['response']);
        $this->assertSame('Verified project answer.', $response['display']['message']);
        $this->assertSame($artifact, $response['display']['report']);
    }

    public function test_dated_and_non_chart_blocks_are_validated_against_shared_evidence(): void
    {
        $evidence = ['date' => '2026-10-03', 'completion_percent' => 59, 'status' => 'Review'];
        $candidate = ['type' => 'visual_report', 'title' => 'Shared report', 'blocks' => [
            ['type' => 'timeline', 'events' => [['label' => 'Review recorded', 'date' => '2026-10-03', 'value' => null, 'source_path' => 'date']]],
            ['type' => 'progress', 'label' => 'Completion', 'value' => 59, 'unit' => '%', 'source_path' => 'completion_percent'],
            ['type' => 'key_value', 'items' => [['label' => 'Status', 'value' => 'Review', 'source_path' => 'status']]],
            ['type' => 'text_summary', 'text' => 'Review is in progress.'],
        ]];

        $artifact = app(ArtifactValidationService::class)->validate($candidate, $evidence, $this->source());

        $this->assertSame(['timeline', 'progress', 'key_value', 'text_summary'], array_column($artifact['blocks'], 'type'));
        $this->assertSame('2026-10-03', $artifact['blocks'][0]['events'][0]['date']);
    }

    #[DataProvider('unsafeArtifacts')]
    public function test_unsafe_or_unverifiable_artifacts_are_rejected(array $spec, array $evidence = []): void
    {
        $this->expectException(ValidationException::class);

        app(ArtifactValidationService::class)->validate($spec, $evidence, $this->source(), [1.0, 2.0, 59.0]);
    }

    public static function unsafeArtifacts(): array
    {
        $base = ['type' => 'visual_report', 'title' => 'Safe report', 'blocks' => [['type' => 'text_summary', 'text' => 'A safe summary.']]];
        $chart = ['type' => 'bar_chart', 'unit' => '%', 'series' => [['name' => 'Readiness', 'data' => [['label' => 'Warehouse', 'value' => 59]]]]];

        return [
            'HTML' => [[...$base, 'title' => '<img src=x onerror=alert(1)>']],
            'JavaScript' => [[...$base, 'blocks' => [['type' => 'text_summary', 'text' => 'eval("run")']]]],
            'URL' => [[...$base, 'source' => ['system' => 'https://example.com']]],
            'unknown block' => [[...$base, 'blocks' => [['type' => 'html', 'html' => '<script>']]]],
            'unknown key' => [[...$base, 'onclick' => 'run()']],
            'too many blocks' => [[...$base, 'blocks' => array_fill(0, 11, $base['blocks'][0])]],
            'too many rows' => [[...$base, 'blocks' => [['type' => 'table', 'columns' => ['Value'], 'rows' => array_fill(0, 101, [['value' => 1]])]]]],
            'too many rows across blocks' => [[...$base, 'blocks' => array_fill(0, 2, ['type' => 'table', 'columns' => ['Value'], 'rows' => array_fill(0, 51, [['value' => 1]])])]],
            'too many points' => [[...$base, 'blocks' => [[...$chart, 'series' => [['name' => 'Values', 'data' => array_fill(0, 101, ['label' => 'a', 'value' => 1])]]]]]],
            'mismatched series unit' => [[...$base, 'blocks' => [[...$chart, 'series' => [['name' => 'Count', 'unit' => 'packages', 'data' => [['label' => 'a', 'value' => 1]]]]]]]],
            'false source value' => [[...$base, 'blocks' => [['type' => 'progress', 'label' => 'Readiness', 'value' => 59, 'source_path' => 'readiness']]], ['readiness' => 42]],
            'false timeline date' => [[...$base, 'blocks' => [['type' => 'timeline', 'events' => [['label' => 'Recorded', 'date' => '2026-10-03', 'value' => null, 'source_path' => 'date']]]]], ['date' => '2026-10-02']],
            'credential source path' => [[...$base, 'blocks' => [['type' => 'key_value', 'items' => [['label' => 'Secret', 'value' => 'hidden', 'source_path' => 'api_key']]]]], ['api_key' => 'hidden']],
            'mixed source units' => [[...$base, 'blocks' => [[...$chart, 'series' => [['name' => 'Values', 'data' => [['label' => 'DRs', 'value' => 1, 'source_path' => 'counts.total_deliveries_count']]]]]]], ['counts' => ['total_deliveries_count' => 1]]],
            'unproven number' => [[...$base, 'blocks' => [['type' => 'kpi_grid', 'items' => [['label' => 'Invented', 'value' => 999]]]]]],
            'progress out of range' => [[...$base, 'blocks' => [['type' => 'progress', 'label' => 'Readiness', 'value' => 101]]]],
            'unordered time series' => [[...$base, 'blocks' => [[...$chart, 'type' => 'line_chart', 'series' => [['name' => 'Count', 'data' => [['label' => '2026-02', 'value' => 1], ['label' => '2026-01', 'value' => 2]]]]]]]],
            'negative pie' => [[...$base, 'blocks' => [[...$chart, 'type' => 'pie_chart', 'series' => [['name' => 'Parts', 'data' => [['label' => 'a', 'value' => -1]]]]]]]],
        ];
    }

    public function test_source_metadata_is_owned_by_the_application(): void
    {
        $spec = ['type' => 'visual_report', 'title' => 'A report', 'source' => ['system' => 'Invented source', 'retrieved_at' => '2000-01-01'], 'blocks' => [['type' => 'text_summary', 'text' => 'A summary.']]];

        $artifact = app(ArtifactValidationService::class)->validate($spec, [], $this->source());

        $this->assertSame($this->source(), $artifact['source']);
    }

    public function test_shared_report_tool_preserves_the_normal_command_response(): void
    {
        $this->actingAs(User::factory()->create());
        Http::preventStrayRequests();
        $artifact = ['type' => 'visual_report', 'title' => 'Monthly expenses', 'blocks' => [['type' => 'bar_chart', 'title' => 'Expenses', 'unit' => 'PHP', 'series' => [['name' => 'Expenses', 'data' => [['label' => 'January', 'value' => 10], ['label' => 'February', 'value' => 20]]]]]]];
        JcAgent::fake([new ToolCall('report', 'RenderVisualReport', ['artifact_json' => json_encode($artifact)]), 'Expenses rose from 10 to 20.'])->preventStrayPrompts();

        $response = $this->postJson('/jarvis/command', ['command' => 'Show an expense report: January: 10, February: 20.']);

        $response->assertOk()->assertJsonPath('response', 'Expenses rose from 10 to 20.')
            ->assertJsonPath('artifact.type', 'visual_report')->assertJsonPath('display.type', 'conversation')
            ->assertJsonPath('artifact.source.system', 'User-supplied data')->assertJsonMissingPath('display.details');
        Http::assertNothingSent();
    }

    public function test_report_failure_does_not_fail_or_replace_the_text_answer(): void
    {
        $this->actingAs(User::factory()->create());
        Http::preventStrayRequests();
        JcAgent::fake([new ToolCall('report', 'RenderVisualReport', ['artifact_json' => '{"type":"html","html":"<script>"}']), 'The verified answer remains available.'])->preventStrayPrompts();

        $response = $this->postJson('/jarvis/command', ['command' => 'Show the supplied report: Count: 10.']);

        $response->assertOk()->assertJsonPath('response', 'The verified answer remains available.')->assertJsonPath('artifact', null);
        Http::assertNothingSent();
    }

    public function test_ordinary_answers_do_not_get_a_report_or_identical_details(): void
    {
        $result = app(ConversationResponsePresenter::class)->present(['success' => true, 'type' => 'ai_response', 'response' => 'I am doing well.'], 'detailed');

        $this->assertArrayNotHasKey('artifact', $result);
        $this->assertArrayNotHasKey('details', $result['display']);
    }

    public function test_optional_failure_diagnostics_are_not_added_to_a_finance_answer(): void
    {
        $this->actingAs(User::factory()->create());
        Http::preventStrayRequests();
        JcAgent::fake([new ToolCall('report', 'RenderVisualReport', ['artifact_json' => '{"type":"html"}']), 'Expenses rose from 10 to 20. The attached visual report did not validate this time.'])->preventStrayPrompts();

        $response = $this->postJson('/jarvis/command', ['command' => 'Show an expense report: January: 10, February: 20.']);

        $response->assertOk()->assertJsonPath('response', 'Expenses rose from 10 to 20.')->assertJsonPath('artifact', null);
        Http::assertNothingSent();
    }

    public function test_explicit_questions_about_artifact_failures_keep_the_explanation(): void
    {
        $answer = 'The visual report did not validate this time.';

        $this->assertSame($answer, app(ConversationResponsePresenter::class)->omitArtifactFailure($answer, 'Why did the visual report fail?'));
    }

    private function source(): array
    {
        return ['system' => 'MMC Tracker', 'retrieved_at' => '2026-10-03T10:00:00+08:00'];
    }

    private function project(): array
    {
        return ['project' => ['project_id' => 73, 'project_name' => 'Another Project', 'project_status' => 'Implementation'],
            'counts' => ['total_deliveries_count' => 28649, 'total_package_allocations_count' => 251557, 'billing_groups_count' => 0],
            'delivery_progress_percent' => 0, 'overall_progress' => null,
            'warehouse_readiness' => ['by_unit' => [['unit' => 'pc', 'percent' => 59], ['unit' => 'set', 'percent' => 42]]],
            'operational_pipeline' => ['dr' => ['label' => 'DR', 'completed' => 28649, 'total' => 28649, 'percent' => 100, 'unit' => 'DR receipts'],
                'stock_out' => ['label' => 'Stock Out', 'completed' => null, 'total' => null, 'percent' => null, 'unit' => 'mixed item units']]];
    }
}
