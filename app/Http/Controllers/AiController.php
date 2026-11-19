<?php

namespace App\Http\Controllers;

use App\Ai\Agents\JcAgent;
use App\Models\JarvisSetting;
use App\Services\CapabilityResponder;
use App\Services\ConversationIntentResolver;
use App\Services\ConversationResponsePresenter;
use App\Services\GoDaddyEmailService;
use App\Services\WorkflowAgentStatusService;
use App\Services\WorkflowOwner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiController extends Controller
{
    public function chat(
        Request $request,
        GoDaddyEmailService $emailService,
        WorkflowAgentStatusService $agentStatusService,
        WorkflowOwner $workflowOwner,
        ConversationResponsePresenter $presenter,
    ): JsonResponse {
        try {
            $request->validate([
                'message' => ['required', 'string', 'max:2000'],
                'conversation_id' => ['nullable', 'string', 'max:36'],
                'clarification_id' => ['nullable', 'uuid'],
            ]);
            $resolver = app(ConversationIntentResolver::class);
            $resolution = $resolver->resolve($request, trim($request->message));
            $message = $resolution['command'];
            $request->attributes->set('jarvis_resolved_command', $message);
            if ($resolution['response'] !== null) {
                $data = ['conversation_id' => $request->input('conversation_id'), ...$resolution['response']];
                $resolver->rememberResult($request, $data);

                return response()->json($presenter->present($data, $request->attributes->get('jarvis_response_depth', 'concise')));
            }

            $capabilityResponse = $request->attributes->get('jarvis_explanation_only') ? null : app(CapabilityResponder::class)->respond($request, $message, $emailService);
            if ($capabilityResponse !== null) {
                $resolver->rememberResult($request, $capabilityResponse);

                return response()->json($presenter->present($capabilityResponse, $request->attributes->get('jarvis_response_depth', 'concise')));
            }

            $settings = JarvisSetting::current();
            $agent = new JcAgent(
                $emailService,
                $settings->ai_name ?: 'JC',
                $settings->learned_memory ?? '',
                $agentStatusService,
                $workflowOwner->key($request),
                userPrompt: $message,
                settings: $settings,
                responseDepth: $request->attributes->get('jarvis_response_depth', 'concise'),
                taskContext: $request->attributes->get('jarvis_task_context', []),
                explanationOnly: (bool) $request->attributes->get('jarvis_explanation_only', false),
            );

            if ($request->filled('conversation_id')) {
                $response = $agent
                    ->continue(
                        $request->conversation_id,
                        as: $request->user()
                    )
                    ->prompt($message);
            } else {
                $response = $agent
                    ->forUser($request->user())
                    ->prompt($message);
            }

            $data = [
                'success' => true,
                'response' => $response->text,
                'conversation_id' => $response->conversationId,
                'wake_name_variants' => JarvisSetting::current()->wake_name_variants ?? [],
            ];
            if ($question = $request->attributes->get('jarvis_agent_clarification')) {
                $data = [...$data, ...$question];
            }
            $resolver->rememberResult($request, $data);

            return response()->json($presenter->present($data, $request->attributes->get('jarvis_response_depth', 'concise')));

        } catch (\Throwable $e) {
            return response()->json($presenter->present([
                'success' => false,
                'error' => $e->getMessage(),
            ]), 500);
        }
    }
}
