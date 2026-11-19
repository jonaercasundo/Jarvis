<?php

namespace App\Ai\Tools;

use App\Services\ArtifactPresentationPlanner;
use App\Services\WorkflowAgentStatusService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class GetAgentStatus implements Tool
{
    public function capabilityMetadata(): array
    {
        return ['category' => 'workflows', 'risk' => 'READ_ONLY', 'operations' => ['workflow_agent_status'],
            'configuration' => null, 'requires_confirmation' => false];
    }

    public function __construct(
        private WorkflowAgentStatusService $statusService,
        private string $ownerKey,
        private ?ArtifactPresentationPlanner $artifactPlanner = null,
    ) {}

    public function description(): Stringable|string
    {
        return 'Check the configured workflow Agents, their current status, today’s real task totals, recent errors, and the apps they own. Use this when the user asks what an Agent is doing or how an Agent or department is performing. Never invent missing activity or totals.';
    }

    public function handle(Request $request): Stringable|string
    {
        $agents = $this->statusService->summaries($this->ownerKey, $request['agent'] ?? null);
        $this->artifactPlanner?->addAgentStatus($agents);

        return json_encode([
            'agents' => $agents,
            'message' => $agents === [] ? 'No matching workflow Agents are configured.' : null,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'agent' => $schema->string()
                ->description('Optional Agent name or specialization to filter by. Leave blank to return all configured Agents.')
                ->nullable(),
        ];
    }
}
