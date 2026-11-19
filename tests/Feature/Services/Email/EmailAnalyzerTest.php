<?php

namespace Tests\Feature\Services\Email;

use App\Models\JarvisSetting;
use App\Services\Email\EmailAnalyzer;
use App\Services\Email\EmailMessage;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Ai\AnonymousAgent;
use Tests\TestCase;

class EmailAnalyzerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_normalized_promotion_is_analyzed_and_suppressed(): void
    {
        JarvisSetting::current()->update(['allowed_actions' => ['analysis']]);
        AnonymousAgent::fake([
            json_encode([
                'notification_required' => false,
                'classification' => 'PROMOTIONAL',
                'priority' => 'LOW',
                'requires_action' => false,
                'time_sensitive' => false,
                'business_impact' => false,
                'reason' => 'Marketing offer with no action required.',
                'action' => null,
                'deadline' => null,
                'summary' => null,
            ]),
        ]);

        $email = new EmailMessage(
            provider: 'imap',
            account: 'owner@example.com',
            messageId: 'pc-express-promo',
            threadId: null,
            from: 'offers@pcexpress.example',
            to: ['owner@example.com'],
            cc: [],
            subject: 'PC Express Alert: Lower Prices, Same Quality',
            body: 'Shop our weekly offers. No response is needed.',
            receivedAt: '2026-09-30T09:00:00+08:00',
            attachments: [['name' => 'offers.pdf', 'content_type' => 'application/pdf', 'size' => 1200]],
            isUnread: true,
            providerMessageId: 'imap-pc-express-promo',
        );

        $result = app(EmailAnalyzer::class)->analyze($email);

        $this->assertTrue($result['success']);
        $this->assertSame('PROMOTIONAL', $result['analysis']['classification']);
        $this->assertFalse($result['analysis']['notification_required']);
        $this->assertFalse($result['analysis']['requires_action']);
        $this->assertNull($result['analysis']['summary']);
        AnonymousAgent::assertPrompted(fn ($prompt): bool => $prompt->contains('PC Express Alert: Lower Prices, Same Quality')
            && $prompt->contains('Shop our weekly offers')
            && $prompt->contains('pc-express-promo')
            && $prompt->contains('offers.pdf (application/pdf)')
        );
    }

    public function test_actionable_service_upgrade_is_classified_with_a_concrete_action(): void
    {
        JarvisSetting::current()->update(['allowed_actions' => ['analysis']]);
        AnonymousAgent::fake([
            json_encode([
                'notification_required' => true,
                'classification' => 'ACTION_REQUIRED',
                'priority' => 'HIGH',
                'requires_action' => true,
                'time_sensitive' => true,
                'business_impact' => true,
                'reason' => 'Approval is required before a scheduled service change.',
                'action' => 'Review and approve the proposed upgrade.',
                'deadline' => '2026-10-02',
                'summary' => 'RISE INC. requests approval for a service upgrade by October 2.',
            ]),
        ]);

        $email = new EmailMessage(
            provider: 'imap',
            account: 'owner@example.com',
            messageId: 'rise-upgrade',
            threadId: 'rise-thread',
            from: 'marah@example.com',
            to: ['owner@example.com'],
            cc: [],
            subject: 'RISE INC. Service Upgrade',
            body: 'Please approve the service upgrade by October 2. Changes begin October 5.',
            receivedAt: '2026-09-30T09:15:00+08:00',
            attachments: [],
            isUnread: true,
            providerMessageId: 'imap-rise-upgrade',
        );

        $result = app(EmailAnalyzer::class)->analyze($email);

        $this->assertTrue($result['success']);
        $this->assertSame('ACTION_REQUIRED', $result['analysis']['classification']);
        $this->assertTrue($result['analysis']['notification_required']);
        $this->assertSame('Review and approve the proposed upgrade.', $result['analysis']['action']);
        $this->assertSame('2026-10-02', $result['analysis']['deadline']);
        AnonymousAgent::assertPrompted(fn ($prompt): bool => $prompt->contains('RISE INC. Service Upgrade')
            && $prompt->contains('Please approve the service upgrade by October 2')
            && $prompt->contains('From: marah@example.com')
        );
    }
}
