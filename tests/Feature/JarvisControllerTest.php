<?php

namespace Tests\Feature;

use App\Models\JarvisSetting;
use App\Services\Email\EmailManager;
use App\Services\Email\EmailMessage;
use App\Services\GoDaddyEmailService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\AnonymousAgent;
use Mockery;
use Tests\TestCase;

class JarvisControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_email_status_request_analyzes_bodies_and_returns_a_summary_without_the_raw_list(): void
    {
        Http::fake(['api.frankfurter.app/*' => Http::failedConnection()]);
        $settings = JarvisSetting::current();
        $settings->update(['allowed_actions' => ['analysis']]);

        $emailManager = Mockery::mock(EmailManager::class);
        $emailManager->shouldReceive('isConfigured')->once()->andReturn(true);
        $emailManager->shouldReceive('getUnreadCount')->once()->andReturn(2);
        $emailManager->shouldReceive('getUnreadEmails')->once()->with(10)->andReturn([
            $this->email('rise-upgrade', 'RISE INC. Service Upgrade', 'Please review the proposed service change by Friday.', '42'),
            $this->email('pc-express', 'PC Express Alert: Lower Prices, Same Quality', 'Enjoy this week\'s sale. No response is needed.'),
        ]);
        $this->app->instance(EmailManager::class, $emailManager);
        $this->app->instance(GoDaddyEmailService::class, Mockery::mock(GoDaddyEmailService::class));

        AnonymousAgent::fake([
            json_encode([
                'notification_required' => true,
                'classification' => 'ACTION_REQUIRED',
                'priority' => 'HIGH',
                'requires_action' => true,
                'time_sensitive' => true,
                'business_impact' => true,
                'reason' => 'A service change needs review by Friday.',
                'action' => 'Review the proposed service change.',
                'deadline' => 'Friday',
                'summary' => 'Review the service change by Friday.',
            ]),
            json_encode([
                'notification_required' => false,
                'classification' => 'PROMOTIONAL',
                'priority' => 'LOW',
                'requires_action' => false,
                'time_sensitive' => false,
                'business_impact' => false,
                'reason' => 'This is a promotion with no action required.',
                'action' => null,
                'deadline' => null,
                'summary' => null,
            ]),
        ]);

        $response = $this->postJson('/jarvis/command', ['command' => 'Tell me the status of my email.']);

        $response->assertOk()
            ->assertJsonPath('type', 'email_status')
            ->assertJsonPath('overall_status', 'NEEDS_ATTENTION')
            ->assertJsonPath('total_unread', 2)
            ->assertJsonPath('critical_count', 0)
            ->assertJsonPath('action_required_count', 1)
            ->assertJsonPath('important_count', 0)
            ->assertJsonPath('ignored_count', 1)
            ->assertJsonPath('items.0.subject', 'RISE INC. Service Upgrade')
            ->assertJsonPath('display.items.0.uid', '42')
            ->assertJsonMissingPath('emails');

        $this->assertStringContainsString('Nothing appears immediately urgent.', $response->json('response'));
        $this->assertDatabaseCount('email_analyses', 2);
        $this->assertDatabaseHas('email_analyses', [
            'message_id' => 'rise-upgrade',
            'classification' => 'ACTION_REQUIRED',
            'notification_required' => true,
        ]);
        $this->assertDatabaseHas('email_analyses', [
            'message_id' => 'pc-express',
            'classification' => 'PROMOTIONAL',
            'notification_required' => false,
        ]);
        AnonymousAgent::assertPrompted(fn ($prompt): bool => $prompt->contains('Please review the proposed service change by Friday.'));
        AnonymousAgent::assertPrompted(fn ($prompt): bool => $prompt->contains('Enjoy this week'));
        Http::assertNothingSent();
    }

    public function test_explicit_unread_email_request_keeps_returning_the_message_list(): void
    {
        $emailService = Mockery::mock(GoDaddyEmailService::class);
        $emailService->shouldReceive('isConfigured')->once()->andReturn(true);
        $emailService->shouldReceive('unreadEmails')->once()->with(10)->andReturn([
            'success' => true,
            'emails' => [['subject' => 'Requested unread message']],
        ]);
        $this->app->instance(GoDaddyEmailService::class, $emailService);

        $response = $this->postJson('/jarvis/command', ['command' => 'Show my unread emails']);

        $response->assertOk()
            ->assertJsonPath('type', 'email')
            ->assertJsonCount(1, 'emails')
            ->assertJsonPath('emails.0.subject', 'Requested unread message');
    }

    private function email(string $messageId, string $subject, string $body, ?string $providerMessageId = null): EmailMessage
    {
        return new EmailMessage(
            provider: 'imap',
            account: 'owner@example.com',
            messageId: $messageId,
            threadId: null,
            from: 'sender@example.com',
            to: ['owner@example.com'],
            cc: [],
            subject: $subject,
            body: $body,
            receivedAt: '2026-09-30T09:00:00+08:00',
            attachments: [],
            isUnread: true,
            providerMessageId: $providerMessageId ?? $messageId,
        );
    }
}
