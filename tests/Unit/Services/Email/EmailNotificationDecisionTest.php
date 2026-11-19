<?php

namespace Tests\Unit\Services\Email;

use App\Services\Email\EmailNotificationDecision;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class EmailNotificationDecisionTest extends TestCase
{
    #[DataProvider('classificationCases')]
    public function test_only_important_actionable_messages_are_selected_for_notification(array $analysis, bool $expected): void
    {
        $this->assertSame($expected, (new EmailNotificationDecision)->shouldNotify($analysis));
    }

    public static function classificationCases(): array
    {
        return [
            'promotion' => [['notification_required' => false, 'classification' => 'PROMOTIONAL', 'priority' => 'LOW'], false],
            'newsletter' => [['notification_required' => false, 'classification' => 'NEWSLETTER', 'priority' => 'LOW'], false],
            'automated' => [['notification_required' => false, 'classification' => 'AUTOMATED', 'priority' => 'LOW'], false],
            'spam' => [['notification_required' => false, 'classification' => 'SPAM_OR_IRRELEVANT', 'priority' => 'LOW'], false],
            'action required' => [['notification_required' => true, 'classification' => 'ACTION_REQUIRED', 'priority' => 'HIGH', 'requires_action' => true], true],
            'critical is always notified' => [['notification_required' => false, 'classification' => 'CRITICAL', 'priority' => 'CRITICAL'], true],
            'important business impact' => [['notification_required' => true, 'classification' => 'IMPORTANT', 'priority' => 'MEDIUM', 'business_impact' => true], true],
            'model request without impact' => [['notification_required' => true, 'classification' => 'FYI', 'priority' => 'LOW'], false],
        ];
    }
}
