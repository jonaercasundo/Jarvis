<?php

namespace Tests\Feature\Http;

use App\Models\EmailAnalysis;
use App\Services\Email\EmailManager;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Mockery;
use Tests\TestCase;

class EmailNotificationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_notification_endpoint_delivers_only_unnotified_important_analyses_once(): void
    {
        $emailManager = Mockery::mock(EmailManager::class);
        $emailManager->shouldReceive('isConfigured')->times(3)->andReturn(true);
        $this->app->instance(EmailManager::class, $emailManager);

        $importantEmail = EmailAnalysis::query()->create($this->analysisAttributes(
            messageId: 'important-message',
            notificationRequired: true,
            classification: 'ACTION_REQUIRED',
            priority: 'HIGH',
        ));

        $ignoredEmails = collect(range(1, 9))->map(fn (int $number): EmailAnalysis => EmailAnalysis::query()->create(
            $this->analysisAttributes(
                messageId: "ignored-message-{$number}",
                notificationRequired: false,
                classification: $number === 1 ? 'PROMOTIONAL' : 'AUTOMATED',
                priority: 'LOW',
            ),
        ));

        $firstResponse = $this->getJson('/jarvis/notification');

        $firstResponse->assertOk()
            ->assertJsonCount(1, 'notifications')
            ->assertJsonPath('notifications.0.from', 'marah@example.com')
            ->assertJsonPath('notifications.0.subject', 'RISE INC. Service Upgrade');
        $notificationToken = $firstResponse->json('notifications.0.token');
        $this->assertNotNull($notificationToken);
        $this->assertNull($importantEmail->fresh()->notified_at);
        $this->assertSame(0, EmailAnalysis::query()->whereIn('id', $ignoredEmails->pluck('id'))->whereNotNull('notified_at')->count());

        $secondResponse = $this->getJson('/jarvis/notification');

        $secondResponse->assertOk()
            ->assertJsonCount(0, 'notifications')
            ->assertJsonMissingPath('notifications.0');
        $this->assertNull($importantEmail->fresh()->notified_at);

        $this->postJson('/jarvis/notification/ack', ['tokens' => [$notificationToken]])
            ->assertOk()
            ->assertJsonPath('acknowledged', 1);
        $this->assertNotNull($importantEmail->fresh()->notified_at);

        $this->postJson('/jarvis/notification/ack', ['tokens' => [$notificationToken]])
            ->assertOk()
            ->assertJsonPath('acknowledged', 0);

        $thirdResponse = $this->getJson('/jarvis/notification');

        $thirdResponse->assertOk()->assertJsonCount(0, 'notifications');
    }

    /** @return array<string, mixed> */
    private function analysisAttributes(
        string $messageId,
        bool $notificationRequired,
        string $classification,
        string $priority,
    ): array {
        return [
            'provider' => 'imap',
            'account' => 'owner@example.com',
            'message_id' => $messageId,
            'thread_id' => null,
            'from_address' => $notificationRequired ? 'marah@example.com' : 'notifications@example.com',
            'subject' => $notificationRequired ? 'RISE INC. Service Upgrade' : 'Routine update',
            'classification' => $classification,
            'priority' => $priority,
            'requires_action' => $notificationRequired,
            'time_sensitive' => false,
            'business_impact' => $notificationRequired,
            'notification_required' => $notificationRequired,
            'reason' => $notificationRequired ? 'Review the service upgrade details.' : 'No action is needed.',
            'action' => $notificationRequired ? 'Review the upgrade details.' : null,
            'deadline' => null,
            'summary' => $notificationRequired ? 'A service change requires review.' : null,
            'analyzed_at' => now(),
        ];
    }
}
