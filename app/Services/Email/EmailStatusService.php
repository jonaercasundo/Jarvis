<?php

namespace App\Services\Email;

use App\Models\EmailAnalysis;

class EmailStatusService
{
    public function __construct(
        private EmailManager $emailManager,
        private EmailAnalyzer $emailAnalyzer,
        private EmailNotificationDecision $notificationDecision,
    ) {}

    /** @return array<string, mixed> */
    public function summarize(int $limit = 15): array
    {
        if (! $this->emailManager->isConfigured()) {
            return [
                'configured' => false,
                'overall_status' => 'NO_ACTION_REQUIRED',
                'total_unread' => 0,
                'critical_count' => 0,
                'action_required_count' => 0,
                'important_count' => 0,
                'ignored_count' => 0,
                'unanalyzed_count' => 0,
                'items' => [],
                'summary' => 'Your email account is not set up yet. Add it in Settings under Email.',
            ];
        }

        $totalUnread = $this->emailManager->getUnreadCount();
        $emails = $this->emailManager->getUnreadEmails($limit);
        $items = [];
        $criticalCount = 0;
        $actionRequiredCount = 0;
        $importantCount = 0;
        $unanalyzedCount = 0;
        $analyzedCount = 0;
        $nonIgnoredCount = 0;

        foreach ($emails as $email) {
            if (! $email->isUnread) {
                continue;
            }

            $analysisResult = $this->emailAnalyzer->analyze($email);

            if (! ($analysisResult['success'] ?? false)) {
                $unanalyzedCount++;

                continue;
            }

            $analysis = $analysisResult['analysis'];
            $analyzedCount++;
            $category = strtoupper((string) $analysis['classification']);
            $priority = strtoupper((string) $analysis['priority']);
            $requiresNotification = $this->notificationDecision->shouldNotify($analysis);
            $nonIgnoredCount += (int) ! in_array($category, ['TEST', 'IGNORE', 'SPAM_OR_IRRELEVANT'], true);

            EmailAnalysis::query()->updateOrCreate(
                [
                    'provider' => $email->provider,
                    'account' => $email->account,
                    'message_id' => $email->messageId,
                ],
                [
                    'thread_id' => $email->threadId,
                    'from_address' => $email->from,
                    'subject' => $email->subject,
                    'classification' => $category,
                    'priority' => $priority,
                    'requires_action' => $analysis['requires_action'],
                    'time_sensitive' => $analysis['time_sensitive'],
                    'business_impact' => $analysis['business_impact'],
                    'notification_required' => $requiresNotification,
                    'reason' => $analysis['reason'],
                    'action' => $analysis['action'],
                    'deadline' => $analysis['deadline'],
                    'summary' => $analysis['summary'],
                    'analyzed_at' => now(),
                ],
            );

            $isCritical = $category === 'CRITICAL' || $priority === 'CRITICAL';
            $isActionRequired = ! $isCritical && $category === 'ACTION_REQUIRED';
            $isImportant = ! $isCritical && ! $isActionRequired && $category === 'IMPORTANT';

            $criticalCount += (int) $isCritical;
            $actionRequiredCount += (int) $isActionRequired;
            $importantCount += (int) $isImportant;

            if ($isCritical || $isActionRequired || $isImportant) {
                $items[] = [
                    'message_id' => $email->messageId,
                    'uid' => ctype_digit($email->providerMessageId) ? $email->providerMessageId : null,
                    'from' => $email->from,
                    'date' => $email->receivedAt,
                    'subject' => $email->subject ?: '(No subject)',
                    'category' => $category,
                    'priority' => $priority,
                    'reason' => $analysis['reason'],
                    'action' => $analysis['action'],
                    'deadline' => $analysis['deadline'],
                ];
            }
        }

        $retrievedUnread = count(array_filter($emails, fn (EmailMessage $email): bool => $email->isUnread));
        $notAnalyzedCount = max(0, $totalUnread - $retrievedUnread) + $unanalyzedCount;
        $relevantCount = $criticalCount + $actionRequiredCount + $importantCount;
        $ignoredCount = max(0, $analyzedCount - $relevantCount);
        $overallStatus = $this->overallStatus(
            $totalUnread,
            $criticalCount,
            $actionRequiredCount,
            $importantCount,
            $analyzedCount,
            $notAnalyzedCount,
            $nonIgnoredCount,
        );

        return [
            'configured' => true,
            'overall_status' => $overallStatus,
            'total_unread' => $totalUnread,
            'critical_count' => $criticalCount,
            'action_required_count' => $actionRequiredCount,
            'important_count' => $importantCount,
            'ignored_count' => $ignoredCount,
            'unanalyzed_count' => $unanalyzedCount,
            'analyzed_count' => $analyzedCount,
            'not_analyzed_count' => $notAnalyzedCount,
            'items' => array_slice($items, 0, 5),
            'summary' => $this->buildSummary(
                $overallStatus,
                $totalUnread,
                $relevantCount,
                $ignoredCount,
                $items,
                $criticalCount,
                $notAnalyzedCount,
                $nonIgnoredCount,
                $analyzedCount,
            ),
        ];
    }

