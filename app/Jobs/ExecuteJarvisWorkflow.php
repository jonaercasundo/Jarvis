<?php

namespace App\Jobs;

use App\Models\JarvisWorkflow;
use App\Services\WorkflowExecutor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;

class ExecuteJarvisWorkflow implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $workflowId,
        public string $ownerKey,
        public string $triggerType,
        public array $payload,
    ) {}

    public function handle(WorkflowExecutor $executor): void
    {
        $previousUserId = Auth::id();

        if (preg_match('/^user:(\d+)$/', $this->ownerKey, $matches)) {
            if (! Auth::loginUsingId((int) $matches[1])) {
                return;
            }
        } else {
            Auth::logout();
        }

        try {
            $workflow = JarvisWorkflow::query()
                ->where('owner_key', $this->ownerKey)
                ->where('status', 'active')
                ->find($this->workflowId);

            if ($workflow) {
                $executor->run($workflow, $this->payload, $this->triggerType);
            }
        } finally {
            Auth::logout();
            if ($previousUserId) {
                Auth::loginUsingId($previousUserId);
            }
        }
    }
}
