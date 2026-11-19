<?php

namespace App\Ai\Tools;

use App\Models\JarvisSetting;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class RememberUserPreference implements Tool
{
    public function capabilityMetadata(): array
    {
        return ['category' => 'preferences', 'risk' => 'WRITE', 'operations' => ['remember', 'forget'], 'intent_verbs' => ['remember', 'forget', 'save', 'pronounce'],
            'configuration' => null, 'requires_confirmation' => false];
    }

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Save or remove a user-approved preference for future conversations, or save/remove words that speech recognition may hear instead of the configured AI name.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $settings = JarvisSetting::current();
        $memories = collect(preg_split('/\R+/', $settings->learned_memory ?? '') ?: [])
            ->map(fn (string $memory): string => trim(preg_replace('/^[-*]\s*/', '', $memory)))
            ->filter()
            ->values();
        $addedMemory = false;
        $removedMemory = false;

        foreach (($request['forget_memory'] ?? []) as $memoryToForget) {
            $normalizedMemoryToForget = mb_strtolower(trim($memoryToForget));
            $filtered = $memories->reject(fn (string $memory): bool => mb_strtolower($memory) === $normalizedMemoryToForget);
            $removedMemory = $removedMemory || $filtered->count() !== $memories->count();
            $memories = $filtered->values();
        }

        $memory = trim($request['memory'] ?? '');
        if ($memory !== '') {
            $memory = Str::limit($memory, 300, '');
            $alreadyRemembered = $memories->contains(fn (string $existing): bool => mb_strtolower($existing) === mb_strtolower($memory));

            if (! $alreadyRemembered) {
                $memories->push($memory);
                $addedMemory = true;
            }
        }

        $variants = collect($settings->wake_name_variants ?? [])
            ->map(fn (string $variant): string => Str::squish($variant))
            ->filter()
            ->values();
        $addedVariant = false;
        $removedVariant = false;

        foreach (($request['forget_wake_name_variants'] ?? []) as $variantToForget) {
            $normalizedVariantToForget = mb_strtolower(Str::squish($variantToForget));
            $filtered = $variants->reject(fn (string $variant): bool => mb_strtolower($variant) === $normalizedVariantToForget);
            $removedVariant = $removedVariant || $filtered->count() !== $variants->count();
            $variants = $filtered->values();
        }

        foreach (($request['wake_name_variants'] ?? []) as $variant) {
            $variant = Str::limit(Str::squish($variant), 30, '');
            $normalizedVariant = mb_strtolower($variant);
            $configuredName = mb_strtolower(Str::squish($settings->ai_name ?? ''));

            if ($variant === '' || $normalizedVariant === $configuredName) {
                continue;
            }

            $alreadyRemembered = $variants->contains(fn (string $existing): bool => mb_strtolower($existing) === $normalizedVariant);
            if (! $alreadyRemembered && $variants->count() < 12) {
                $variants->push($variant);
                $addedVariant = true;
            }
        }

        $settings->learned_memory = $memories->take(-20)->implode(PHP_EOL);
        $settings->wake_name_variants = $variants->all();
        $settings->save();

        $changes = [];
        if ($addedMemory) {
            $changes[] = 'preference remembered';
        }
        if ($removedMemory) {
            $changes[] = 'preference forgotten';
        }
        if ($addedVariant) {
            $changes[] = 'wake-name pronunciation variation remembered';
        }
        if ($removedVariant) {
            $changes[] = 'wake-name pronunciation variation forgotten';
        }

        return $changes === []
            ? 'No new memory changes were needed.'
            : 'Updated memory: '.implode(', ', $changes).'.';
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'memory' => $schema->string()->description('One concise, stable, non-sensitive preference or fact the user explicitly asked you to remember.')->max(500)->nullable(),
            'forget_memory' => $schema->array()->description('Exact saved memory notes the user asked to forget.')->items($schema->string())->max(20),
            'wake_name_variants' => $schema->array()->description('Alternate words speech recognition may produce for the configured AI name, only when the user explicitly corrects or teaches the pronunciation.')->items($schema->string()->max(30))->max(12),
            'forget_wake_name_variants' => $schema->array()->description('Exact saved wake-name variants the user asked to forget.')->items($schema->string()->max(30))->max(12),
        ];
    }
}
