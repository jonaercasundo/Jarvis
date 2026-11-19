<?php

namespace App\Ai\Tools;

use App\Ai\Agents\JcAgent;
use App\Services\CapabilityRegistry;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class DescribeCapabilities implements Tool
{
    public function __construct(private JcAgent $agent) {}

    public function capabilityMetadata(): array
    {
        return ['category' => 'self_awareness', 'risk' => 'READ_ONLY', 'operations' => ['describe_capabilities'],
            'configuration' => null, 'requires_confirmation' => false];
    }

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Describe actual registered JARVIS tools, implemented workflow executors, UI-listed integrations, and owned saved-connection configuration status without executing operational tools or testing remote APIs. Configured does not mean connected or reachable. Use this first for capability questions.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        return json_encode(app(CapabilityRegistry::class)->discover($this->agent), JSON_THROW_ON_ERROR);
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
        ];
    }
}
