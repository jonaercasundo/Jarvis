<?php

namespace App\Ai\Tools;

use App\Services\CapabilityExecutionPolicy;
use App\Services\WorkflowOperationsService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Laravel\Ai\Tools\ToolNameResolver;
use Stringable;

class PolicyControlledTool implements Tool
{
    public function __construct(private Tool $tool, private string $prompt, private string $preference, private array $allowedActions = []) {}

    public function name(): string
    {
        return ToolNameResolver::resolve($this->tool);
    }

    public function description(): Stringable|string
    {
        return $this->tool->description();
    }

    public function schema(JsonSchema $schema): array
    {
        return $this->tool->schema($schema);
    }

    public function handle(Request $request): Stringable|string
    {
        if (request()->attributes->has('jarvis_agent_clarification')) {
            return json_encode(['success' => false, 'error' => 'Wait for the user to answer the clarification before using another tool.']);
        }
        $metadata = method_exists($this->tool, 'capabilityMetadata') ? $this->tool->capabilityMetadata() : [];
        if (($metadata['category'] ?? null) === 'email' && app(WorkflowOperationsService::class)->supportsRequest($this->prompt)) {
            return json_encode(['success' => false, 'error' => 'This Operations request does not authorize email as a fallback.']);
        }
        if (array_diff($metadata['permissions'] ?? [], $this->allowedActions) !== []) {
            return json_encode(['success' => false, 'error' => 'This capability is disabled in Settings.']);
        }
        if (! app(CapabilityExecutionPolicy::class)->permits($metadata, $this->prompt, $this->preference)) {
            return json_encode(['success' => false, 'error' => 'This request permits capability explanation only. Demonstrations must follow the saved preference and cannot perform writes or external side effects.']);
        }

        return $this->tool->handle($request);
    }
}
