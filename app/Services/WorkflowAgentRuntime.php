<?php

namespace App\Services;

use App\Exceptions\WorkflowHttpException;
use App\Models\ApiConnection;
use Illuminate\Support\Arr;
use RuntimeException;
use Throwable;

class WorkflowAgentRuntime
{
    public const DEFAULT_PERMISSIONS = ['read' => true, 'analyze' => true, 'report' => true, 'draft' => true, 'write' => false, 'send' => false, 'delete' => false];

    public function __construct(private WorkflowAiService $ai, private WorkflowAgentTools $tools) {}

    /**
     * Execute one bounded reasoning loop; the callback persists and executes only validated tool calls.
     *
     * @param  array<string, mixed>  $agent
     * @param  array<int, array<string, mixed>>  $apps
     * @param  callable(array<string, mixed>): array<string, mixed>  $execute
     * @return array{result: array<string, mixed>, trace: array<string, mixed>, tools: array<int, array<string, mixed>>}
     */
    public function run(array $agent, array $apps, string $request, callable $execute): array
    {
        $config = $agent['config'] ?? [];
        if (isset($agent['_owner_key'])) {
            $this->ai->forOwner($agent['_owner_key']);
        }
        $model = $this->ai->resolveModel($config['model'] ?? null);
        $permissions = array_replace(self::DEFAULT_PERMISSIONS, $config['permissions'] ?? []);
        if (! $permissions['analyze']) {
            throw new RuntimeException('Autonomous reasoning requires the Analyze and compare data permission.');
        }
        $secrets = ApiConnection::query()->where('owner_key', $agent['_owner_key'] ?? '')->get()->pluck('token')->filter()->all();
        $secrets[] = $this->ai->settings()->apiKey();
        $catalog = array_map(fn (array $app): array => $this->tools->describe($app), $apps);
        $context = $this->tools->sanitize([
            'agent' => ['name' => $agent['label'] ?? 'AI Agent', 'specialization' => ($config['specialization'] ?? 'custom') === 'custom' ? (($config['custom_specialization'] ?? '') ?: 'custom') : $config['specialization'], 'responsibilities' => $config['instruction'] ?? ''],
            'request' => $request, 'permissions' => $permissions, 'require_confirmation' => $config['require_approval'] ?? true,
            'available_tools' => $catalog, 'output_fields' => $config['fields'] ?? ['summary'], 'tool_results' => [],
        ], $secrets);
        $trace = ['request' => $context['request'], 'selected_agent' => $context['agent']['name'], 'model' => $model['model'], 'model_fallback' => $model['fallback'],
            'available_tools' => array_map(fn (array $tool): array => ['tool_id' => $tool['tool_id'], 'name' => $tool['name'], 'status' => 'Not needed'], $catalog), 'decisions' => []];
        $seen = [];
        $limit = max(1, min(12, (int) config('jarvis.ai.max_tool_iterations', 8)));
        for ($iteration = 0; $iteration <= $limit; $iteration++) {
            $context['remaining_tool_calls'] = $limit - $iteration;
            $decision = $this->tools->sanitize($this->ai->nextAgentAction($context, $model['model']), $secrets);
            $resolution = $this->ai->lastModelResolution();
            if ($resolution && ($resolution['model'] !== $model['model'] || $resolution['fallback'])) {
                $model = $resolution;
                $trace['model'] = $model['model'];
                $trace['model_fallback'] = $model['fallback'];
            }
            if (($decision['kind'] ?? null) === 'final') {
                $considerations = $decision['tool_considerations'] ?? [];
                $considerations = array_values(array_filter(is_array($considerations) ? $considerations : [],
                    fn (mixed $tool): bool => is_array($tool) && in_array($tool['tool_id'] ?? null, array_column($catalog, 'tool_id'), true) && is_bool($tool['relevant'] ?? null) && is_string($tool['reason'] ?? null) && trim($tool['reason']) !== ''));
                $unconsidered = array_diff(array_column($catalog, 'tool_id'), array_column($context['tool_results'], 'tool_id'), array_column($considerations, 'tool_id'));
                if ($unconsidered !== [] && $iteration < $limit) {
                    $context['feedback'] = 'Before finalizing, consider every uncalled available tool and return tool_considerations with its relevance and a brief reason. Do not claim data is unavailable without considering the connected sources.';

                    continue;
                }
                if ($unconsidered !== []) {
                    break;
                }
                $uncalledRelevant = collect(is_array($considerations) ? $considerations : [])->contains(fn (mixed $tool): bool => is_array($tool) && ($tool['relevant'] ?? false) === true && ! in_array($tool['tool_id'] ?? null, array_column($context['tool_results'], 'tool_id'), true));
                if ($uncalledRelevant && $iteration < $limit) {
                    $context['feedback'] = 'You identified a relevant connected tool that has not been called. Retrieve its data before finalizing, or explicitly explain why it cannot be used.';

                    continue;
                }
                if (! is_array($decision['result'] ?? null)) {
                    throw new RuntimeException('The Agent returned an invalid final result.');
                }
                if (! $permissions['report']) {
                    unset($decision['result']['artifact'], $decision['result']['report']);
                }
                $trace['final_response'] = $this->preview($decision['result']);
                $trace['tool_considerations'] = $considerations;

                return ['result' => $decision['result'], 'trace' => $trace, 'tools' => array_map($this->preview(...), $context['tool_results'])];
            }
            if (($decision['kind'] ?? null) !== 'tool' || ! is_string($decision['tool_id'] ?? null) || ! is_string($decision['action'] ?? null) || ! is_array($decision['arguments'] ?? null) || ! is_string($decision['reason'] ?? null)) {
                throw new RuntimeException('The Agent returned an invalid tool decision.');
            }
            if ($iteration === $limit) {
                break;
            }
            $app = collect($apps)->first(fn (array $app): bool => $app['id'] === $decision['tool_id']);
            if (! $app) {
                throw new RuntimeException('The Agent selected a tool that is not connected to it.');
            }
            $prepared = $this->tools->prepare($app, $decision['action'], $decision['arguments']);
            $permission = $this->tools->permission($app, $decision['action']);
            $identity = $prepared['config'];
            if (($identity['app'] ?? '') === 'custom_api') {
                $identity = ['app' => 'custom_api', 'values' => Arr::only($identity['values'] ?? [], ['connection_id', 'connection', 'method', 'url', 'query', 'body', 'headers'])];
            }
            $signature = hash('sha256', json_encode($this->canonicalize($identity), JSON_THROW_ON_ERROR));
            if (isset($seen[$signature])) {
                $output = ['status' => 'duplicate_request_blocked', 'message' => 'This exact request has already been attempted. Use another action or narrower parameters.'];
            } elseif (($permissions[$permission] ?? false) !== true) {
                $output = ['status' => 'permission_denied', 'message' => 'Agent permission is disabled: '.$permission.'. No action was executed.'];
            } elseif ($this->tools->changesRecords($app, $decision['action']) && ($config['require_approval'] ?? true)) {
                $output = ['status' => 'confirmation_required', 'message' => 'Review and approve this specific action before sending or changing records. No action was executed.',
                    'proposed_action' => ['tool_id' => $app['id'], 'action' => $decision['action'], 'arguments' => $decision['arguments']]];
            } else {
                $seen[$signature] = true;
                try {
                    $raw = $execute($prepared);
                    $insufficient = ($raw['error'] ?? null) === 'response_too_large' || data_get($raw, 'pagination.has_more') === true;
                    $output = ['status' => $insufficient ? 'insufficient_information' : (($raw['success'] ?? true) === false ? 'request_failed' : ($this->emptyResult($raw) ? 'no_matching_records' : 'valid_data')), 'data' => $raw];
                } catch (Throwable $exception) {
                    $status = $exception instanceof WorkflowHttpException ? ($exception->details['status'] ?? null) : null;
                    $output = ['status' => in_array($status, [401, 403], true) ? 'authentication_failed' : ($exception instanceof WorkflowHttpException && isset($exception->details['error_type']) ? 'connection_failed' : 'request_failed'),
                        'message' => $exception->getMessage()];
                }
            }
            $result = $this->tools->sanitize(['tool_id' => $app['id'], 'action' => $decision['action'], 'reason' => mb_substr($decision['reason'], 0, 300), ...$output], $secrets);
            $context['tool_results'][] = $result;
            $trace['decisions'][] = $this->preview($result);
            foreach ($trace['available_tools'] as &$tool) {
                if ($tool['tool_id'] === $app['id']) {
                    $tool['status'] = $result['status'];
                }
            }
            unset($tool);
            if ($result['status'] === 'confirmation_required') {
                $final = ['summary' => 'Approval is required before this action can send or change anything.', 'pending_action' => $result['proposed_action']];
                $trace['final_response'] = $this->preview($final);

                return ['result' => $final, 'trace' => $trace, 'tools' => array_map($this->preview(...), $context['tool_results'])];
            }
        }

        $final = ['summary' => 'The Agent reached its tool-use limit. Review the execution trace for retrieved data and any blocked actions.'];
        $trace['final_response'] = $final;

        return ['result' => $final, 'trace' => $trace, 'tools' => array_map($this->preview(...), $context['tool_results'])];
    }

