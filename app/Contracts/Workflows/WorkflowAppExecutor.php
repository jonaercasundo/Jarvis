<?php

namespace App\Contracts\Workflows;

interface WorkflowAppExecutor
{
    public function app(): string;

    /** @return array<string, mixed> */
    public function capabilities(): array;

    /** @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    public function execute(array $node, bool $dryRun = false): array;
}
