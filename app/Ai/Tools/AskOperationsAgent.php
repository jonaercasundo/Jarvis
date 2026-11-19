<?php

namespace App\Ai\Tools;

use App\Services\ArtifactPresentationPlanner;
use App\Services\ConversationIntentResolver;
use App\Services\WorkflowOperationsService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class AskOperationsAgent implements Tool
{
    public function __construct(private WorkflowOperationsService $operations, private string $ownerKey, private string $userPrompt, private ?ArtifactPresentationPlanner $planner = null) {}

    public function capabilityMetadata(): array
    {
        return ['category' => 'data', 'risk' => 'READ_ONLY', 'operations' => ['operations_data_read'],
            'configuration' => 'operations_workflow', 'permissions' => ['analysis'], 'requires_confirmation' => false,
            ...$this->operations->capability($this->ownerKey)];
    }

    public function description(): Stringable|string
    {
        return 'Delegate real project progress, deliveries, inventory, lots, packages, warehouses, or item masterlist questions to the Operations Agent. Use MMC Tracker for raw records and compact project delivery summaries; prefer suitable configured Metabase questions or dashboards for analytics and BI reporting. Named projects are resolved with a filtered project search before reading their data. Progress, warehouse package counts and billed delivery questions use GET /api/jarvis/projects/delivery-progress?project_id=<verified_project_id>. Project delivery summaries use GET /api/jarvis/projects/delivery-progress?per_page=20&page=1; report pagination and scope comparisons to returned projects. Individual DR records use deliveries with project/status/date filters and pagination. Never read the entire deliveries dataset for summary questions. Use this same returned data for optional charts without a second Tracker read. Tracker reads use GET; Metabase may execute an existing question with POST. No record-changing endpoints are allowed. Ask for scope before reading all deliveries. Oversized results include narrowing suggestions; never repeat the same oversized request. Never use for email, small talk, capability explanations, or changing records. Report unavailable data honestly.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        if (! $this->operations->supportsRequest($this->userPrompt)) {
            return json_encode(['success' => false, 'source' => 'MMC Tracker', 'data' => null, 'error' => 'This request does not authorize reading operations data.']);
        }

        $conversationRequest = app(\Illuminate\Http\Request::class);
        $resolvedProject = $conversationRequest->attributes->get('jarvis_operations_project');
        $result = $this->operations->ask(
            $this->ownerKey,
            $this->userPrompt,
            dryRun: $this->operations->isSimulationRequest($this->userPrompt),
            resolvedProject: is_array($resolvedProject) ? $resolvedProject : null,
        );
        if (($result['error_type'] ?? null) === 'ambiguous_project' && $conversationRequest->hasSession()) {
            $reference = $result['reference'] ?? null;
            $options = [];
            foreach (array_slice($result['candidates'] ?? [], 0, 6) as $candidate) {
                $value = is_string($reference) && $reference !== ''
                    ? str_ireplace($reference, 'project ID '.$candidate['project_id'], $this->userPrompt)
                    : 'Show project status for project ID '.$candidate['project_id'];
                $options[] = ['label' => Str::limit($candidate['project_name'], 32).' (#'.$candidate['project_id'].')', 'value' => $value];
            }
            app(ConversationIntentResolver::class)->askFromAgent($conversationRequest, 'Which matching Tracker project do you mean?', $options);
        }
        if (($result['success'] ?? false) && ! ($result['simulated'] ?? false) && is_array($result['data'] ?? null)) {
            if (data_get($result, 'meta.connection_verified') === true && is_array(data_get($result, 'meta.resolved_project'))) {
                $conversationRequest->attributes->set('jarvis_resolved_operations_project', data_get($result, 'meta.resolved_project'));
            }
            $this->planner?->rememberRetrievedData($result['data'], $result['source'] ?? 'MMC Tracker', data_get($result, 'artifact.source.retrieved_at'));
            if (is_array($result['artifact'] ?? null)) {
                $this->planner?->addVisualReport($result['artifact']);
            }
        }

        return json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'request' => $schema->string()->description('The user’s operations question, including the project name and context. Do not supply URLs, credentials, or invented project IDs.')->required(),
        ];
    }
}