    private function preview(array $result): array
    {
        $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (strlen($json) <= 2000) {
            return $result;
        }

        return [...Arr::only($result, ['tool_id', 'action', 'reason', 'status', 'message']),
            'truncated' => true, 'preview' => mb_strcut($json, 0, 2000, 'UTF-8')];
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map($this->canonicalize(...), $value);
    }

    /** Apply the same policy to fixed actions; model output and user task text cannot grant approval. */
    public function assertFixedActionAllowed(array $agent, array $app): void
    {
        $permission = $this->tools->permission($app, 'configured');
        $permissions = array_replace(self::DEFAULT_PERMISSIONS, $agent['config']['permissions'] ?? []);
        if (($permissions[$permission] ?? false) !== true) {
            throw new RuntimeException('Agent permission is disabled: '.$permission.'.');
        }
        if ($this->tools->changesRecords($app, 'configured') && ($agent['config']['require_approval'] ?? true)) {
            throw new RuntimeException('This action requires confirmation. No action was executed.');
        }
    }

    private function emptyResult(array $result): bool
    {
        $data = $result['response'] ?? $result['result'] ?? null;
        foreach (['data.projects', 'data.rows', 'data.records', 'data.items', 'data.results', 'results', 'value'] as $path) {
            if (is_array($data) && Arr::has($data, $path) && data_get($data, $path) === []) {
                return true;
            }
        }

        return $data === [] || ($result['count'] ?? null) === 0 || (is_array($data) && array_key_exists('data', $data) && $data['data'] === []);
    }
}
