<?php

namespace App\Services;

use App\Contracts\Workflows\WorkflowAppExecutor;
use App\Services\Workflow\CustomApiWorkflowExecutor;
use App\Services\Workflow\GmailWorkflowExecutor;
use App\Services\Workflow\OutlookWorkflowExecutor;
use RuntimeException;

class WorkflowAppExecutorRegistry
{
    /** @var array<string, WorkflowAppExecutor> */
    private array $executors;

    public function __construct(GmailWorkflowExecutor $gmail, CustomApiWorkflowExecutor $customApi, OutlookWorkflowExecutor $outlook)
    {
        $this->executors = [$gmail->app() => $gmail, $customApi->app() => $customApi, $outlook->app() => $outlook];
    }

    /** @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    public function execute(array $node, bool $dryRun = false): array
    {
        $app = strtolower(trim((string) ($node['config']['app'] ?? '')));
        $executor = $this->executors[$app] ?? null;

        if (! $executor) {
            throw new RuntimeException("The connected app '{$app}' is not configured for workflow execution.");
        }

        return $executor->execute($node, $dryRun);
    }

    public function register(WorkflowAppExecutor $executor): void
    {
        $this->executors[$executor->app()] = $executor;
    }

    /** Metadata comes from the registered executors, never from UI integration labels. */
    public function capabilities(): array
    {
        $capabilities = [];
        foreach ($this->executors as $app => $executor) {
            $capabilities[$app] = [...$executor->capabilities(), 'implementation' => get_class($executor)];
        }

        return $capabilities;
    }
}
