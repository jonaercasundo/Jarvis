<?php

namespace App\Http\Controllers;

use App\Services\JarvisWorkflowService;
use App\Services\WorkflowAiService;
use App\Services\WorkflowDefinition;
use App\Services\WorkflowExecutor;
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

    public function catalog(WorkflowNodeCatalog $catalog): JsonResponse
    {
        return response()->json(['success' => true, 'nodes' => $catalog->all()]);
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
            'test_body' => ['nullable', 'string', 'max:10000'],
        ]);
        $trigger = $workflows->triggerNode($workflow);
        $triggerType = match ($trigger['type']) {
            'email_trigger' => 'email',
            'schedule_trigger' => 'schedule',
            'webhook_trigger' => 'webhook',
        };
        $payload = $this->samplePayload($triggerType, (string) ($validated['test_body'] ?? ''));
        $result = $executor->run($workflow, $payload, $triggerType, dryRun: true);

        return response()->json([
            'success' => $result['success'],
            'execution' => $this->executionData($result),
            'message' => $result['success'] ? 'Test finished. External actions were simulated.' : $result['error'],
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
            'results' => $result['results'],
        ];
    }
}
