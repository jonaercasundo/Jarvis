<?php

namespace App\Services;

use App\Models\ApiConnection;
use App\Models\EmailAccount;
use App\Models\JarvisSetting;
use App\Models\JarvisWorkflow;
use App\Services\Workflow\WorkflowEmailReader;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class JarvisWorkflowService
{
    public function __construct(
        private WorkflowDefinition $definition,
        private GoDaddyEmailService $emailService,
        private WorkflowHttpService $http,
        private WorkflowEmailReader $workflowEmailReader,
        private WorkflowAppExecutorRegistry $appExecutors,
    ) {}

    public function list(string $ownerKey): array
    {
        return JarvisWorkflow::query()
            ->where('owner_key', $ownerKey)
            ->orderByDesc('last_used_at')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (JarvisWorkflow $workflow) => $this->publicData($workflow))
            ->all();
    }

    public function create(string $ownerKey, string $name, ?string $description, array $data): JarvisWorkflow
    {
        $this->definition->validate($data);

        return JarvisWorkflow::create([
            'owner_key' => $ownerKey,
            'name' => trim($name),
            'description' => $description,
            'workflow_data' => $data,
            'trigger_state' => [],
            'last_used_at' => now(),
            'status' => 'draft',
        ]);
    }

    public function update(JarvisWorkflow $workflow, string $name, ?string $description, array $data): JarvisWorkflow
    {
        $this->definition->validate($data);

        $workflow->update([
            'name' => trim($name),
            'description' => $description,
            'workflow_data' => $data,
            'last_used_at' => now(),
            'status' => 'draft',
            'webhook_secret' => null,
            'next_run_at' => null,
        ]);

        return $workflow->fresh();
    }

    public function markAsLastUsed(JarvisWorkflow $workflow): JarvisWorkflow
    {
        JarvisWorkflow::withoutTimestamps(function () use ($workflow): void {
            $workflow->last_used_at = now();
            $workflow->save();
        });

        return $workflow->fresh();
    }

    public function activate(JarvisWorkflow $workflow): array
    {
        if ($workflow->status === 'active') {
            throw ValidationException::withMessages(['workflow' => 'This workflow is already active. Pause it before activating again.']);
        }
        if (in_array($workflow->status, ['error', 'invalid'], true)) {
            throw ValidationException::withMessages(['workflow' => 'Save the workflow again before activating it.']);
        }

        $data = $workflow->workflow_data ?? [];
        $this->definition->validate($data, forActivation: true);
        $graph = new WorkflowGraph($data);
        $analysisEnabled = in_array('analysis', JarvisSetting::current()->allowed_actions ?? [], true);

        $trigger = $this->triggerNode($workflow);
        $state = $workflow->trigger_state ?? [];
        $secret = null;

        foreach ($data['nodes'] as $node) {
            if (($node['type'] ?? null) === 'app_action') {
                $config = $node['config'] ?? [];
                $app = strtolower((string) ($config['app'] ?? ''));
                $action = strtolower((string) ($config['action'] ?? ''));
                $supportedGmailRead = $app === 'gmail' && in_array($action, ['read', 'list_unread', 'list unread'], true);
                $supportedEmailSend = in_array($app, ['gmail', 'email', 'outlook'], true)
                    && in_array($action, ['send_email', 'send email', 'send'], true);
                $supportedLiveAction = in_array($action, $this->appExecutors->capabilities()[$app]['actions'] ?? [], true);

                if (! $supportedGmailRead && ! $supportedEmailSend && ! $supportedLiveAction) {
                    throw ValidationException::withMessages(['workflow' => 'This app action has no live connector yet. Test runs use a mock connector; activation is blocked until a real connector is configured.']);
                }
                if ($supportedEmailSend && ! $this->emailService->canSendNewEmails()) {
                    throw ValidationException::withMessages(['workflow' => 'Enable “Send new emails” in JARVIS Settings → Scope before activating this workflow.']);
                }
            }
            if (($node['type'] ?? null) === 'send_email' && ! $this->emailService->canSendNewEmails()) {
                throw ValidationException::withMessages(['workflow' => 'Enable “Send new emails” in JARVIS Settings → Scope before activating this workflow.']);
            }
            if (($node['type'] ?? null) === 'jarvis_ai' && ! in_array('analysis', JarvisSetting::current()->allowed_actions ?? [], true)) {
                throw ValidationException::withMessages(['workflow' => 'Enable AI analysis in JARVIS Settings → Scope before activating this workflow.']);
            }
            $requiresAgentReasoning = ($node['type'] ?? null) === 'ai_agent'
                && ($node['config']['execution_mode'] ?? 'ai_assisted') !== 'deterministic';
            $requiresOrchestratorRouting = ($node['type'] ?? null) === 'orchestrator'
                && count($graph->connectedAgents((string) $node['id'])) > 1;
            if (($requiresAgentReasoning || $requiresOrchestratorRouting) && ! $analysisEnabled) {
                throw ValidationException::withMessages(['workflow' => 'Enable AI analysis before activating this workflow.']);
            }
            if (collect($data['nodes'] ?? [])->contains(fn (array $candidate): bool => ($candidate['type'] ?? null) === 'orchestrator') && ($node['type'] ?? null) === 'app_action') {
                $config = $node['config'] ?? [];
                $app = strtolower((string) ($config['app'] ?? ''));
                $action = strtolower((string) ($config['action'] ?? ''));
                if (! in_array($action, $this->appExecutors->capabilities()[$app]['actions'] ?? [], true)) {
                    throw ValidationException::withMessages(['workflow' => 'This app action is not connected for workflow execution yet.']);
                }

                if (in_array($app, ['custom_api', 'outlook'], true)) {
                    $values = $config['values'] ?? [];
                    $connection = ApiConnection::query()->where('owner_key', $workflow->owner_key)
                        ->whereKey($values['connection_id'] ?? $values['connection'] ?? 0)->first();
                    if (! $connection && ($app !== 'outlook' || $action !== 'draft')) {
                        throw ValidationException::withMessages(['workflow' => 'Choose an owned saved connection for this app.']);
                    }
                    if ($app === 'outlook' && $connection && parse_url($connection->base_url, PHP_URL_HOST) !== 'graph.microsoft.com') {
                        throw ValidationException::withMessages(['workflow' => 'Choose a Microsoft Graph connection for Outlook.']);
                    }

                    continue;
                }

                $connectionId = $config['values']['connection'] ?? null;
                if ($connectionId !== null && $connectionId !== '') {
                    $account = is_numeric($connectionId)
                        ? EmailAccount::query()->whereKey((int) $connectionId)->where('is_active', true)->first()
                        : null;
                    if (! $account || ! $this->workflowEmailReader->isConfigured((int) $account->id)) {
                        throw ValidationException::withMessages(['workflow' => 'Choose an active, configured email mailbox for the Gmail step.']);
                    }
                } elseif (! $this->workflowEmailReader->isConfigured()) {
                    throw ValidationException::withMessages(['workflow' => 'Connect a default mailbox before activating this workflow.']);
                }
            }
            if (($node['type'] ?? null) === 'webhook_action') {
                try {
                    $this->http->validateUrl((string) ($node['config']['url'] ?? ''));
                } catch (\RuntimeException $exception) {
                    throw ValidationException::withMessages(['workflow' => $exception->getMessage()]);
                }
            }
        }

        if ($trigger['type'] === 'webhook_trigger') {
            $secret = Str::random(48);
            $workflow->webhook_secret = $secret;
        } elseif ($trigger['type'] === 'email_trigger') {
            if (! $this->emailService->isConfigured()) {
                throw ValidationException::withMessages(['workflow' => 'Connect a mailbox in Settings → Email before activating an email trigger.']);
            }

            $baseline = $this->emailService->latestEmails(50);
            if (! ($baseline['success'] ?? false)) {
                throw ValidationException::withMessages(['workflow' => $baseline['error'] ?? 'JARVIS could not read the mailbox. Check the email permissions in Settings.']);
            }

            $state['seen_uids'] = array_values(array_map(
                'strval',
                array_column($baseline['emails'] ?? [], 'uid'),
            ));
        }

        $workflow->update([
            'status' => 'active',
            'trigger_state' => $state,
            'next_run_at' => $trigger['type'] === 'schedule_trigger' ? $this->nextScheduleAt($trigger['config'] ?? []) : null,
            'webhook_secret' => $secret ?? $workflow->webhook_secret,
        ]);

        return [
            'workflow' => $workflow->fresh(),
            'webhook_url' => $secret ? $this->webhookUrl($workflow->fresh(), $secret) : null,
        ];
    }

    public function pause(JarvisWorkflow $workflow): JarvisWorkflow
    {
        $workflow->update([
            'status' => 'paused',
            'next_run_at' => null,
        ]);

        return $workflow->fresh();
    }

    public function rotateWebhookSecret(JarvisWorkflow $workflow): array
    {
        $trigger = $this->triggerNode($workflow);
        if (($trigger['type'] ?? null) !== 'webhook_trigger') {
            throw ValidationException::withMessages(['workflow' => 'Add a Webhook trigger to use a webhook URL.']);
        }

        if ($workflow->status !== 'active') {
            throw ValidationException::withMessages(['workflow' => 'Activate this workflow before creating its webhook URL.']);
        }

        $secret = Str::random(48);
        $workflow->update(['webhook_secret' => $secret]);

        return ['webhook_url' => $this->webhookUrl($workflow->fresh(), $secret)];
    }

    public function findOwned(string $ownerKey, int $id): JarvisWorkflow
    {
        return JarvisWorkflow::query()
            ->where('owner_key', $ownerKey)
            ->findOrFail($id);
    }

    public function findById(int $id): ?JarvisWorkflow
    {
        return JarvisWorkflow::query()->find($id);
    }

    public function delete(JarvisWorkflow $workflow): void
    {
        $workflow->delete();
    }

    public function executions(JarvisWorkflow $workflow, int $limit = 30): array
    {
        return $workflow->executions()
            ->latest('id')
            ->limit(min(max($limit, 1), 100))
            ->with('steps')
            ->get()
            ->map(fn ($execution) => [
                'id' => $execution->id,
                'trigger_type' => $execution->trigger_type,
                'status' => $execution->status,
                'started_at' => $execution->started_at?->toIso8601String(),
                'finished_at' => $execution->finished_at?->toIso8601String(),
                'input_data' => $execution->input_data ?? [],
                'output_data' => $execution->output_data ?? [],
                'error_message' => $execution->error_message,
                'steps' => $execution->steps->map(fn ($step) => [
                    'node_id' => $step->node_id,
                    'node_type' => $step->node_type,
                    'execution_order' => $step->execution_order,
                    'status' => $step->status,
                    'input_data' => $step->input_data ?? [],
                    'output_data' => $step->output_data ?? [],
                    'error_message' => $step->error_message,
                    'started_at' => $step->started_at?->toIso8601String(),
                    'finished_at' => $step->finished_at?->toIso8601String(),
                ])->all(),
            ])
            ->all();
    }

    public function publicData(JarvisWorkflow $workflow): array
    {
        return [
            'id' => $workflow->id,
            'name' => $workflow->name,
            'description' => $workflow->description,
            'workflow_data' => $workflow->workflow_data ?? ['nodes' => [], 'edges' => []],
            'status' => $workflow->status,
            'last_used_at' => $workflow->last_used_at?->toIso8601String(),
            'last_run_at' => $workflow->last_run_at?->toIso8601String(),
            'next_run_at' => $workflow->next_run_at?->toIso8601String(),
            'has_webhook' => filled($workflow->webhook_secret),
            'updated_at' => $workflow->updated_at?->toIso8601String(),
        ];
    }

    public function triggerNode(JarvisWorkflow $workflow): array
    {
        foreach ($workflow->workflow_data['nodes'] ?? [] as $node) {
            if (app(WorkflowNodeCatalog::class)->isTrigger($node['type'] ?? '')) {
                return $node;
            }
        }

        throw ValidationException::withMessages(['workflow' => 'Add a trigger to this workflow.']);
    }

    public function nextScheduleAt(array $config): Carbon
    {
        $timezone = $config['timezone'] ?? config('app.timezone', 'UTC');
        $now = Carbon::now($timezone)->startOfMinute();
        $frequency = $config['frequency'] ?? 'daily';

        if ($frequency === 'hourly') {
            $minute = (int) ($config['minute'] ?? 0);
            $next = $now->copy()->minute($minute)->second(0);
            if ($next->lessThanOrEqualTo($now)) {
                $next->addHour();
            }

            return $next;
        }

        [$hour, $minute] = array_map('intval', explode(':', $config['time'] ?? '09:00'));
        $next = $now->copy()->setTime($hour, $minute);

        if ($frequency === 'weekly') {
            $targetDay = $config['day'] ?? 'monday';
            for ($i = 0; $i < 8; $i++) {
                if ($next->isSameDay($now) && $next->lessThanOrEqualTo($now)) {
                    $next->addWeek();
                }
                if (strtolower($next->englishDayOfWeek) === $targetDay && $next->greaterThan($now)) {
                    return $next;
                }
                $next->addDay()->setTime($hour, $minute);
            }

            return $next;
        }

        if ($next->lessThanOrEqualTo($now)) {
            $next->addDay();
        }

        return $next;
    }

    private function webhookUrl(JarvisWorkflow $workflow, string $secret): string
    {
        return rtrim(config('app.url'), '/').'/api/jarvis/workflows/'.$workflow->id.'/webhook/'.$secret;
    }
}
