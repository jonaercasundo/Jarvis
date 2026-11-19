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
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Responses\Data\ToolCall;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GenericOperationsTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[DataProvider('projectReferences')]
    public function test_new_projects_resolve_by_tracker_identity_without_project_specific_code(string $question, array $selection, string $reference): void
    {
        $workflow = $this->workflow();
        $project = $this->project(73001, 'NEW-2027 Science Equipment', 23.75);
        $this->fakeDirectory([['project_id' => 73001, 'project_name' => $project['project_name'], 'ref_no' => 'ABC-01', 'status' => 'Implementation']]);
        Http::fake(['https://tracker.metro-mobilia.com/api/jarvis/projects/delivery-progress?*' => Http::response($this->aggregate([$project]))]);
        AnonymousAgent::fake([json_encode($selection), '{"summary":"Verified project status."}'])->preventStrayPrompts();

        $result = app(WorkflowOperationsService::class)->ask($workflow->owner_key, $question);

        $this->assertTrue($result['success'], $result['error'] ?? '');
        $this->assertSame(73001, $result['data']['project']['project_id']);
        $this->assertSame('NEW-2027 Science Equipment', $result['artifact']['project']['name']);
        $this->assertSame('project_status', $result['artifact']['type']);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => parse_url($request->url(), PHP_URL_PATH) === '/api/jarvis/projects'
            && ($reference === '73001' ? (string) $request['project_id'] === $reference : $request['search'] === $reference));
    }

    public static function projectReferences(): array
    {
        return [
            'future project name' => ['What is the status of NEW-2027?', ['resource' => 'projects', 'project_name' => 'NEW-2027'], 'NEW-2027'],
            'formatted reference' => ['Show project with reference number ABC 01.', ['resource' => 'project', 'project_name' => 'ABC-01'], 'ABC 01'],
            'explicit ID' => ['How is project 73001 doing?', ['resource' => 'project', 'project_id' => 73001], '73001'],
        ];
    }

    public function test_three_project_comparison_uses_one_ai_selection_and_scoped_progress_reads(): void
    {
        $workflow = $this->workflow();
        $projects = [$this->project(910, 'Project ABC', 10), $this->project(220, 'Project XYZ', 40), $this->project(880, 'NEW-2027', 70)];
        Http::fake(['https://tracker.metro-mobilia.com/api/jarvis/projects?*' => fn (Request $request) => Http::response([
            'data' => array_values(array_filter($projects, fn (array $project): bool => $project['project_name'] === $request['search'])),
            'meta' => ['pagination' => ['has_more' => false]],
        ]), 'https://tracker.metro-mobilia.com/api/jarvis/projects/delivery-progress?*' => Http::response($this->aggregate($projects))]);
        AnonymousAgent::fake(['{"resource":"projects/delivery-progress","project_names":["Project ABC","Project XYZ","NEW-2027"]}'])->preventStrayPrompts();

        $result = app(WorkflowOperationsService::class)->ask($workflow->owner_key, 'Compare Project ABC, Project XYZ, and NEW-2027 deliveries.');

        $this->assertTrue($result['success'], $result['error'] ?? '');
        $this->assertSame([910, 220, 880], array_column(array_column($result['data']['projects'], 'project'), 'project_id'));
        $this->assertSame('project_comparison', $result['artifact']['type']);
        Http::assertSentCount(6);
        AnonymousAgent::assertPromptedTimes(1);
    }

    public function test_active_portfolio_and_highest_completion_use_one_aggregate_call_and_no_ai_prompts(): void
    {
        $workflow = $this->workflow();
        Http::fake(['https://tracker.metro-mobilia.com/api/jarvis/projects/delivery-progress?*' => Http::response($this->aggregate([
            $this->project(11, 'Civic Laboratory', null), $this->project(12, 'Book Supply', 63.5), $this->project(13, 'School Upgrade', 63.5),
        ]))]);
        AnonymousAgent::fake()->preventStrayPrompts();

        $result = app(WorkflowOperationsService::class)->ask($workflow->owner_key, 'Which active project has the highest delivery completion?');

        $this->assertTrue($result['success'], $result['error'] ?? '');
        $this->assertSame([12, 13, 11], array_column(array_column($result['data']['projects'], 'project'), 'project_id'));
        $this->assertStringContainsString('shared by 2', $result['summary']);
        $this->assertSame('project_portfolio', $result['artifact']['type']);
        $this->assertNull($result['artifact']['blocks'][0]['rows'][2][2]['value']);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => (int) $request['active_only'] === 1 && ! isset($request['project_id']) && (int) $request['per_page'] === 20);
        AnonymousAgent::assertNeverPrompted();
    }

    public function test_delivery_status_reports_geography_and_deduplicates_schools_on_a_partial_page(): void
    {
        $workflow = $this->workflow();
        $this->fakeDirectory([['project_id' => 340, 'project_name' => 'Science Equipment', 'status' => 'Implementation']]);
        $base = ['project_id' => 340, 'region' => 'Region A', 'division' => 'Division A', 'municipality' => 'Town A', 'delivery_status' => 'delivered', 'delivered_date' => '2026-09-30',
            'package_allocations_count' => 1, 'delivered_packages_count' => 1, 'accepted_packages_count' => 0];
        Http::fake(['https://tracker.metro-mobilia.com/api/jarvis/deliveries?*' => Http::response(['data' => [
            [...$base, 'school_id' => 'A', 'school_name' => 'School A'], [...$base, 'school_id' => 'A', 'school_name' => 'School A'],
            [...$base, 'school_id' => 'B', 'school_name' => 'School B', 'package_allocations_count' => 2],
            [...$base, 'school_id' => 'C', 'school_name' => 'School C', 'delivered_packages_count' => 0, 'delivery_status' => 'pending'],
        ], 'meta' => ['pagination' => ['has_more' => true, 'current_page' => 1, 'last_page' => 2]]])]);
        AnonymousAgent::fake(['{"resource":"deliveries","project_name":"Science Equipment"}', '{"summary":"Delivery locations retrieved."}'])->preventStrayPrompts();

        $result = app(WorkflowOperationsService::class)->ask($workflow->owner_key, 'Show individual delivery records and locations for Science Equipment.');

        $this->assertTrue($result['success'], $result['error'] ?? '');
        $this->assertSame(['schools_delivered' => 1, 'schools_partial' => 1, 'schools_pending' => 1, 'regions_reached' => 1, 'divisions_reached' => 1, 'municipalities_reached' => 1], $result['data']['delivery_coverage']);
        $this->assertSame('delivery_status', $result['artifact']['type']);
        $this->assertSame('School / Location', $result['artifact']['blocks'][1]['columns'][3]);
        $this->assertStringContainsString('Partial coverage', $result['artifact']['blocks'][3]['text']);
        Http::assertSentCount(2);
    }

    public function test_inventory_status_retains_individual_units_instead_of_warehouse_readiness(): void
    {
        $workflow = $this->workflow();
        $this->fakeDirectory([['project_id' => 345, 'project_name' => 'Project XYZ', 'status' => 'Implementation']]);
        Http::fake(['https://tracker.metro-mobilia.com/api/jarvis/inventory?*' => Http::response(['data' => [
            ['item_name' => 'Blocks', 'unit' => 'set', 'qty' => 3, 'warehouse_name' => 'North', 'inventory_status' => 'Approved'],
            ['item_name' => 'Books', 'unit' => 'pc', 'qty' => 10, 'warehouse_name' => 'South', 'inventory_status' => 'Approved'],
        ], 'meta' => ['pagination' => ['has_more' => false]]])]);
        AnonymousAgent::fake(['{"resource":"inventory","project_name":"Project XYZ"}', '{"summary":"Inventory retrieved."}'])->preventStrayPrompts();

        $result = app(WorkflowOperationsService::class)->ask($workflow->owner_key, 'Show Project XYZ warehouse stock.');

        $this->assertTrue($result['success'], $result['error'] ?? '');
        $this->assertSame('warehouse_status', $result['artifact']['type']);
        $this->assertSame('table', $result['artifact']['blocks'][0]['type']);
        $this->assertSame(['set', 'pc'], array_column(array_column($result['artifact']['blocks'][0]['rows'], 1), 'value'));
        $this->assertSame([3.0, 10.0], array_column(array_column($result['artifact']['blocks'][0]['rows'], 2), 'value'));
        Http::assertSentCount(2);
    }

    public function test_ambiguous_project_choices_resume_the_original_delivery_question_with_a_tracker_id(): void
    {
        $this->workflow();
        $this->fakeDirectory([['project_id' => 401, 'project_name' => 'Science North'], ['project_id' => 402, 'project_name' => 'Science South']]);
        AnonymousAgent::fake(['{"resource":"deliveries","project_name":"Science"}'])->preventStrayPrompts();
        JcAgent::fake([new ToolCall('operations', 'AskOperationsAgent', ['request' => 'Show Science individual delivery records.']), 'Which project?'])->preventStrayPrompts();

        $response = $this->postJson('/jarvis/command', ['command' => 'Show Science individual delivery records.']);

        $response->assertOk()->assertJsonPath('type', 'clarification')->assertJsonPath('options.0.value', 'Show project ID 401 individual delivery records.');
        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/deliveries'));

        Http::fake(['https://tracker.metro-mobilia.com/api/jarvis/deliveries?*' => Http::response(['data' => [], 'meta' => ['pagination' => ['has_more' => false]]])]);
        AnonymousAgent::fake(['{"resource":"deliveries","project_id":401}', '{"summary":"Selected project locations retrieved."}'])->preventStrayPrompts();
        JcAgent::fake([new ToolCall('operations', 'AskOperationsAgent', ['request' => 'Show project ID 401 individual delivery records.']), 'Selected project locations retrieved.'])->preventStrayPrompts();

        $selected = $this->postJson('/jarvis/command', ['command' => $response->json('options.0.value'), 'clarification_id' => $response->json('clarification_id'), 'conversation_id' => $response->json('conversation_id')]);

        $this->assertNotNull($selected->json('artifact'), json_encode($selected->json()));
        $selected->assertOk()->assertJsonPath('artifact.type', 'delivery_status');
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/deliveries') && (int) $request['project_id'] === 401);
    }

    public function test_large_portfolios_discard_inventory_details_before_workflow_size_limits(): void
    {
        $workflow = $this->workflow();
        $projects = [];
        for ($index = 1; $index <= 50; $index++) {
            $project = $this->project($index, 'Future Project '.$index, 10);
            $project['warehouse_readiness']['items'] = array_fill(0, 100, ['description' => str_repeat('Large inventory detail ', 10)]);
            $projects[] = $project;
        }
        Http::fake(['https://tracker.metro-mobilia.com/api/jarvis/projects/delivery-progress?*' => Http::response($this->aggregate($projects))]);
        AnonymousAgent::fake()->preventStrayPrompts();

        $result = app(WorkflowOperationsService::class)->ask($workflow->owner_key, 'Compare all active projects.');

        $this->assertTrue($result['success'], $result['error'] ?? '');
        $this->assertCount(50, $result['data']['projects']);
        $this->assertSame('project_portfolio', $result['artifact']['type']);
        $this->assertCount(50, $result['artifact']['blocks'][0]['rows']);
        Http::assertSentCount(1);
        AnonymousAgent::assertNeverPrompted();
    }

    public function test_billing_intent_reports_only_recorded_billing_stages(): void
    {
        $workflow = $this->workflow();
        $this->fakeDirectory([['project_id' => 73001, 'project_name' => 'Project ABC']]);
        $project = $this->project(73001, 'Project ABC', 20);
        $project['for_billing_groups_count'] = 3;
        $project['billed_groups_count'] = 2;
        $project['paid_groups_count'] = 0;
        Http::fake(['https://tracker.metro-mobilia.com/api/jarvis/projects/delivery-progress?*' => Http::response($this->aggregate([$project]))]);
        AnonymousAgent::fake(['{"resource":"project","project_name":"Project ABC"}', '{"summary":"Billing stages retrieved."}'])->preventStrayPrompts();

        $result = app(WorkflowOperationsService::class)->ask($workflow->owner_key, 'Show billing status of Project ABC.');

        $this->assertTrue($result['success'], $result['error'] ?? '');
        $this->assertSame('billing_status', $result['artifact']['type']);
        $this->assertSame([3.0, 2.0, 0.0], array_column($result['artifact']['blocks'][0]['items'], 'value'));
        Http::assertSentCount(2);
    }

    public function test_portfolio_reports_pagination_without_claiming_complete_coverage(): void
    {
        $workflow = $this->workflow();
        $response = $this->aggregate([$this->project(11, 'Civic Laboratory', 60)]);
        $response['meta']['pagination'] = ['current_page' => 1, 'last_page' => 3, 'per_page' => 20, 'total' => 45, 'has_more' => true];
        Http::fake(['https://tracker.metro-mobilia.com/api/jarvis/projects/delivery-progress?*' => Http::response($response)]);
        AnonymousAgent::fake()->preventStrayPrompts();

        $result = app(WorkflowOperationsService::class)->ask($workflow->owner_key, 'Show all active project delivery summaries.');

        $this->assertTrue($result['success'], $result['error'] ?? '');
        $this->assertSame(45, $result['data']['pagination']['total']);
        $this->assertStringContainsString('comparisons cover this page only', $result['summary']);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => (int) $request['per_page'] === 20 && (int) $request['page'] === 1 && (int) $request['compact'] === 1);
        AnonymousAgent::assertNeverPrompted();
    }

    private function fakeDirectory(array $projects): void
    {
        Http::fake(['https://tracker.metro-mobilia.com/api/jarvis/projects?*' => Http::response(['data' => $projects, 'meta' => ['pagination' => ['has_more' => false]]])]);
    }

    private function project(int $id, string $name, ?float $percent): array
    {
        return ['project_id' => $id, 'project_name' => $name, 'project_status' => 'Implementation', 'is_active' => true,
            'total_deliveries_count' => 10, 'total_package_allocations_count' => 100, 'billing_groups_count' => 0,
            'delivery_progress_percent' => $percent, 'billing_progress_percent' => null, 'overall_progress' => null,
            'warehouse_readiness' => ['by_unit' => []], 'operational_pipeline' => ['delivered' => ['label' => 'Delivered', 'completed' => $percent, 'total' => 100, 'percent' => $percent, 'unit' => 'package allocations']]];
    }

    private function aggregate(array $projects): array
    {
        return ['success' => true, 'data' => ['projects' => $projects], 'meta' => ['generated_at' => '2026-10-04T03:00:00Z', 'filters' => [], 'definitions' => []]];
    }

    private function workflow(): JarvisWorkflow
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        JarvisSetting::current()->update(['allowed_actions' => ['analysis']]);
        $connection = ApiConnection::factory()->create(['owner_key' => 'user:'.$user->id, 'base_url' => 'https://tracker.metro-mobilia.com', 'token' => 'generic-test-secret']);
        Http::preventStrayRequests();
        $this->mock(WorkflowHttpService::class, function ($mock): void {
            foreach (['projects', 'projects/delivery-progress', 'deliveries', 'inventory'] as $path) {
                $url = 'https://tracker.metro-mobilia.com/api/jarvis/'.$path;
                $mock->shouldReceive('validateUrl')->with($url);
                $mock->shouldReceive('requestOptions')->with($url)->andReturn(['allow_redirects' => false]);
            }
        });

        return JarvisWorkflow::query()->create(['owner_key' => 'user:'.$user->id, 'name' => 'Operations', 'status' => 'draft', 'workflow_data' => [
            'nodes' => [
                ['id' => 'router', 'type' => 'orchestrator', 'position' => ['x' => 0, 'y' => 0], 'config' => []],
                ['id' => 'ops', 'type' => 'ai_agent', 'position' => ['x' => 100, 'y' => 0], 'config' => ['specialization' => 'operations', 'instruction' => 'Use Tracker.', 'execution_mode' => 'deterministic']],
                ['id' => 'tracker', 'type' => 'app_action', 'position' => ['x' => 200, 'y' => 0], 'config' => ['app' => 'custom_api', 'action' => 'request', 'values' => ['connection_id' => $connection->id, 'url' => '/api/jarvis/projects', 'method' => 'GET']]],
            ], 'edges' => [['source' => 'router', 'target' => 'ops'], ['source' => 'ops', 'target' => 'tracker']],
        ]]);
    }
}
