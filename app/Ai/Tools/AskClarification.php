<?php

namespace App\Ai\Tools;

use App\Services\ConversationIntentResolver;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class AskClarification implements Tool
{
    public function capabilityMetadata(): array
    {
        return ['category' => 'conversation', 'risk' => 'READ_ONLY', 'operations' => ['ask_clarification'], 'configuration' => null, 'requires_confirmation' => false];
    }

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Ask one short business-intent question only when an important required detail is missing. Offer up to six simple choices and allow a typed answer. Do not ask about presentation, repeat known context, or use clarification as action approval. Stop and wait after this tool.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $validated = $request->validate([
            'question' => ['required', 'string', 'max:200'],
            'options' => ['sometimes', 'array', 'max:6'],
            'options.*.label' => ['required', 'string', 'max:50'],
            'options.*.value' => ['required', 'string', 'max:100'],
        ]);

        return json_encode(app(ConversationIntentResolver::class)->askFromAgent(request(), $validated['question'], $validated['options'] ?? []), JSON_THROW_ON_ERROR);
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'question' => $schema->string()->description('One concise question about missing business intent or required task details.')->max(200)->required(),
            'options' => $schema->array()->max(6)->items($schema->object([
                'label' => $schema->string()->max(50)->required(),
                'value' => $schema->string()->max(100)->required(),
            ])),
        ];
    }
}
