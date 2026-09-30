<?php

namespace App\Services;

use App\Jobs\ExecuteJarvisWorkflow;
use App\Models\JarvisWorkflow;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Throwable;

class WorkflowSchedulerService
{
    public function __construct(private JarvisWorkflowService $workflows) {}

    public function runDue(): void
    {
        $this->dispatchScheduled();
        $this->pollEmailTriggers();
    }

    private function dispatchScheduled(): void
    {
        JarvisWorkflow::query()
            ->where('status', 'active')
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', now())
            ->orderBy('next_run_at')
            ->chunkById(50, function ($workflows): void {
                foreach ($workflows as $candidate) {
                    $scheduledAt = null;

                    DB::transaction(function () use ($candidate, &$scheduledAt): void {
                        $workflow = JarvisWorkflow::query()->lockForUpdate()->find($candidate->id);
                        if (! $workflow || $workflow->status !== 'active' || ! $workflow->next_run_at || $workflow->next_run_at->isFuture()) {
                            return;
                        }

                        $trigger = $this->workflows->triggerNode($workflow);
                        if (($trigger['type'] ?? null) !== 'schedule_trigger') {
                            return;
                        }

                        $scheduledAt = $workflow->next_run_at->toIso8601String();
                        $workflow->update([
                            'next_run_at' => $this->workflows->nextScheduleAt($trigger['config'] ?? []),
                        ]);
                    });

                    if ($scheduledAt !== null) {
                        ExecuteJarvisWorkflow::dispatch(
                            $candidate->id,
                            $candidate->owner_key,
                            'schedule',
                            ['scheduled_at' => $scheduledAt],
                        )->afterCommit();
                    }
                }
            });
    }

    private function pollEmailTriggers(): void
    {
        JarvisWorkflow::query()
            ->where('status', 'active')
            ->orderBy('id')
            ->chunkById(50, function ($workflows): void {
                foreach ($workflows as $workflow) {
                    try {
                        $this->withOwner($workflow->owner_key, function () use ($workflow): void {
                            $fresh = JarvisWorkflow::query()->find($workflow->id);
                            if (! $fresh || $fresh->status !== 'active') {
                                return;
                            }

                            $trigger = $this->workflows->triggerNode($fresh);
                            if (($trigger['type'] ?? null) !== 'email_trigger') {
                                return;
                            }

                            $emailService = app(GoDaddyEmailService::class);
                            if (! $emailService->isConfigured()) {
                                return;
                            }

                            $recent = $emailService->latestEmails(50);
                            if (! ($recent['success'] ?? false)) {
                                return;
                            }

                            $seen = array_fill_keys(array_map('strval', $fresh->trigger_state['seen_uids'] ?? []), true);
                            $newSeen = array_keys($seen);
                            $config = $trigger['config'] ?? [];
                            $toDispatch = [];

                            foreach (array_reverse($recent['emails'] ?? []) as $summary) {
                                $uid = (string) ($summary['uid'] ?? '');
                                if ($uid === '' || isset($seen[$uid])) {
                                    continue;
                                }

                                if (! $this->matchesEmailFilters($summary, $config)) {
                                    $newSeen[] = $uid;
                                    $seen[$uid] = true;

                                    continue;
                                }

                                $emailResult = $emailService->getEmail((int) $uid);
                                if (! ($emailResult['success'] ?? false)) {
                                    continue;
                                }

                                $email = $emailResult['message'] ?? [];
                                $toDispatch[] = [
                                    'uid' => $uid,
                                    'payload' => [
                                        'uid' => $uid,
                                        'from' => $email['from'] ?? $summary['from'] ?? null,
                                        'from_name' => $email['from_name'] ?? $summary['from_name'] ?? null,
                                        'to' => $email['to'] ?? null,
                                        'subject' => $email['subject'] ?? $summary['subject'] ?? '(No subject)',
                                        'date' => $email['date'] ?? $summary['date'] ?? null,
                                        'body' => mb_substr((string) ($email['body'] ?? ''), 0, 30000),
                                    ],
                                ];
                            }

                            foreach ($toDispatch as $item) {
                                ExecuteJarvisWorkflow::dispatch(
                                    $fresh->id,
                                    $fresh->owner_key,
                                    'email',
                                    $item['payload'],
                                )->afterCommit();
                                $newSeen[] = $item['uid'];
                            }

                            $fresh->update([
                                'trigger_state' => array_merge($fresh->trigger_state ?? [], [
                                    'seen_uids' => array_slice(array_values(array_unique($newSeen)), -500),
                                ]),
                            ]);
                        });
                    } catch (Throwable $exception) {
                        report($exception);
                    }
                }
            });
    }

    private function matchesEmailFilters(array $email, array $config): bool
    {
        $fromFilter = mb_strtolower(trim((string) ($config['from'] ?? '')));
        $subjectFilter = mb_strtolower(trim((string) ($config['subject_contains'] ?? '')));

        if ($fromFilter !== '' && ! str_contains(mb_strtolower((string) ($email['from'] ?? '')), $fromFilter)) {
            return false;
        }

        if ($subjectFilter !== '' && ! str_contains(mb_strtolower((string) ($email['subject'] ?? '')), $subjectFilter)) {
            return false;
        }

        return true;
    }

    private function withOwner(string $ownerKey, callable $callback): void
    {
        $previousUserId = Auth::id();
        if (preg_match('/^user:(\d+)$/', $ownerKey, $matches)) {
            if (! Auth::loginUsingId((int) $matches[1])) {
                return;
            }
        } else {
            Auth::logout();
        }

        try {
            $callback();
        } finally {
            Auth::logout();
            if ($previousUserId) {
                Auth::loginUsingId($previousUserId);
            }
        }
    }
}
