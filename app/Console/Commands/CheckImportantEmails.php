<?php

namespace App\Console\Commands;

use App\Models\EmailAnalysis;
use App\Services\Email\EmailAnalyzer;
use App\Services\Email\EmailManager;
use App\Services\Email\EmailNotificationDecision;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class CheckImportantEmails extends Command
{
    protected $signature = 'jarvis:check-important-emails';

    protected $description = 'Classify unread emails and notify about messages needing attention';

    public function handle(
        EmailManager $emailManager,
        EmailAnalyzer $analyzer,
        EmailNotificationDecision $notificationDecision,
    ): int {
        try {
            $provider = $emailManager->provider();
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if (! $provider->isConfigured()) {
            $this->info('Email provider is not configured; skipping important-email check.');

            return self::SUCCESS;
        }

        try {
            $emails = $provider->getUnreadMessages(config('jarvis.importance.poll_limit', 15));
        } catch (Throwable $exception) {
            Log::error('JARVIS could not retrieve unread email', [
                'provider' => $provider->providerName(),
                'account' => $provider->accountAddress(),
                'exception' => $exception,
            ]);
            $this->error('Could not retrieve unread email.');

            return self::FAILURE;
        }

        foreach ($emails as $email) {
            if (! $email->isUnread) {
                continue;
            }

            if (EmailAnalysis::query()
                ->where('provider', $email->provider)
                ->where('account', $email->account)
                ->where('message_id', $email->messageId)
                ->exists()) {
                continue;
            }

            $analysisResult = $analyzer->analyze($email);

            if (! ($analysisResult['success'] ?? false)) {
                Log::warning('JARVIS unread email classification failed', [
                    'provider' => $email->provider,
                    'account' => $email->account,
                    'message_id' => $email->messageId,
                    'error' => $analysisResult['error'] ?? 'unknown error',
                ]);

                continue;
            }

            $analysis = $analysisResult['analysis'];
            $notificationRequired = $notificationDecision->shouldNotify($analysis);

            EmailAnalysis::query()->create([
                'provider' => $email->provider,
                'account' => $email->account,
                'message_id' => $email->messageId,
                'thread_id' => $email->threadId,
                'from_address' => $email->from,
                'subject' => $email->subject,
                'classification' => $analysis['classification'],
                'priority' => $analysis['priority'],
                'requires_action' => $analysis['requires_action'],
                'time_sensitive' => $analysis['time_sensitive'],
                'business_impact' => $analysis['business_impact'],
                'notification_required' => $notificationRequired,
                'reason' => $analysis['reason'],
                'action' => $notificationRequired ? $analysis['action'] : null,
                'deadline' => $notificationRequired ? $analysis['deadline'] : null,
                'summary' => $notificationRequired ? $analysis['summary'] : null,
                'analyzed_at' => now(),
            ]);

            Log::info('JARVIS classified unread email', [
                'provider' => $email->provider,
                'account' => $email->account,
                'message_id' => $email->messageId,
                'classification' => $analysis['classification'],
                'priority' => $analysis['priority'],
                'requires_action' => $analysis['requires_action'],
                'time_sensitive' => $analysis['time_sensitive'],
                'business_impact' => $analysis['business_impact'],
                'notification_required' => $notificationRequired,
                'reason' => $analysis['reason'],
            ]);
        }

        $this->info('Unread email classification complete.');

        return self::SUCCESS;
    }
}
