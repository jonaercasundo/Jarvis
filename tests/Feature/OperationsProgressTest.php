<?php

namespace Tests\Feature;

use App\Ai\Agents\JcAgent;
use App\Models\ApiConnection;
use App\Models\JarvisSetting;
use App\Models\JarvisWorkflow;
use App\Models\User;
use App\Services\WorkflowHttpService;
use App\Services\WorkflowOperationsService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\Data\ToolCall;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OperationsProgressTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[DataProvider('progressQuestions')]
    public function test_named_progress_questions_resolve_real_ids_and_use_one_compact_report(string $question): void
    {
        $workflow = $this->workflow();
        $this->fakeDns();
        Http::preventStrayRequests();
        Http::fake([
            'https://tracker.metro-mobilia.com/api/jarvis/projects?*' => fn ($request) => Http::response([
                'success' => true,
                'data' => $request['search'] === 'SME 2' ? [] : [['project_id' => 502698, 'project_name' => '(SME2) Equipment', 'ref_no' => 'REF-2']],
                'meta' => ['pagination' => ['has_more' => false]],
            ]),
            'https://tracker.metro-mobilia.com/api/jarvis/projects/delivery-progress?*' => Http::response($this->progress()),
        ]);
        AnonymousAgent::fake([
            '{"resource":"operation-masterlist","project_id":null,"project_name":"SME 2"}',
            '{"summary":"Delivery progress is 0%; overall progress is unavailable."}',
        ])->preventStrayPrompts();

        $result = app(WorkflowOperationsService::class)->ask($workflow->owner_key, $question);

        $this->assertTrue($result['success'], $result['error'] ?? '');
        $this->assertSame(502698, $result['data']['project']['project_id']);
        $this->assertSame('REF-2', $result['data']['project']['ref_no']);
        $this->assertSame(0, $result['data']['counts']['warehouse_packages_count']);
        $this->assertNull($result['data']['overall_progress']);
        $this->assertNull($result['data']['billing_progress_percent']);
        $this->assertArrayNotHasKey('summary', $result['data']);
        $this->assertSame('projects/delivery-progress', $result['meta']['resource']);
        $this->assertSame(30, $result['meta']['diagnostic']['total_timeout']);
        $this->assertCount(3, $workflow->executions()->get());
        AnonymousAgent::assertPromptedTimes(2);
        AnonymousAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->contains('connected_app_result') && $prompt->contains('251557') && ! $prompt->contains('discard-duplicate-summary'));
        Http::assertSent(fn ($request): bool => parse_url($request->url(), PHP_URL_PATH) === '/api/jarvis/projects'
            && $request['search'] === 'SME2' && (int) $request['per_page'] === 10);
        Http::assertSent(fn ($request): bool => parse_url($request->url(), PHP_URL_PATH) === '/api/jarvis/projects/delivery-progress'
            && (int) $request['project_id'] === 502698 && (int) $request['active_only'] === 0);
        Http::assertSentCount(3);
    }

    public static function progressQuestions(): array
    {
        return [
            ['What is the progress of SME 2?'],
            ['Show SME 2 progress as a chart.'],
            ['Show me a table of SME 2 status.'],
            ['Visualize SME 2 warehouse readiness.'],
            ['How many SME 2 packages are in the warehouse?'],
            ['How many SME 2 deliveries are billed?'],
        ];
    }

    public function test_oversized_optional_report_keeps_the_successful_operations_answer(): void
    {
        $workflow = $this->workflow();
        $this->fakeDns();
        Http::preventStrayRequests();
        Http::fake([
            'https://tracker.metro-mobilia.com/api/jarvis/projects?*' => Http::response(['data' => [['project_id' => 502698, 'project_name' => '(SME2) Equipment']], 'meta' => ['pagination' => ['has_more' => false]]]),
            'https://tracker.metro-mobilia.com/api/jarvis/projects/delivery-progress?*' => Http::response($this->progress()),
        ]);
        AnonymousAgent::fake([
            '{"resource":"projects/delivery-progress","project_name":"SME 2"}',
            json_encode(['summary' => 'Delivery progress is 0%; billing is unknown.', 'artifact' => ['type' => 'visual_report', 'title' => 'Too large', 'blocks' => [['type' => 'text_summary', 'text' => str_repeat('x', 60000)]]]]),
        ])->preventStrayPrompts();

        $result = app(WorkflowOperationsService::class)->ask($workflow->owner_key, 'What is the progress of SME 2?');

        $this->assertTrue($result['success'], $result['error'] ?? '');
        $this->assertSame('Delivery progress is 0%; billing is unknown.', $result['summary']);
        $this->assertSame('visual_report', $result['artifact']['type']);
        Http::assertSentCount(2);
    }

    #[DataProvider('unresolvedProjects')]
    public function test_unresolved_search_never_reads_progress(array $projects, bool $hasMore, string $error): void
    {
        $workflow = $this->workflow();
        $this->fakeDns();
        Http::preventStrayRequests();
        Http::fake(['https://tracker.metro-mobilia.com/api/jarvis/projects?*' => Http::response([
            'data' => $projects, 'meta' => ['pagination' => ['has_more' => $hasMore]],
        ])]);
        AnonymousAgent::fake(['{"resource":"projects/delivery-progress","project_id":null,"project_name":"SME 2"}'])->preventStrayPrompts();

        $result = app(WorkflowOperationsService::class)->ask($workflow->owner_key, 'What is the progress of SME 2?');

        $this->assertFalse($result['success']);
        $this->assertNull($result['data']);
        $this->assertStringContainsString($error, $result['error']);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'delivery-progress'));
        AnonymousAgent::assertPromptedTimes(1);
    }

    public static function unresolvedProjects(): array
    {
        return [
            'absent' => [[], false, 'No project matched'],
            'ambiguous' => [[['project_id' => 2], ['project_id' => 3]], false, 'ambiguous'],
            'incomplete' => [[['project_id' => 2]], true, 'more pages'],
            'similar name' => [[['project_id' => 20, 'project_name' => '(SME20) Equipment']], false, 'precise name'],
        ];
    }

    public function test_masterlist_search_is_sent_with_bounded_pagination(): void
    {
        $workflow = $this->workflow();
        $this->fakeDns();
        Http::preventStrayRequests();
        Http::fake(['https://tracker.metro-mobilia.com/api/jarvis/operation-masterlist?*' => Http::response(['data' => [], 'meta' => ['pagination' => ['total' => 0]]])]);
        AnonymousAgent::fake(['{"resource":"operation-masterlist","search":"SME 2","per_page":10}', '{"summary":"No items matched."}'])->preventStrayPrompts();

        $result = app(WorkflowOperationsService::class)->ask($workflow->owner_key, 'Search the operation masterlist for SME 2, per_page 10.');

        $this->assertTrue($result['success']);
        Http::assertSent(fn ($request): bool => $request['search'] === 'SME 2' && (int) $request['per_page'] === 10 && (int) $request['page'] === 1);
    }

    public function test_oversized_response_fails_before_reasoning_without_json_preview(): void
    {
        $workflow = $this->workflow();
        $this->fakeDns();
        Http::preventStrayRequests();
        Http::fake(['https://tracker.metro-mobilia.com/api/jarvis/operation-masterlist?*' => Http::response(['data' => [['description' => str_repeat('x', 51000)]]])]);
        AnonymousAgent::fake(['{"resource":"operation-masterlist"}'])->preventStrayPrompts();

        $result = app(WorkflowOperationsService::class)->ask($workflow->owner_key, 'Show the operation masterlist.');

        $this->assertFalse($result['success']);
        $this->assertSame('response_too_large', $result['error']);
        $this->assertStringContainsString('smaller per_page', implode(' ', $result['suggestions']));
        $this->assertStringNotContainsString('preview', json_encode($result));
        $this->assertSame('failed', $workflow->executions()->firstOrFail()->status);
        AnonymousAgent::assertPromptedTimes(1);
        Http::assertSentCount(1);
    }

    public function test_invalid_pagination_is_rejected_without_tracker_reads(): void
    {
        $workflow = $this->workflow();
        Http::preventStrayRequests();
        AnonymousAgent::fake(['{"resource":"projects","per_page":101}'])->preventStrayPrompts();

        $result = app(WorkflowOperationsService::class)->ask($workflow->owner_key, 'Show projects, per_page 101.');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('per_page', $result['error']);
        Http::assertNothingSent();
    }

    public function test_an_invented_project_id_is_rejected_without_tracker_reads(): void
    {
        $workflow = $this->workflow();
        Http::preventStrayRequests();
        AnonymousAgent::fake(['{"resource":"projects/delivery-progress","project_id":2}'])->preventStrayPrompts();

        $result = app(WorkflowOperationsService::class)->ask($workflow->owner_key, 'What is the progress of SME 2?');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('explicit numeric project ID', $result['error']);
        Http::assertNothingSent();
    }

    public function test_chat_chart_uses_the_same_tracker_result_without_another_read(): void
    {
        $this->workflow();
        $this->fakeDns();
        Http::preventStrayRequests();
        Http::fake([
            'https://tracker.metro-mobilia.com/api/jarvis/projects?*' => Http::response([
                'data' => [['project_id' => 502698, 'project_name' => '(SME2) Equipment', 'ref_no' => 'REF-2']],
                'meta' => ['pagination' => ['has_more' => false]],
            ]),
            'https://tracker.metro-mobilia.com/api/jarvis/projects/delivery-progress?*' => Http::response($this->progress()),
        ]);
        AnonymousAgent::fake(['{"resource":"projects/delivery-progress","project_name":"SME 2"}', '{"summary":"251557 allocations are pending."}'])->preventStrayPrompts();
        JcAgent::fake([
            new ToolCall('operations', 'AskOperationsAgent', ['request' => 'Show SME 2 progress as a chart.']),
            new ToolCall('chart', 'RenderVisualization', [
                'visual_json' => json_encode(['type' => 'chart', 'chart_type' => 'bar', 'title' => 'SME 2 package allocations',
                    'series' => [['name' => 'Allocations', 'data' => [['label' => 'Total', 'value' => 251557], ['label' => 'Warehouse', 'value' => 0]]]]]),
                'message' => 'No allocations have warehouse status.', 'speech_text' => 'No allocations have warehouse status.',
            ]),
            'No allocations have warehouse status.',
        ])->preventStrayPrompts();

        $response = $this->postJson('/jarvis/command', ['command' => 'Show SME 2 progress as a chart.']);

        $response->assertOk()->assertJsonPath('visual.source', 'MMC Tracker')->assertJsonPath('visual.is_demo', false)
            ->assertJsonPath('visual.series.0.data.0.value', 251557);
        Http::assertSentCount(2);
        AnonymousAgent::assertPromptedTimes(2);
    }

    public function test_chat_automatically_returns_a_report_with_the_same_progress_read(): void
    {
        $this->workflow();
        $this->fakeDns();
        Http::preventStrayRequests();
        Http::fake([
            'https://tracker.metro-mobilia.com/api/jarvis/projects?*' => Http::response([
                'data' => [['project_id' => 502698, 'project_name' => '(SME2) Equipment', 'ref_no' => 'REF-2']],
                'meta' => ['pagination' => ['has_more' => false]],
            ]),
            'https://tracker.metro-mobilia.com/api/jarvis/projects/delivery-progress?*' => Http::response($this->progress()),
        ]);
        AnonymousAgent::fake(['{"resource":"projects/delivery-progress","project_name":"SME 2"}', '{"summary":"No deliveries completed."}'])->preventStrayPrompts();
        JcAgent::fake([new ToolCall('operations', 'AskOperationsAgent', ['request' => 'What is the progress of SME 2?']), 'No deliveries completed.'])->preventStrayPrompts();

        $response = $this->postJson('/jarvis/command', ['command' => 'What is the progress of SME 2?']);

        $response->assertOk()->assertJsonPath('response', 'No deliveries completed.')
            ->assertJsonPath('artifact.type', 'visual_report')->assertJsonPath('artifact.source.system', 'MMC Tracker')
            ->assertJsonPath('display.report.type', 'visual_report')->assertJsonMissingPath('display.details');
        Http::assertSentCount(2);
        AnonymousAgent::assertPromptedTimes(2);
    }

    public function test_project_comparison_reads_each_project_once_and_keeps_chart_units_separate(): void
    {
        $workflow = $this->workflow();
        $this->fakeDns();
        Http::preventStrayRequests();
        Http::fake([
            'https://tracker.metro-mobilia.com/api/jarvis/projects?*' => function ($request) {
                $second = $request['search'] === 'SME 2';

                return Http::response(['data' => [['project_id' => $second ? 502698 : 502682, 'project_name' => $second ? '(SME2) Equipment' : '(SME1) Equipment']], 'meta' => ['pagination' => ['has_more' => false]]]);
            },
            'https://tracker.metro-mobilia.com/api/jarvis/projects/delivery-progress?*' => function ($request) {
                $response = $this->progress();
                $first = $response['data']['projects'][0];
                $first['project_id'] = 502682;
                $first['project_name'] = '(SME1) Equipment';
                $response['data']['projects'][] = $first;

                return Http::response($response);
            },
        ]);
        AnonymousAgent::fake([
            '{"resource":"projects/delivery-progress","project_names":["SME 1","SME 2"]}',
        ])->preventStrayPrompts();

        $result = app(WorkflowOperationsService::class)->ask($workflow->owner_key, 'Compare SME 1 and SME 2.');

        $this->assertTrue($result['success'], $result['error'] ?? '');
        $this->assertSame([502682, 502698], array_column(array_column($result['data']['projects'], 'project'), 'project_id'));
        $this->assertSame('Project comparison', $result['artifact']['title']);
        $this->assertSame('%', $result['artifact']['blocks'][0]['unit']);
        Http::assertSentCount(4);
        foreach ([502682, 502698] as $projectId) {
            Http::assertSent(fn ($request): bool => str_contains($request->url(), 'delivery-progress') && (int) $request['project_id'] === $projectId);
        }
        AnonymousAgent::assertPromptedTimes(1);
    }

    #[DataProvider('statusRetrievalChoices')]
    public function test_exact_project_status_prompt_has_one_verified_read_and_no_duplicate_details(bool $agentReads): void
    {
        $this->workflow();
        $this->fakeDns();
        Http::preventStrayRequests();
        $progress = $this->progress();
        $progress['data']['projects'][0]['project_status'] = 'For Contract Signing';
        $progress['data']['projects'][0]['warehouse_readiness']['by_unit'] = [['unit' => 'pc', 'percent' => 59.36], ['unit' => 'set', 'percent' => 42], ['unit' => 'pcs', 'percent' => 51.59]];
        Http::fake([
            'https://tracker.metro-mobilia.com/api/jarvis/projects?*' => Http::response(['data' => [['project_id' => 502698, 'project_name' => '(SME2) Equipment']], 'meta' => ['pagination' => ['has_more' => false]]]),
            'https://tracker.metro-mobilia.com/api/jarvis/projects/delivery-progress?*' => Http::response($progress),
        ]);
        AnonymousAgent::fake(['{"resource":"project","project_name":"SME 2"}', '{"summary":"SME 2 is For Contract Signing; delivery progress is 0%."}'])->preventStrayPrompts();
        $question = 'What is the status of SME 2?';
        $answer = 'SME 2 is For Contract Signing; delivery progress is 0%.';
        if ($agentReads) {
            JcAgent::fake([new ToolCall('operations', 'AskOperationsAgent', ['request' => $question]), $answer])->preventStrayPrompts();
        } else {
            JcAgent::fake([new ToolCall('report', 'RenderVisualReport', ['artifact_json' => '{"type":"visual_report","title":"Remembered values","blocks":[{"type":"kpi_grid","items":[{"label":"DR","value":28649}]}]}']), 'Remembered answer. The attached visual report did not validate this time.'])->preventStrayPrompts();
        }

        $response = $this->postJson('/jarvis/command', ['command' => $question]);

        $response->assertOk()->assertJsonPath('response', $answer)->assertJsonPath('artifact.type', 'project_status')
            ->assertJsonPath('artifact.project.status', 'For Contract Signing')->assertJsonPath('artifact.overall_progress', null)
            ->assertJsonPath('artifact.warehouse_readiness.0.percentage', 59.36)->assertJsonPath('artifact.pipeline.0.value', 0)
            ->assertJsonMissingPath('display.details')->assertDontSee('did not validate');
        Http::assertSentCount(2);
        AnonymousAgent::assertPromptedTimes(2);
    }

    public static function statusRetrievalChoices(): array
    {
        return ['tool read' => [true], 'AI skipped read' => [false]];
    }

    public function test_status_read_fallback_respects_disabled_analysis_permission(): void
    {
        $this->workflow();
        JarvisSetting::current()->update(['allowed_actions' => []]);
        Http::preventStrayRequests();
        JcAgent::fake(['An old project status.'])->preventStrayPrompts();
        AnonymousAgent::fake()->preventStrayPrompts();

        $response = $this->postJson('/jarvis/command', ['command' => 'What is the status of SME 2?']);

        $response->assertOk()->assertJsonPath('artifact', null);
        $this->assertStringContainsString('disabled in Settings', $response->json('response'));
        Http::assertNothingSent();
        AnonymousAgent::assertNeverPrompted();
    }

    private function progress(): array
    {
        return ['success' => true, 'data' => ['projects' => [[
            'project_id' => 502698, 'project_name' => '(SME2) Equipment', 'ref_no' => 'REF-2',
            'warehouse_readiness' => ['unit' => 'mixed item units', 'percent' => null],
            'operational_pipeline' => ['delivered' => ['completed' => 0, 'total' => 251557, 'percent' => 0]],
            'overall_progress' => null, 'warehouse_packages_count' => 0, 'billed_groups_count' => 0,
            'delivery_progress_percent' => 0, 'billing_progress_percent' => null,
        ]], 'summary' => ['unused' => 'discard-duplicate-summary']],
            'meta' => ['filters' => ['project_id' => 502698], 'definitions' => ['billing' => 'No groups means unknown.']]];
    }

    private function fakeDns(): void
    {
        $this->mock(WorkflowHttpService::class, function ($mock): void {
            foreach (['projects', 'projects/delivery-progress', 'operation-masterlist'] as $path) {
                $url = 'https://tracker.metro-mobilia.com/api/jarvis/'.$path;
                $mock->shouldReceive('validateUrl')->with($url);
                $mock->shouldReceive('requestOptions')->with($url)->andReturn(['allow_redirects' => false]);
            }
        });
    }

    private function workflow(): JarvisWorkflow
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        JarvisSetting::current()->update(['allowed_actions' => ['analysis']]);
        $connection = ApiConnection::factory()->create(['owner_key' => 'user:'.$user->id, 'base_url' => 'https://tracker.metro-mobilia.com', 'token' => 'progress-test-secret']);

        return JarvisWorkflow::query()->create(['owner_key' => 'user:'.$user->id, 'name' => 'Operations', 'status' => 'draft', 'workflow_data' => [
            'nodes' => [
                ['id' => 'router', 'type' => 'orchestrator', 'position' => ['x' => 0, 'y' => 0], 'config' => []],
                ['id' => 'ops', 'type' => 'ai_agent', 'position' => ['x' => 100, 'y' => 0], 'config' => ['specialization' => 'operations', 'instruction' => 'Report actual Tracker data.', 'execution_mode' => 'deterministic']],
                ['id' => 'tracker', 'type' => 'app_action', 'position' => ['x' => 200, 'y' => 0], 'config' => ['app' => 'custom_api', 'action' => 'request', 'values' => ['connection_id' => $connection->id, 'url' => '/api/jarvis/operation-masterlist', 'method' => 'GET']]],
            ], 'edges' => [['source' => 'router', 'target' => 'ops'], ['source' => 'ops', 'target' => 'tracker']],
        ]]);
    }
}
