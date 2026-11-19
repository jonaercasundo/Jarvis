<?php

namespace App\Services\Email;

use App\Models\EmailAnalysis;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class EmailNotificationService
{
    /** @return array<int, array{token: string, provider: string, account: string, message_id: string, priority: string, from: string, subject: string, reason: string, action: ?string, deadline: ?string}> */
    public function deliverPending(int $limit = 10): array
    {
        return DB::transaction(function () use ($limit): array {
            $claimExpiredAt = now()->subMinutes(2);

            $pending = EmailAnalysis::query()
                ->where('notification_required', true)
                ->whereNull('notified_at')
                ->where(function ($query) use ($claimExpiredAt): void {
                    $query->whereNull('notification_claimed_at')
                        ->orWhere('notification_claimed_at', '<=', $claimExpiredAt);
                })
                ->oldest('analyzed_at')
                ->lockForUpdate()
                ->limit($limit)
                ->get();

            $notifications = [];

            foreach ($pending as $analysis) {
                $token = (string) Str::uuid();

                $analysis->update([
                    'notification_token' => $token,
                    'notification_claimed_at' => now(),
                ]);

                $notifications[] = [
                    'token' => $token,
                    'provider' => $analysis->provider,
                    'account' => $analysis->account,
                    'message_id' => $analysis->message_id,
                    'priority' => $analysis->priority,
                    'from' => $analysis->from_address ?: 'Unknown sender',
                    'subject' => $analysis->subject ?: '(No subject)',
                    'reason' => $analysis->reason,
                    'action' => $analysis->action,
                    'deadline' => $analysis->deadline,
                ];
            }

            return $notifications;
        });
    }

    /** @param string[] $tokens */
    public function acknowledgeDelivered(array $tokens): int
    {
        if ($tokens === []) {
            return 0;
        }

        return DB::transaction(function () use ($tokens): int {
            $analyses = EmailAnalysis::query()
                ->whereIn('notification_token', $tokens)
                ->where('notification_required', true)
                ->whereNull('notified_at')
                ->lockForUpdate()
                ->get();

            foreach ($analyses as $analysis) {
                $analysis->update([
                    'notified_at' => now(),
                    'notification_token' => null,
                    'notification_claimed_at' => null,
                ]);

                Log::info('JARVIS delivered email attention notification', [
                    'provider' => $analysis->provider,
                    'account' => $analysis->account,
                    'message_id' => $analysis->message_id,
                    'classification' => $analysis->classification,
                    'priority' => $analysis->priority,
                ]);
            }

            return $analyses->count();
        });
    }
}