    private function overallStatus(
        int $totalUnread,
        int $criticalCount,
        int $actionRequiredCount,
        int $importantCount,
        int $analyzedItemCount,
        int $unanalyzedCount,
        int $nonIgnoredCount,
    ): string {
        if ($criticalCount > 0) {
            return 'URGENT';
        }

        if ($actionRequiredCount > 0 || $importantCount > 0) {
            return 'NEEDS_ATTENTION';
        }

        if ($unanalyzedCount > 0 && $analyzedItemCount === 0) {
            return 'NEEDS_ATTENTION';
        }

        if ($totalUnread === 0 || ($nonIgnoredCount === 0 && $unanalyzedCount === 0 && $totalUnread === $analyzedItemCount)) {
            return 'NO_ACTION_REQUIRED';
        }

        return 'NORMAL';
    }

    /** @param array<int, array<string, mixed>> $items */
    private function buildSummary(
        string $overallStatus,
        int $totalUnread,
        int $relevantCount,
        int $ignoredCount,
        array $items,
        int $criticalCount,
        int $notAnalyzedCount,
        int $nonIgnoredCount,
        int $analyzedCount,
    ): string {
        if ($totalUnread === 0) {
            return 'Your email status is NO ACTION REQUIRED. You have no unread emails.';
        }

        $summary = sprintf(
            'Your email status is %s. You have %d unread email%s.',
            str_replace('_', ' ', $overallStatus),
            $totalUnread,
            $totalUnread === 1 ? '' : 's',
        );

        if ($relevantCount > 0) {
            $summary .= sprintf(' %d may need your attention:', $relevantCount);

            foreach (array_slice($items, 0, 5) as $item) {
                $detail = $item['action'] ?: $item['reason'];
                $summary .= "\n• {$item['subject']} — {$detail}";
            }
        } elseif ($notAnalyzedCount > 0) {
            $summary .= ' I could not determine whether your unread emails need attention.';
        } elseif ($overallStatus === 'NO_ACTION_REQUIRED' || $nonIgnoredCount === 0) {
            $summary .= ' Everything can safely be ignored.';
        } else {
            $summary .= ' Nothing appears to require your attention.';
        }

        if ($ignoredCount > 0 && $relevantCount > 0 && $notAnalyzedCount === 0) {
            $summary .= sprintf(
                ' The remaining %d appear informational, automated, promotional, or otherwise low priority.',
                $ignoredCount,
            );
        }

        if ($criticalCount === 0 && $relevantCount > 0) {
            $summary .= ' Nothing appears immediately urgent.';
        }

        if ($notAnalyzedCount > 0) {
            $summary .= sprintf(' I could not analyze %d unread email%s, so this summary may be incomplete.', $notAnalyzedCount, $notAnalyzedCount === 1 ? '' : 's');
        }

        return $summary;
    }
}
