<?php

namespace App\Http\Controllers;

use App\Jobs\ExecuteJarvisWorkflow;
use App\Models\JarvisWorkflow;
use App\Services\JarvisWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JarvisWorkflowWebhookController extends Controller
{
    public function __invoke(Request $request, int $workflowId, string $token): JsonResponse
    {
        $workflow = JarvisWorkflow::query()->find($workflowId);
        if (! $workflow || $workflow->status !== 'active' || ! is_string($workflow->webhook_secret) || ! hash_equals($workflow->webhook_secret, $token)) {
            return response()->json(['success' => false, 'error' => 'Webhook not found.'], 404);
        }

        $rawBody = $request->getContent();
        if (strlen($rawBody) > 100_000) {
            return response()->json(['success' => false, 'error' => 'Webhook payload exceeds 100 KB.'], 413);
        }

        $data = json_decode($rawBody, true);
        if (json_last_error() !== JSON_ERROR_NONE || (! is_array($data))) {
            return response()->json(['success' => false, 'error' => 'Send a valid JSON object or array.'], 422);
        }

        try {
            $trigger = app(JarvisWorkflowService::class)->triggerNode($workflow);
        } catch (\Throwable) {
            return response()->json(['success' => false, 'error' => 'Webhook not found.'], 404);
        }

        if (($trigger['type'] ?? null) !== 'webhook_trigger') {
            return response()->json(['success' => false, 'error' => 'Webhook not found.'], 404);
        }

        $headers = [];
        foreach (['content-type', 'user-agent', 'x-event-type'] as $name) {
            if ($value = $request->header($name)) {
                $headers[$name] = mb_substr($value, 0, 500);
            }
        }

        ExecuteJarvisWorkflow::dispatch(
            $workflow->id,
            $workflow->owner_key,
            'webhook',
            ['body' => $data, 'headers' => $headers, 'received_at' => now()->toIso8601String()],
        );

        return response()->json(['success' => true, 'message' => 'Workflow queued.'], 202);
    }
}
