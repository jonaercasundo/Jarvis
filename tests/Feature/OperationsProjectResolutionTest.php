<?php

namespace Tests\Feature;

use App\Models\ApiConnection;
use App\Models\JarvisSetting;
use App\Models\JarvisWorkflow;
use App\Models\User;
use App\Services\WorkflowHttpService;
use App\Services\WorkflowOperationsService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\AnonymousAgent;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OperationsProjectResolutionTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[DataProvider('bareProjectAliases')]
    public function test_a_bare_project_alias_reads_its_status_without_requiring_a_question(string $alias): void
    {
        $workflow = $this->workflow();
        $this->fakeDns();
        Http::preventStrayRequests();
        Http::fake([
            'https://tracker.metro-mobilia.com/api/jarvis/projects?*' => fn (Request $request) => Http::response($this->searchResult(
                $request['search'] === 'SME2' ? [['project_id' => 502698, 'project_name' => '(SME2) Equipment']] : [],
            )),
            'https://tracker.metro-mobilia.com/api/jarvis/projects/delivery-progress?*' => Http::response($this->progress(502698, '(SME2) Equipment')),
        ]);
        AnonymousAgent::fake(['{"resource":"projects","project_name":null}', '{"summary":"SME 2 is For Contract Signing."}'])->preventStrayPrompts();

        $result = app(WorkflowOperationsService::class)->ask($workflow->owner_key, $alias);

        $this->assertTrue($result['success'], $result['error'] ?? '');
        $this->assertSame(502698, $result['data']['project']['project_id']);
        $this->assertSame('projects/delivery-progress', $result['meta']['resource']);
        $this->assertIsArray($result['artifact'], 'A bare project alias should produce the same status report as a status question.');
        $this->assertSame('project_status', $result['artifact']['type']);
        $this->assertSame('For Contract Signing', $result['artifact']['project']['status']);
        $this->assertNull($result['artifact']['overall_progress']);
        $this->assertLookupQueryIsSmallAndUnfiltered();
        Http::assertSentCount($alias === 'SME2' ? 2 : 3);
        AnonymousAgent::assertPromptedTimes(2);
    }

    /** @return array<string, array{string}> */
    public static function bareProjectAliases(): array
    {
        return ['spaced alias' => ['SME 2'], 'compact alias' => ['SME2'], 'parenthesized alias' => ['(SME2)']];
    }

    public function test_an_ai_compacted_name_preserves_the_original_user_phrase_for_the_first_search(): void
    {
        $workflow = $this->workflow();
        $this->fakeDns();
        Http::preventStrayRequests();
        Http::fake([
            'https://tracker.metro-mobilia.com/api/jarvis/projects?*' => fn (Request $request) => Http::response($this->searchResult(
                $request['search'] === 'SME2' ? [['project_id' => 502698, 'project_name' => '(SME2) Equipment']] : [],
            )),
            'https://tracker.metro-mobilia.com/api/jarvis/projects/delivery-progress?*' => Http::response($this->progress(502698, '(SME2) Equipment')),
        ]);
        AnonymousAgent::fake(['{"resource":"project","project_name":"SME2"}', '{"summary":"SME 2 is For Contract Signing."}'])->preventStrayPrompts();

        $result = app(WorkflowOperationsService::class)->ask($workflow->owner_key, 'What is the status of SME 2?');

        $this->assertTrue($result['success'], $result['error'] ?? '');
        $this->assertSame(502698, $result['data']['project']['project_id']);
        Http::assertSentInOrder([
            fn (Request $request): bool => parse_url($request->url(), PHP_URL_PATH) === '/api/jarvis/projects' && $request['search'] === 'SME 2',
            fn (Request $request): bool => parse_url($request->url(), PHP_URL_PATH) === '/api/jarvis/projects' && $request['search'] === 'SME2',
            fn (Request $request): bool => parse_url($request->url(), PHP_URL_PATH) === '/api/jarvis/projects/delivery-progress' && (int) $request['project_id'] === 502698,
        ]);
        AnonymousAgent::assertPromptedTimes(2);
    }

    public function test_resolution_diagnostics_show_search_results_without_logging_authentication(): void
    {
        $workflow = $this->workflow();
        $this->fakeDns();
        Http::preventStrayRequests();
        $candidate = ['project_id' => 502698, 'project_name' => '(SME2) Equipment', 'ref_no' => 'REF-2'];
        $responseBodies = [
            'SME 2' => json_encode($this->searchResult([]), JSON_THROW_ON_ERROR),
            'SME2' => json_encode($this->searchResult([[...$candidate, 'token' => 'candidate-secret', 'authorization' => 'Bearer candidate-secret']]), JSON_THROW_ON_ERROR),
        ];
        Http::fake([
            'https://tracker.metro-mobilia.com/api/jarvis/projects?*' => fn (Request $request) => Http::response($responseBodies[$request['search']], 200, ['Content-Type' => 'application/json']),
            'https://tracker.metro-mobilia.com/api/jarvis/projects/delivery-progress?*' => Http::response($this->progress(502698, '(SME2) Equipment')),
        ]);
        AnonymousAgent::fake(['{"resource":"project","project_name":"SME 2"}', '{"summary":"SME 2 is For Contract Signing."}'])->preventStrayPrompts();
        Log::spy();

        $result = app(WorkflowOperationsService::class)->ask($workflow->owner_key, 'What is the status of SME 2?');

        $this->assertTrue($result['success'], $result['error'] ?? '');
        foreach (['SME 2' => 0, 'SME2' => 1] as $search => $matches) {
            Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context) use ($search, $matches, $responseBodies, $candidate): bool {
                if ($message !== 'Operations project resolution') {
                    return false;
                }
                $logged = json_encode($context);

                return ($context['project_query_original'] ?? null) === 'SME 2'
                    && ($context['project_query_normalized'] ?? null) === 'SME2'
                    && ($context['endpoint'] ?? null) === '/api/jarvis/projects'
                    && ($context['query'] ?? null) === ['search' => $search, 'page' => 1, 'per_page' => 10]
                    && ($context['http_status'] ?? null) === 200
                    && ($context['matches'] ?? null) === $matches
                    && ($context['tracker_records_returned'] ?? null) === $matches
                    && ($context['records_after_local_filtering'] ?? null) === $matches
                    && ($context['response_bytes'] ?? null) === strlen($responseBodies[$search])
                    && $context['response_bytes'] > 0
                    && ($context['applied_filters'] ?? null) === ['search' => $search, 'page' => 1, 'per_page' => 10,
                        'limit' => null, 'project_id' => null, 'year' => null, 'active_only' => null, 'status' => null]
                    && ($context['local_filters']['identity_fields'] ?? null) === ['project_code', 'code', 'short_name', 'ref_no', 'project_name']
                    && ($context['local_filters']['normalization'] ?? null) === 'Uppercase letters and numbers; formatting separators ignored.'
                    && ($context['local_filters']['precise_identity_boundaries'] ?? null) === true
                    && ($context['local_filters']['requires_complete_pagination'] ?? null) === true
                    && ($context['local_filters']['requires_positive_project_id'] ?? null) === true
                    && ($context['local_filters']['requires_project_name_string'] ?? null) === true
                    && ($context['candidate_projects'] ?? null) === ($matches === 0 ? [] : [$candidate])
                    && ($context['matched_projects'] ?? null) === ($matches === 0 ? [] : [$candidate])
                    && ($context['pagination'] ?? null) === ['has_more' => false]
                    && ! str_contains($logged, 'project-test-secret')
                    && ! str_contains($logged, 'candidate-secret')
                    && ! str_contains($logged, 'Bearer')
                    && stripos($logged, 'authorization') === false
                    && stripos($logged, 'headers') === false
                    && stripos($logged, 'token') === false;
            })->once();
        }
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer project-test-secret'));
        AnonymousAgent::assertPromptedTimes(2);
    }

    #[DataProvider('projectAliases')]
    public function test_formatting_aliases_retry_a_small_compact_search_and_read_the_resolved_project(string $alias, string $compact, int $projectId, string $projectName): void
    {
        $workflow = $this->workflow();
        $this->fakeDns();
        Http::preventStrayRequests();
        Http::fake([
            'https://tracker.metro-mobilia.com/api/jarvis/projects?*' => fn (Request $request) => Http::response($this->searchResult(
                $request['search'] === $compact ? [['project_id' => $projectId, 'project_name' => $projectName]] : [],
            )),
            'https://tracker.metro-mobilia.com/api/jarvis/projects/delivery-progress?*' => Http::response($this->progress($projectId, $projectName)),
        ]);
        AnonymousAgent::fake([
            json_encode(['resource' => 'project', 'project_name' => $alias]),
            '{"summary":"The project is waiting for contract signing; delivery progress is 0%."}',
        ])->preventStrayPrompts();

        $result = app(WorkflowOperationsService::class)->ask($workflow->owner_key, 'What is the status of '.$alias.'?');

        $this->assertTrue($result['success'], $result['error'] ?? '');
        $this->assertSame($projectId, $result['data']['project']['project_id']);
        $this->assertSame($projectName, $result['data']['project']['project_name']);
        $this->assertSame('projects/delivery-progress', $result['meta']['resource']);
        $expectedSearches = $alias === $compact ? [$alias] : [$alias, $compact];
        $lookups = Http::recorded(fn (Request $request): bool => parse_url($request->url(), PHP_URL_PATH) === '/api/jarvis/projects');
        $this->assertSame($expectedSearches, $lookups->map(fn (array $pair): string => $pair[0]['search'])->values()->all());
        $this->assertLookupQueryIsSmallAndUnfiltered();
        Http::assertSent(fn (Request $request): bool => parse_url($request->url(), PHP_URL_PATH) === '/api/jarvis/projects/delivery-progress'
            && (int) $request['project_id'] === $projectId);
        Http::assertSentCount(count($expectedSearches) + 1);
        AnonymousAgent::assertPromptedTimes(2);
    }

    /** @return array<string, array{string, string, int, string}> */
    public static function projectAliases(): array
    {
        return [
            'spaced code' => ['SME 2', 'SME2', 502698, '(SME2) Equipment'],
            'compact code' => ['SME2', 'SME2', 502698, '(SME2) Equipment'],
            'parenthesized code' => ['(SME2)', 'SME2', 502698, '(SME2) Equipment'],
            'lowercase spaced code' => ['sme 2', 'SME2', 502698, '(SME2) Equipment'],
            'lowercase compact code' => ['sme2', 'SME2', 502698, '(SME2) Equipment'],
            'hyphenated code' => ['SME-2', 'SME2', 502698, '(SME2) Equipment'],
            'different hyphenated code' => ['BLD-7', 'BLD7', 713, '(BLD-7) Fitout'],
            'different parenthesized code' => ['(BLD7)', 'BLD7', 713, '(BLD-7) Fitout'],
            'different lowercase spaced code' => ['bld 7', 'BLD7', 713, '(BLD-7) Fitout'],
        ];
    }

    #[DataProvider('structuredIdentityFields')]
    public function test_available_structured_identifiers_resolve_without_a_code_in_the_project_description(string $field): void
    {
        $workflow = $this->workflow();
        $this->fakeDns();
        Http::preventStrayRequests();
        Http::fake([
            'https://tracker.metro-mobilia.com/api/jarvis/projects?*' => Http::response($this->searchResult([
                ['project_id' => 713, 'project_name' => 'Renovation and Fitout', $field => 'BLD-7'],
            ])),
            'https://tracker.metro-mobilia.com/api/jarvis/projects/delivery-progress?*' => Http::response($this->progress(713, 'Renovation and Fitout')),
        ]);
        AnonymousAgent::fake(['{"resource":"project","project_name":"bld 7"}', '{"summary":"Delivery progress is 0%."}'])->preventStrayPrompts();

        $result = app(WorkflowOperationsService::class)->ask($workflow->owner_key, 'What is the status of bld 7?');

        $this->assertTrue($result['success'], $result['error'] ?? '');
        $this->assertSame(713, $result['data']['project']['project_id']);
        $this->assertSame('Renovation and Fitout', $result['data']['project']['project_name']);
        Http::assertSentCount(2);
        AnonymousAgent::assertPromptedTimes(2);
    }

    /** @return array<string, array{string}> */
    public static function structuredIdentityFields(): array
    {
        return ['project_code' => ['project_code'], 'code' => ['code'], 'short_name' => ['short_name'], 'ref_no' => ['ref_no']];
    }

    public function test_zero_matches_retry_only_the_compact_query_and_never_read_business_data(): void
    {
        $workflow = $this->workflow();
        $this->fakeDns();
        Http::preventStrayRequests();
        Http::fake(['https://tracker.metro-mobilia.com/api/jarvis/projects?*' => Http::response($this->searchResult([]))]);
        AnonymousAgent::fake(['{"resource":"project","project_name":"SME 2"}'])->preventStrayPrompts();

        $result = app(WorkflowOperationsService::class)->ask($workflow->owner_key, 'What is the status of SME 2?');

        $this->assertFalse($result['success']);
        $this->assertNull($result['data']);
        $this->assertStringContainsString('No project matched', $result['error']);
        Http::assertSentInOrder([
            fn (Request $request): bool => $request['search'] === 'SME 2',
            fn (Request $request): bool => $request['search'] === 'SME2',
        ]);
        $this->assertLookupQueryIsSmallAndUnfiltered();
        AnonymousAgent::assertPromptedTimes(1);
    }

    public function test_multiple_precise_matches_return_safe_candidates_without_reading_business_data(): void
    {
        $workflow = $this->workflow();
        $this->fakeDns();
        Http::preventStrayRequests();
        Http::fake(['https://tracker.metro-mobilia.com/api/jarvis/projects?*' => Http::response($this->searchResult([
            ['project_id' => 713, 'project_name' => '(SME2) Equipment North', 'ref_no' => 'REF-N', 'token' => 'candidate-secret'],
            ['project_id' => 714, 'project_name' => '(SME2) Equipment South', 'ref_no' => 'REF-S', 'authorization' => 'Bearer candidate-secret'],
        ]))]);
        AnonymousAgent::fake(['{"resource":"project","project_name":"SME 2"}'])->preventStrayPrompts();

        $result = app(WorkflowOperationsService::class)->ask($workflow->owner_key, 'What is the status of SME 2?');

        $this->assertFalse($result['success']);
        $this->assertSame('ambiguous_project', $result['error_type']);
        $this->assertSame([713, 714], array_column($result['candidates'], 'project_id'));
        $this->assertSame(['(SME2) Equipment North', '(SME2) Equipment South'], array_column($result['candidates'], 'project_name'));
        $this->assertStringNotContainsString('candidate-secret', json_encode($result));
        Http::assertSentCount(1);
        AnonymousAgent::assertPromptedTimes(1);
    }

    public function test_a_similar_code_is_never_used_as_the_requested_project(): void
    {
        $workflow = $this->workflow();
        $this->fakeDns();
        Http::preventStrayRequests();
        Http::fake(['https://tracker.metro-mobilia.com/api/jarvis/projects?*' => Http::response($this->searchResult([
            ['project_id' => 20, 'project_name' => '(SME20) Equipment'],
        ]))]);
        AnonymousAgent::fake(['{"resource":"project","project_name":"SME 2"}'])->preventStrayPrompts();

        $result = app(WorkflowOperationsService::class)->ask($workflow->owner_key, 'What is the status of SME 2?');

        $this->assertFalse($result['success']);
        $this->assertNull($result['data']);
        $this->assertStringContainsString('precise', $result['error']);
        Http::assertNotSent(fn (Request $request): bool => parse_url($request->url(), PHP_URL_PATH) !== '/api/jarvis/projects');
        AnonymousAgent::assertPromptedTimes(1);
    }

    public function test_one_precise_match_is_selected_from_other_broad_code_results(): void
    {
        $workflow = $this->workflow();
        $this->fakeDns();
        Http::preventStrayRequests();
        Http::fake([
            'https://tracker.metro-mobilia.com/api/jarvis/projects?*' => Http::response($this->searchResult([
                ['project_id' => 20, 'project_name' => '(SME20) Equipment'],
                ['project_id' => 502698, 'project_name' => '(SME2) Equipment'],
            ])),
            'https://tracker.metro-mobilia.com/api/jarvis/projects/delivery-progress?*' => Http::response($this->progress(502698, '(SME2) Equipment')),
        ]);
        AnonymousAgent::fake(['{"resource":"project","project_name":"SME 2"}', '{"summary":"Delivery progress is 0%."}'])->preventStrayPrompts();

        $result = app(WorkflowOperationsService::class)->ask($workflow->owner_key, 'What is the status of SME 2?');

        $this->assertTrue($result['success'], $result['error'] ?? '');
        $this->assertSame(502698, $result['data']['project']['project_id']);
        Http::assertSentCount(2);
        AnonymousAgent::assertPromptedTimes(2);
    }

    #[DataProvider('incompletePagination')]
    public function test_incomplete_filtered_results_do_not_guess_an_identity_or_download_more_pages(array $pagination): void
    {
        $workflow = $this->workflow();
        $this->fakeDns();
        Http::preventStrayRequests();
        Http::fake(['https://tracker.metro-mobilia.com/api/jarvis/projects?*' => Http::response([
            'data' => [['project_id' => 502698, 'project_name' => '(SME2) Equipment']], 'meta' => ['pagination' => $pagination],
        ])]);
        AnonymousAgent::fake(['{"resource":"project","project_name":"SME 2"}'])->preventStrayPrompts();

        $result = app(WorkflowOperationsService::class)->ask($workflow->owner_key, 'What is the status of SME 2?');

        $this->assertFalse($result['success']);
        $this->assertNull($result['data']);
        $this->assertStringContainsString('more pages', $result['error']);
        Http::assertSentCount(1);
        $this->assertLookupQueryIsSmallAndUnfiltered();
        AnonymousAgent::assertPromptedTimes(1);
    }

    /** @return array<string, array{array<string, int|bool>}> */
    public static function incompletePagination(): array
    {
        return ['has_more' => [['has_more' => true]], 'last_page' => [['current_page' => 1, 'last_page' => 2]]];
    }

    public function test_unrequested_year_and_status_never_exclude_a_project_from_lookup_or_progress(): void
    {
        $workflow = $this->workflow();
        $this->fakeDns();
        Http::preventStrayRequests();
        Http::fake([
            'https://tracker.metro-mobilia.com/api/jarvis/projects?*' => Http::response($this->searchResult([
                ['project_id' => 502698, 'project_name' => '(SME2) Equipment FY 2025'],
            ])),
            'https://tracker.metro-mobilia.com/api/jarvis/projects/delivery-progress?*' => Http::response($this->progress(502698, '(SME2) Equipment FY 2025')),
        ]);
        AnonymousAgent::fake(['{"resource":"project","project_name":"SME 2","year":2026,"status":"Active"}', '{"summary":"The project is For Contract Signing."}'])->preventStrayPrompts();

        $result = app(WorkflowOperationsService::class)->ask($workflow->owner_key, 'What is the status of SME 2?');

        $this->assertTrue($result['success'], $result['error'] ?? '');
        $this->assertSame(502698, $result['data']['project']['project_id']);
        $this->assertLookupQueryIsSmallAndUnfiltered();
        Http::assertSent(fn (Request $request): bool => parse_url($request->url(), PHP_URL_PATH) === '/api/jarvis/projects/delivery-progress'
            && ! isset($request['year']) && ! isset($request['status']) && (int) $request['project_id'] === 502698);
        Http::assertSentCount(2);
    }

    public function test_an_explicit_year_is_applied_only_after_the_project_is_resolved(): void
    {
        $workflow = $this->workflow();
        $this->fakeDns();
        Http::preventStrayRequests();
        Http::fake([
            'https://tracker.metro-mobilia.com/api/jarvis/projects?*' => Http::response($this->searchResult([
                ['project_id' => 502698, 'project_name' => '(SME2) Equipment FY 2025'],
            ])),
            'https://tracker.metro-mobilia.com/api/jarvis/projects/delivery-progress?*' => Http::response($this->progress(502698, '(SME2) Equipment FY 2025')),
        ]);
        AnonymousAgent::fake(['{"resource":"project","project_name":"SME 2","year":2025}', '{"summary":"Delivery progress is 0%."}'])->preventStrayPrompts();

        $result = app(WorkflowOperationsService::class)->ask($workflow->owner_key, 'What is the status of SME 2 for year 2025?');

        $this->assertTrue($result['success'], $result['error'] ?? '');
        $this->assertLookupQueryIsSmallAndUnfiltered();
        Http::assertSent(fn (Request $request): bool => parse_url($request->url(), PHP_URL_PATH) === '/api/jarvis/projects/delivery-progress'
            && (int) $request['year'] === 2025 && (int) $request['project_id'] === 502698);
        Http::assertSentCount(2);
        AnonymousAgent::assertPromptedTimes(2);
    }

    #[DataProvider('monitoringQuestions')]
    public function test_monitoring_questions_use_resolved_progress_rather_than_the_catalog(string $question, string $alias): void
    {
        $workflow = $this->workflow();
        $this->fakeDns();
        Http::preventStrayRequests();
        Http::fake([
            'https://tracker.metro-mobilia.com/api/jarvis/projects?*' => Http::response($this->searchResult([
                ['project_id' => 502698, 'project_name' => '(SME2) Equipment'],
            ])),
            'https://tracker.metro-mobilia.com/api/jarvis/projects/delivery-progress?*' => Http::response($this->progress(502698, '(SME2) Equipment')),
        ]);
        AnonymousAgent::fake([json_encode(['resource' => 'operation-masterlist', 'project_name' => $alias]), '{"summary":"Delivery progress is 0%."}'])->preventStrayPrompts();

        $result = app(WorkflowOperationsService::class)->ask($workflow->owner_key, $question);

        $this->assertTrue($result['success'], $result['error'] ?? '');
        $this->assertSame(502698, $result['data']['project']['project_id']);
        $this->assertSame('projects/delivery-progress', $result['meta']['resource']);
        $this->assertSame(0, $result['data']['delivery_progress_percent']);
        Http::assertSentCount(2);
        AnonymousAgent::assertPromptedTimes(2);
    }

    /** @return array<string, array{string, string}> */
    public static function monitoringQuestions(): array
    {
        return [
            'status' => ['What is the status of SME 2?', 'SME 2'],
            'warehouse readiness' => ['Show SME2 warehouse readiness.', 'SME2'],
            'completed deliveries' => ['How many SME 2 deliveries are completed?', 'SME 2'],
        ];
    }

    private function assertLookupQueryIsSmallAndUnfiltered(): void
    {
        foreach (Http::recorded(fn (Request $request): bool => parse_url($request->url(), PHP_URL_PATH) === '/api/jarvis/projects') as [$request]) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);
            $keys = array_keys($query);
            sort($keys);
            $this->assertSame(['page', 'per_page', 'search'], $keys);
            $this->assertSame('1', $query['page']);
            $this->assertSame('10', $query['per_page']);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $projects
     * @return array{success: bool, data: list<array<string, mixed>>, meta: array{pagination: array{has_more: bool}}}
     */
    private function searchResult(array $projects): array
    {
        return ['success' => true, 'data' => $projects, 'meta' => ['pagination' => ['has_more' => false]]];
    }

    /** @return array{success: bool, data: array{projects: list<array<string, mixed>>}, meta: array{filters: array{project_id: int}, definitions: array<string, string>}} */
    private function progress(int $projectId, string $projectName): array
    {
        return ['success' => true, 'data' => ['projects' => [[
            'project_id' => $projectId, 'project_name' => $projectName, 'project_status' => 'For Contract Signing',
            'warehouse_readiness' => ['by_unit' => [['unit' => 'pc', 'percent' => 59.36], ['unit' => 'set', 'percent' => 42]]],
            'operational_pipeline' => ['delivered' => ['completed' => 0, 'total' => 251557, 'percent' => 0, 'unit' => 'package allocations']],
            'overall_progress' => null, 'total_deliveries_count' => 28649, 'total_package_allocations_count' => 251557,
            'delivery_progress_percent' => 0, 'billing_progress_percent' => null,
        ]]], 'meta' => ['filters' => ['project_id' => $projectId], 'definitions' => ['billing' => 'No groups means unknown.']]];
    }

    private function fakeDns(): void
    {
        $this->mock(WorkflowHttpService::class, function ($mock): void {
            foreach (['projects', 'projects/delivery-progress'] as $path) {
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
        $connection = ApiConnection::factory()->create(['owner_key' => 'user:'.$user->id, 'base_url' => 'https://tracker.metro-mobilia.com', 'token' => 'project-test-secret']);

        return JarvisWorkflow::query()->create(['owner_key' => 'user:'.$user->id, 'name' => 'Operations', 'status' => 'draft', 'workflow_data' => [
            'nodes' => [
                ['id' => 'router', 'type' => 'orchestrator', 'position' => ['x' => 0, 'y' => 0], 'config' => []],
                ['id' => 'ops', 'type' => 'ai_agent', 'position' => ['x' => 100, 'y' => 0], 'config' => ['specialization' => 'operations', 'instruction' => 'Report actual Tracker data.']],
                ['id' => 'tracker', 'type' => 'app_action', 'position' => ['x' => 200, 'y' => 0], 'config' => ['app' => 'custom_api', 'action' => 'request', 'values' => [
                    'connection_id' => $connection->id, 'url' => '/api/jarvis/operation-masterlist', 'method' => 'GET',
                    'query' => ['year' => 2026, 'active_only' => 1, 'status' => 'Active', 'project_id' => 999],
                ]]],
            ], 'edges' => [['source' => 'router', 'target' => 'ops'], ['source' => 'ops', 'target' => 'tracker']],
        ]]);
    }
}
