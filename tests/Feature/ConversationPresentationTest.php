<?php

namespace Tests\Feature;

use App\Ai\Agents\JcAgent;
use App\Models\JarvisSetting;
use App\Models\PendingEmailAction;
use App\Models\User;
use App\Services\ConversationResponsePresenter;
use App\Services\GoDaddyEmailService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Responses\Data\ToolCall;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ConversationPresentationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_capabilities_show_cards_with_a_short_separate_spoken_invitation(): void
    {
        JcAgent::fake();
        Http::preventStrayRequests();
        $this->postJson('/jarvis/command', ['command' => 'What can you do?'])->assertOk()
            ->assertJsonPath('display.type', 'capability_cards')->assertJsonPath('display.payload.summary', 'What do you want help with?')
            ->assertJsonPath('speech_text', 'Pick what you want help with, and I will show you.')->assertJsonMissingPath('capabilities');
        JcAgent::assertNeverPrompted();
        Http::assertNothingSent();
    }

    public function test_email_automation_explanation_shows_the_existing_flow_without_reading_each_step(): void
    {
        JcAgent::fake();
        Http::preventStrayRequests();
        $response = $this->postJson('/jarvis/command', ['command' => 'How can you automate email?'])->assertOk()
            ->assertJsonPath('display.type', 'visual_demo')->assertJsonPath('category', 'email');
        $this->assertNotEmpty($response->json('display.payload.steps'));
        $this->assertStringNotContainsString('Email arrives', $response->json('speech_text'));
        JcAgent::assertNeverPrompted();
        Http::assertNothingSent();
    }

    public function test_broad_analysis_shows_choices_and_speaks_only_the_question(): void
    {
        $this->postJson('/jarvis/command', ['command' => 'Analyze our data.'])->assertOk()
            ->assertJsonPath('display.type', 'clarification')->assertJsonPath('speech_text', 'What should I focus on?')
            ->assertJsonCount(4, 'display.payload.options')->assertJsonPath('display.payload.allow_custom_answer', true);
    }

    public function test_long_answers_show_a_short_result_with_complete_content_in_details(): void
    {
        JcAgent::fake(['Start with the most important task. Finish it before switching. Review your progress. Keep the plan simple. '.str_repeat('A longer explanation follows. ', 30)]);
        Http::preventStrayRequests();
        $response = $this->postJson('/jarvis/command', ['command' => 'Give me a productivity tip.'])->assertOk()
            ->assertJsonPath('display.type', 'conversation')->assertJsonPath('display.expanded', false)
            ->assertJsonPath('display.message', 'Start with the most important task. Finish it before switching. Review your progress. Keep the plan simple.')
            ->assertJsonPath('speech_text', 'Start with the most important task. Finish it before switching.');
        $this->assertSame($response->json('response'), $response->json('display.details'));
        Http::assertNothingSent();
    }

    public function test_email_previews_omit_bodies_and_do_not_read_subjects_or_addresses(): void
    {
        $email = Mockery::mock(GoDaddyEmailService::class);
        $email->shouldReceive('isConfigured')->once()->andReturn(true);
        $email->shouldReceive('unreadEmails')->once()->with(10)->andReturn(['success' => true, 'emails' => [
            ['uid' => 42, 'from' => 'sender@example.com', 'subject' => 'Service interruption', 'body' => 'Private full email body.'],
        ]]);
        $this->app->instance(GoDaddyEmailService::class, $email);
        $this->postJson('/jarvis/command', ['command' => 'Show my unread emails.'])->assertOk()
            ->assertJsonPath('display.type', 'email_preview')->assertJsonPath('display.items.0.uid', '42')
            ->assertJsonMissingPath('display.items.0.body')->assertJsonPath('speech_text', 'I found 1 message in the recent emails checked.');
    }

    public function test_missing_business_records_do_not_produce_sample_kpis(): void
    {
        Http::preventStrayRequests();
        $this->postJson('/jarvis/command', ['command' => 'How are our deliveries?'])->assertOk()
            ->assertJsonPath('display.type', 'status')->assertJsonMissingPath('display.metrics')->assertJsonMissingPath('display.artifact');
        Http::assertNothingSent();
    }

    public function test_forecast_artifacts_preserve_values_and_assumptions_without_reading_the_chart(): void
    {
        $response = app(ConversationResponsePresenter::class)->present(['success' => true, 'response' => 'Deliveries are improving.', 'artifacts' => [
            ['type' => 'chart', 'source' => 'Provided delivery history', 'data' => ['summary' => 'Next month may be slightly higher.',
                'series' => [['label' => 'September', 'value' => 100, 'kind' => 'actual'], ['label' => 'October', 'value' => 124, 'kind' => 'forecast']],
                'assumptions' => 'The estimate assumes the recent trend continues.']],
        ]]);
        $this->assertSame('chart', $response['display']['type']);
        $this->assertSame(124, $response['display']['artifact']['data']['series'][1]['value']);
        $this->assertSame('forecast', $response['display']['artifact']['data']['series'][1]['kind']);
        $this->assertSame('The estimate assumes the recent trend continues.', $response['display']['explanation']);
        $this->assertSame('Next month may be slightly higher.', $response['speech_text']);
    }

    #[DataProvider('voiceUnsafeContent')]
    public function test_code_urls_and_internal_metadata_stay_out_of_speech(string $content): void
    {
        $response = app(ConversationResponsePresenter::class)->present(['success' => true, 'response' => $content]);
        $this->assertDoesNotMatchRegularExpression('/[{}\[\]#*`\/()@]|FindEmails|CodeInspector|READ_ONLY|EXTERNAL_SIDE_EFFECT|Bearer|private-token/', $response['speech_text']);
        $this->assertLessThanOrEqual(280, mb_strlen($response['speech_text']));
        $this->assertStringNotContainsString('private-token', json_encode($response['display']));
    }

    public static function voiceUnsafeContent(): array
    {
        return [["```json\n{\"success\":true}\n```"], ['See https://example.com/api.'], ['FindEmails is READ_ONLY. CodeInspector returns source.'],
            ["**Your reply is ready.**\n- [Send]"], ['Authorization: Bearer private-token.'], ['Check app/Services/WorkflowExecutor.php (line 10).']];
    }

    public function test_technical_details_are_expanded_and_not_spoken(): void
    {
        $this->actingAs(User::factory()->create());
        $this->postJson('/jarvis/command', ['command' => 'What can you do?', 'conversation_id' => 'technical']);
        $this->postJson('/jarvis/command', ['command' => 'Explain technically.', 'conversation_id' => 'technical'])->assertOk()
            ->assertJsonPath('display.expanded', true)->assertJsonPath('speech_text', 'The technical details are on screen.');
    }

    public function test_draft_review_actions_use_the_existing_confirmation_and_cancel_without_sending(): void
    {
        $this->actingAs(User::factory()->create());
        JarvisSetting::current()->update(['allowed_actions' => ['send_emails']]);
        $email = Mockery::mock(GoDaddyEmailService::class);
        $email->shouldReceive('canSendNewEmails')->once()->andReturn(true);
        $email->shouldNotReceive('sendEmail', 'sendReply');
        $this->app->instance(GoDaddyEmailService::class, $email);
        JcAgent::fake([new ToolCall('stage', 'SendEmail', ['to' => 'carlo@example.com', 'subject' => 'Update', 'body' => 'Meeting tomorrow.']), 'Prepared for review.']);
        $draft = $this->postJson('/jarvis/command', ['command' => 'Send an email to Carlo saying Meeting tomorrow.'])->assertOk()
            ->assertJsonPath('display.type', 'confirmation')->assertJsonPath('speech_text', 'The email is ready. Want me to send it?')
            ->assertJsonPath('display.draft.body', 'Meeting tomorrow.');
        $token = $draft->json('display.draft.confirmation_code');
        $this->assertSame(['Send', 'Edit', 'Cancel'], array_column($draft->json('display.actions'), 'label'));
        $this->assertSame('confirm '.$token, $draft->json('display.actions.0.command'));
        $this->postJson('/jarvis/command', ['command' => 'cancel '.$token, 'conversation_id' => $draft->json('conversation_id')])->assertOk()
            ->assertJsonPath('response', 'Cancelled. The email has not been sent.');
        $this->assertDatabaseHas('pending_email_actions', ['token' => $token, 'status' => 'cancelled']);
    }

    public function test_cancel_cannot_cross_draft_review_owners_or_conversations(): void
    {
        $first = User::factory()->create();
        $this->actingAs(User::factory()->create());
        $pending = PendingEmailAction::createPending('send', '0', ['to' => 'owner@example.com', 'subject' => 'Subject', 'body' => 'Message']);
        $this->withSession(['jarvis_draft_reviews' => [$pending->token => ['owner_key' => 'user:'.$first->id, 'conversation_id' => 'original']]]);
        $this->postJson('/jarvis/command', ['command' => 'cancel '.$pending->token, 'conversation_id' => 'original'])->assertNotFound();
        $this->actingAs($first);
        $this->postJson('/jarvis/command', ['command' => 'cancel '.$pending->token, 'conversation_id' => 'different'])->assertNotFound();
        $this->assertDatabaseHas('pending_email_actions', ['token' => $pending->token, 'status' => 'pending']);
    }

    public function test_send_review_action_executes_only_after_the_existing_confirmation_command(): void
    {
        $this->actingAs(User::factory()->create());
        JarvisSetting::current()->update(['allowed_actions' => ['send_emails']]);
        $email = Mockery::mock(GoDaddyEmailService::class);
        $email->shouldReceive('canSendNewEmails')->once()->andReturn(true);
        $email->shouldReceive('sendEmail')->once()->with('carlo@example.com', 'Update', 'Meeting tomorrow.')
            ->andReturn(['success' => true, 'message' => 'Your email has been sent.']);
        $this->app->instance(GoDaddyEmailService::class, $email);
        JcAgent::fake([new ToolCall('stage', 'SendEmail', ['to' => 'carlo@example.com', 'subject' => 'Update', 'body' => 'Meeting tomorrow.']), 'Prepared for review.']);
        $draft = $this->postJson('/jarvis/command', ['command' => 'Send an email to Carlo saying Meeting tomorrow.'])->assertOk();
        $token = $draft->json('display.draft.confirmation_code');
        $this->assertDatabaseHas('pending_email_actions', ['token' => $token, 'status' => 'pending']);

        $this->postJson('/jarvis/command', ['command' => $draft->json('display.actions.0.command'), 'conversation_id' => $draft->json('conversation_id')])
            ->assertOk()->assertJsonPath('type', 'confirmation')->assertJsonPath('speech_text', 'Your email has been sent.');

        $this->assertDatabaseHas('pending_email_actions', ['token' => $token, 'status' => 'completed']);
    }

    public function test_authentication_errors_are_human_with_redacted_diagnostics_accessible(): void
    {
        $response = app(ConversationResponsePresenter::class)->present(['success' => false, 'error' => 'API returned HTTP 401. Authorization: Bearer private-token.']);
        $this->assertSame('error', $response['display']['type']);
        $this->assertSame('The connection needs to be authorized again. Check it in Settings.', $response['display']['message']);
        $this->assertSame('/jarvis/settings/connections', $response['display']['connection_url']);
        $this->assertStringContainsString('401', $response['display']['details']);
        $this->assertStringNotContainsString('private-token', json_encode($response['display']));
        $this->assertStringNotContainsString('401', $response['speech_text']);
    }

    public function test_dashboard_remains_renderable_with_a_polite_live_response_panel(): void
    {
        $this->get('/jarvis')->assertOk()->assertSee('jc-conversation-panel')->assertSee('aria-live="polite"', false);
    }
}
