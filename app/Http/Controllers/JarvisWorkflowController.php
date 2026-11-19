<?php

namespace App\Http\Controllers;

use App\Exceptions\WorkflowHttpException;
use App\Models\ApiConnection;
use App\Models\EmailAccount;
use App\Services\JarvisWorkflowService;
use App\Services\Workflow\CustomApiWorkflowExecutor;
use App\Services\WorkflowAgentApproval;
use App\Services\WorkflowAgentStatusService;
use App\Services\WorkflowAgentTools;
use App\Services\WorkflowAiService;
use App\Services\WorkflowAppExecutorRegistry;
use App\Services\WorkflowDefinition;
use App\Services\WorkflowExecutor;
use App\Services\WorkflowGraph;
use App\Services\WorkflowNodeCatalog;
use App\Services\WorkflowOwner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class JarvisWorkflowController extends Controller
{
    public function index(): View
    {
        return view('jarvis.workflows');
    }

    public function data(Request $request, WorkflowOwner $owner, JarvisWorkflowService $workflows): JsonResponse
    {
        return response()->json(['success' => true, 'workflows' => $workflows->list($owner->key($request))]);
    }

    public function agentStatus(Request $request, WorkflowOwner $owner, WorkflowAgentStatusService $statuses): JsonResponse
    {
        $query = $request->query('agent');

        return response()->json([
            'success' => true,
            'agents' => $statuses->summaries($owner->key($request), is_string($query) ? $query : null),
        ]);
    }

    public function catalog(WorkflowNodeCatalog $catalog): JsonResponse
    {
        return response()->json(['success' => true, 'nodes' => $catalog->all()]);
    }

    public function connections(Request $request, WorkflowOwner $owner, WorkflowAiService $ai, WorkflowAgentTools $tools, WorkflowAppExecutorRegistry $executors): JsonResponse
    {
        $connections = EmailAccount::query()
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get()
            ->map(function (EmailAccount $account) {
                return [
                    'id' => $account->id,
                    'name' => $account->name,
                    'email' => $account->email,
                ];
            })
            ->values();

        $apiConnections = ApiConnection::query()->where('owner_key', $owner->key($request))->orderBy('name')->get();

        return response()->json([
            'success' => true,
            'ai_models' => $ai->models(),
            'app_capabilities' => $executors->capabilities(),
            'default_ai_model' => $ai->resolveModel(null)['model'],
            'connections' => $connections,
            'api_connections' => $apiConnections->map(function (ApiConnection $connection) use ($tools, $owner, $request): array {
                $metadata = $tools->describe(['id' => 'connection_'.$connection->id, '_owner_key' => $owner->key($request),
                    'config' => ['app' => $connection->integration === 'outlook' ? 'outlook' : 'custom_api', 'action' => $connection->integration === 'outlook' ? 'draft' : 'request', 'values' => ['connection_id' => $connection->id]]]);

                return [...$connection->toArray(), 'description' => $metadata['description'], 'capabilities' => array_values(array_column($metadata['actions'], 'description'))];
            }),
        ]);
    }

    public function testStep(Request $request, WorkflowOwner $owner, CustomApiWorkflowExecutor $executor, WorkflowExecutor $workflows): JsonResponse
    {
        $data = $request->validate([
            'node' => ['required', 'array'],
            'node.config' => ['required', 'array'],
            'node.config.app' => ['required', 'in:custom_api'],
            'context' => ['sometimes', 'array'],
        ]);
        try {
            $node = ['config' => $request->input('node.config')];
            app(WorkflowDefinition::class)->validate(['nodes' => [['id' => 'api_test', 'type' => 'app_action', 'position' => ['x' => 0, 'y' => 0], 'config' => $node['config']]], 'edges' => []]);
            $node['config'] = $workflows->resolveMappings($node['config'], $data['context'] ?? []);
            $node['_owner_key'] = $owner->key($request);

            return response()->json(['success' => true, ...$executor->execute($node)]);
        } catch (\RuntimeException $exception) {
            if ($exception instanceof WorkflowHttpException) {
                return response()->json(['success' => false, 'error' => $exception->getMessage(),
                    'diagnostic' => $exception->details['diagnostic'] ?? $exception->details,
                    ...$exception->details], 422);
            }

            return response()->json(['success' => false, 'error' => $exception->getMessage()], 422);
        }
    }

    public function store(Request $request, WorkflowOwner $owner, JarvisWorkflowService $workflows): JsonResponse
    {
        $data = $this->validateWorkflow($request);
        $workflow = $workflows->create(
            $owner->key($request),
            $data['name'],
            $data['description'] ?? null,
            $data['workflow_data'],
        );

        return response()->json(['success' => true, 'workflow' => $workflows->publicData($workflow)], 201);
    }

    public function show(Request $request, int $id, WorkflowOwner $owner, JarvisWorkflowService $workflows): JsonResponse
    {
        $workflow = $workflows->findOwned($owner->key($request), $id);

        return response()->json(['success' => true, 'workflow' => $workflows->publicData($workflow)]);
    }

    public function useWorkflow(Request $request, int $id, WorkflowOwner $owner, JarvisWorkflowService $workflows): JsonResponse
    {
        $workflow = $workflows->findOwned($owner->key($request), $id);
        $workflow = $workflows->markAsLastUsed($workflow);

        return response()->json(['success' => true, 'workflow' => $workflows->publicData($workflow)]);
    }

    public function update(Request $request, int $id, WorkflowOwner $owner, JarvisWorkflowService $workflows): JsonResponse
    {
        $workflow = $workflows->findOwned($owner->key($request), $id);
        $data = $this->validateWorkflow($request);
        $workflow = $workflows->update($workflow, $data['name'], $data['description'] ?? null, $data['workflow_data']);

        return response()->json(['success' => true, 'workflow' => $workflows->publicData($workflow)]);
    }

    public function destroy(Request $request, int $id, WorkflowOwner $owner, JarvisWorkflowService $workflows): JsonResponse
    {
        $workflow = $workflows->findOwned($owner->key($request), $id);
        $workflows->delete($workflow);

        return response()->json(['success' => true]);
    }

    public function approveAgentAction(Request $request, int $id, int $execution, WorkflowOwner $owner, JarvisWorkflowService $workflows, WorkflowAgentApproval $approvals): JsonResponse
    {
        $workflow = $workflows->findOwned($owner->key($request), $id);
        try {
            $result = $approvals->approve($workflow, $execution);

            return response()->json(['success' => $result['status'] === 'success', 'approval' => $result]);
        } catch (\RuntimeException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function test(
        Request $request,
        int $id,
        WorkflowOwner $owner,
        JarvisWorkflowService $workflows,
        WorkflowNodeCatalog $catalog,
        WorkflowExecutor $executor,
    ): JsonResponse {
        $workflow = $workflows->findOwned($owner->key($request), $id);
        $validated = $request->validate([
            'request' => ['nullable', 'string', 'max:10000'],
            'test_body' => ['nullable', 'string', 'max:10000'],
        ]);
        $requestText = trim((string) ($validated['request'] ?? $validated['test_body'] ?? 'Check my unread emails.'));
        $nodes = $workflow->workflow_data['nodes'] ?? [];
        $trigger = collect($nodes)->first(fn (array $node): bool => $catalog->isTrigger((string) ($node['type'] ?? '')));

        if ($trigger === null) {
            (new WorkflowGraph($workflow->workflow_data ?? []))->orchestrator();
            $triggerType = 'manual';
            $payload = ['request' => $requestText, 'body' => $requestText];
        } else {
            $triggerType = match ($trigger['type']) {
                'email_trigger' => 'email',
                'schedule_trigger' => 'schedule',
                'webhook_trigger' => 'webhook',
                'manual_trigger' => 'manual',
            };
            $payload = $this->samplePayload($triggerType, $requestText);
            $payload['request'] = $requestText;
        }

        $result = $executor->run($workflow, $payload, $triggerType, dryRun: true);

        return response()->json([
            'success' => $result['success'],
            'execution' => $this->executionData($result),
            'message' => ! $result['success']
                ? $result['error']
                : ($result['execution']->status === 'success_with_errors'
                    ? 'Test completed with errors. Failed steps are shown on the canvas; all external actions were simulated.'
                    : 'Test completed. All external actions were simulated.'),
        ], $result['success'] ? 200 : 422);
    }

    public function activate(Request $request, int $id, WorkflowOwner $owner, JarvisWorkflowService $workflows): JsonResponse
    {
        $workflow = $workflows->findOwned($owner->key($request), $id);
        $result = $workflows->activate($workflow);

        return response()->json([
            'success' => true,
            'workflow' => $workflows->publicData($result['workflow']),
            'webhook_url' => $result['webhook_url'],
        ]);
    }

    public function pause(Request $request, int $id, WorkflowOwner $owner, JarvisWorkflowService $workflows): JsonResponse
    {
        $workflow = $workflows->findOwned($owner->key($request), $id);

        return response()->json(['success' => true, 'workflow' => $workflows->publicData($workflows->pause($workflow))]);
    }

    public function executions(Request $request, int $id, WorkflowOwner $owner, JarvisWorkflowService $workflows): JsonResponse
    {
        $workflow = $workflows->findOwned($owner->key($request), $id);

        return response()->json(['success' => true, 'executions' => $workflows->executions($workflow)]);
    }

    public function rotateWebhook(Request $request, int $id, WorkflowOwner $owner, JarvisWorkflowService $workflows): JsonResponse
    {
        $workflow = $workflows->findOwned($owner->key($request), $id);
        $result = $workflows->rotateWebhookSecret($workflow);

        return response()->json(['success' => true, 'webhook_url' => $result['webhook_url']]);
    }

    public function propose(Request $request, WorkflowAiService $ai, WorkflowNodeCatalog $catalog, WorkflowDefinition $definition): JsonResponse
    {
        $validated = $request->validate(['prompt' => ['required', 'string', 'min:5', 'max:5000']]);
        $proposal = $ai->propose($validated['prompt'], $catalog);
        $proposalData = $proposal['workflow_data'] ?? null;

        if (! is_array($proposalData)) {
            return response()->json(['success' => false, 'error' => 'JARVIS did not return a usable workflow draft.'], 422);
        }

        $definition->validate($proposalData);

        return response()->json([
            'success' => true,
            'proposal' => [
                'name' => is_string($proposal['name'] ?? null) ? mb_substr($proposal['name'], 0, 120) : 'JARVIS proposal',
                'description' => is_string($proposal['description'] ?? null) ? mb_substr($proposal['description'], 0, 1000) : null,
                'workflow_data' => $proposalData,
            ],
        ]);
    }

    /** Legacy endpoint compatibility for older dashboard builds. */
    public function legacyShow(Request $request, WorkflowOwner $owner, JarvisWorkflowService $workflows): JsonResponse
    {
        return response()->json(['success' => true, 'workflows' => $workflows->list($owner->key($request))]);
    }

    private function validateWorkflow(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:1', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'workflow_data' => ['required', 'array'],
            'workflow_data.nodes' => ['present', 'array', 'max:30'],
            'workflow_data.edges' => ['present', 'array', 'max:60'],
        ]);

        app(WorkflowDefinition::class)->validate($validated['workflow_data']);

        return $validated;
    }

    private function samplePayload(string $triggerType, string $body): array
    {
        if ($triggerType === 'manual') {
            $decoded = json_decode($body, true);

            return is_array($decoded) ? $decoded : ['text' => $body];
        }

        return match ($triggerType) {
            'email' => [
                'uid' => 'test-email',
                'from' => 'customer@example.com',
                'from_name' => 'Test Customer',
                'to' => 'you@example.com',
                'subject' => 'Workflow test email',
                'date' => now()->toIso8601String(),
                'body' => $body !== '' ? $body : 'This is sample email content for a dry run.',
            ],
            'schedule' => ['scheduled_at' => now()->toIso8601String(), 'body' => $body],
            default => [
                'body' => $body !== '' ? json_decode($body, true) ?? ['text' => $body] : ['test' => true],
                'headers' => ['content-type' => 'application/json'],
                'received_at' => now()->toIso8601String(),
            ],
        };
    }

    private function executionData(array $result): array
    {
        $execution = $result['execution'];

        return [
            'id' => $execution->id,
            'status' => $execution->status,
            'started_at' => $execution->started_at?->toIso8601String(),
            'finished_at' => $execution->finished_at?->toIso8601String(),
            'error_message' => $execution->error_message,
            'input_data' => $execution->input_data ?? [],

            // Keep the format expected by the workflow UI.
            'output_data' => [
                'nodes' => $result['results'] ?? [],
            ],

            // Keep results for API compatibility.
            'results' => $result['results'] ?? [],
        ];
    }
}
