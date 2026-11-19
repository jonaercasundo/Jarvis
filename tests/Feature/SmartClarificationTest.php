<?php

namespace Tests\Feature;

use App\Ai\Agents\JcAgent;
use App\Models\JarvisSetting;
use App\Models\PendingEmailAction;
use App\Models\User;
use App\Services\Email\EmailNormalizer;
use App\Services\Email\EmailStatusService;
use App\Services\GoDaddyEmailService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\Data\ToolCall;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SmartClarificationTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[DataProvider('broadRequests')]
    public function test_broad_requests_ask_one_question_without_executing_or_charging_for_ai(string $command, string $question, array $labels): void
    {
        $this->preventOperations();
        JcAgent::fake();

        $response = $this->postJson('/jarvis/command', ['command' => $command]);

        $response->assertOk()->assertJsonPath('type', 'clarification')->assertJsonPath('question', $question)
            ->assertJsonPath('response', $question)->assertJsonPath('allow_custom_answer', true)
            ->assertJsonMissingPath('capabilities')->assertJsonMissingPath('confidence');
        $this->assertSame($labels, array_column($response->json('options'), 'label'));
        $this->assertDatabaseCount('pending_email_actions', 0);
        $this->assertDatabaseCount('jarvis_credit_usages', 0);
        JcAgent::assertNeverPrompted();
        Http::assertNothingSent();
    }

    public static function broadRequests(): array
    {
        return [
            ['Analyze our projects.', 'What would you like to focus on?', ['Progress', 'Delays', 'Deliveries', 'Budget', 'Overall']],
            ['Analyze our operations.', 'What should I focus on?', ['Projects', 'Deliveries', 'Inventory', 'Overall']],
            ['Analyze something.', 'What should I focus on?', ['Projects', 'Deliveries', 'Inventory', 'Overall']],
            ['Forecast our data.', 'What should I forecast?', ['Deliveries', 'Project Completion', 'Inventory', 'Sales', 'Other']],
            ['Show me a forecast.', 'What should I forecast?', ['Deliveries', 'Project Completion', 'Inventory', 'Sales', 'Other']],
            ['Forecast next month.', 'What should I forecast?', ['Deliveries', 'Project Completion', 'Inventory', 'Sales', 'Other']],
            ['Check my email.', 'What should I look for?', ['Important', 'Unread', 'Needs Reply', 'Today']],
        ];
    }

    public function test_choice_resolves_business_intent_once_and_reports_missing_real_records(): void
    {
        $this->actingAs(User::factory()->create());
        $this->preventOperations();
        $question = $this->postJson('/jarvis/command', ['command' => 'Analyze our operations.', 'conversation_id' => 'operations']);

        $response = $this->postJson('/jarvis/command', ['command' => 'deliveries', 'conversation_id' => 'operations', 'clarification_id' => $question->json('clarification_id')]);

        $response->assertOk()->assertJsonPath('type', 'data_unavailable')->assertJsonMissingPath('question')
            ->assertJsonMissingPath('chart')->assertJsonMissingPath('confidence');
        $this->assertStringStartsWith('Deliveries data', $response->json('response'));
        $this->assertStringNotContainsString('18', $response->json('response'));
        $this->assertSame('', JarvisSetting::current()->learned_memory ?? '');
        Http::assertNothingSent();
    }

    #[DataProvider('specificRequests')]
    public function test_specific_business_requests_do_not_ask_unnecessary_questions_when_only_data_is_unavailable(string $command): void
    {
        $this->preventOperations();

        $this->postJson('/jarvis/command', ['command' => $command])->assertOk()
            ->assertJsonPath('type', 'data_unavailable')->assertJsonMissingPath('question')->assertJsonMissingPath('chart');

        Http::assertNothingSent();
    }

    public static function specificRequests(): array
    {
        return [['Show pending deliveries for Project ABC.'], ['Show delivery trends for the last six months.'], ["Forecast next month's deliveries."]];
    }

    public function test_unread_sender_search_keeps_the_filter_and_does_not_clarify(): void
    {
        $email = Mockery::mock(GoDaddyEmailService::class, [app(EmailNormalizer::class)])->makePartial();
        $email->shouldReceive('unreadEmails')->once()->with(20)->andReturn(['success' => true, 'email' => 'owner@example.com',
            'emails' => [['from_name' => 'Carlo', 'from' => 'carlo@example.com', 'subject' => 'Requested'], ['from_name' => 'Someone Else', 'subject' => 'Unrelated']]]);
        $email->shouldNotReceive('latestEmails');
        $this->app->instance(GoDaddyEmailService::class, $email);
        Http::preventStrayRequests();

        $this->postJson('/jarvis/command', ['command' => 'Find unread emails from Carlo.'])->assertOk()
            ->assertJsonPath('type', 'email')->assertJsonCount(1, 'emails')->assertJsonPath('emails.0.subject', 'Requested')
            ->assertJsonMissingPath('question');

        Http::assertNothingSent();
    }

    public function test_an_unread_choice_runs_through_the_existing_read_pipeline_without_saving_an_inferred_preference(): void
    {
        $this->actingAs(User::factory()->create());
        $email = Mockery::mock(GoDaddyEmailService::class);
        $email->shouldReceive('isConfigured')->once()->andReturn(true);
        $email->shouldReceive('unreadEmails')->once()->with(10)->andReturn(['success' => true, 'emails' => [['subject' => 'Real result']]]);
        $this->app->instance(GoDaddyEmailService::class, $email);
        $question = $this->postJson('/jarvis/command', ['command' => 'Check my email.', 'conversation_id' => 'inbox']);

        $this->postJson('/jarvis/command', ['command' => 'Unread', 'conversation_id' => 'inbox', 'clarification_id' => $question->json('clarification_id')])
            ->assertOk()->assertJsonPath('type', 'email')->assertJsonPath('emails.0.subject', 'Real result');

        $this->assertSame('', JarvisSetting::current()->learned_memory ?? '');
    }

    public function test_explicit_email_default_is_saved_and_used_without_clarification(): void
    {
        $this->actingAs(User::factory()->create());
        $status = Mockery::mock(EmailStatusService::class);
        $status->shouldReceive('summarize')->once()->with(10)->andReturn(['summary' => '2 important messages.', 'items' => []]);
        $this->app->instance(EmailStatusService::class, $status);
        $this->preventOperations();

        $this->postJson('/jarvis/command', ['command' => 'Whenever I say check my email, just show important emails.'])
            ->assertOk()->assertJsonPath('type', 'preference_saved');
        $this->assertSame('check_email_default = important', JarvisSetting::current()->learned_memory);

        $this->postJson('/jarvis/command', ['command' => 'Check my email.'])->assertOk()
            ->assertJsonPath('type', 'email_status')->assertJsonPath('response', '2 important messages.')->assertJsonMissingPath('question');
        Http::assertNothingSent();
    }

    public function test_project_metric_and_period_survive_followups_and_a_natural_correction(): void
    {
        $this->actingAs(User::factory()->create());
        $this->preventOperations();
        $this->postJson('/jarvis/command', ['command' => 'Show Project ABC.', 'conversation_id' => 'project'])->assertOk();
        $this->postJson('/jarvis/command', ['command' => 'Deliveries?', 'conversation_id' => 'project'])
            ->assertOk()->assertJsonPath('type', 'data_unavailable')->assertSee('Project ABC', false)->assertJsonMissingPath('question');
        $this->postJson('/jarvis/command', ['command' => 'Forecast next month.', 'conversation_id' => 'project'])
            ->assertOk()->assertJsonMissingPath('question');

        $this->postJson('/jarvis/command', ['command' => 'No, last month.', 'conversation_id' => 'project'])
            ->assertOk()->assertJsonMissingPath('question')->assertSee('Project ABC', false)
            ->assertSessionHas('jarvis_smart_context.period', 'last month')
            ->assertSessionHas('jarvis_smart_context.last_command', 'Forecast deliveries for Project ABC last month.');
        Http::assertNothingSent();
    }

    #[DataProvider('scopeChanges')]
    public function test_pending_answers_do_not_cross_users_or_conversations_or_expiry(string $scopeChange): void
    {
        $this->actingAs(User::factory()->create());
        $this->preventOperations();
        $question = $this->postJson('/jarvis/command', ['command' => 'Check my email.', 'conversation_id' => 'original']);
        if ($scopeChange === 'owner') {
            $this->actingAs(User::factory()->create());
        } elseif ($scopeChange === 'expired') {
            $this->travel(11)->minutes();
        }

        $this->postJson('/jarvis/command', ['command' => 'Unread', 'conversation_id' => $scopeChange === 'conversation' ? 'different' : 'original',
            'clarification_id' => $question->json('clarification_id')])->assertOk()->assertJsonPath('type', 'clarification_expired');

        Http::assertNothingSent();
    }

    public static function scopeChanges(): array
    {
        return [['owner'], ['conversation'], ['expired']];
    }

    public function test_new_capability_request_replaces_a_pending_task_without_clarification(): void
    {
        $this->actingAs(User::factory()->create());
        $this->preventOperations();
        $question = $this->postJson('/jarvis/command', ['command' => 'Forecast.', 'conversation_id' => 'switch']);

        $this->postJson('/jarvis/command', ['command' => 'What can you do?', 'conversation_id' => 'switch', 'clarification_id' => $question->json('clarification_id')])
            ->assertOk()->assertJsonPath('type', 'visual_demo')->assertSessionMissing('jarvis_smart_context.pending');
        Http::assertNothingSent();
    }

    public function test_provided_history_is_actionable_and_does_not_require_a_data_connection_or_another_question(): void
    {
        $this->preventOperations();
        JcAgent::fake(['Deliveries rose from 60 to 80.']);

        $this->postJson('/jarvis/command', ['command' => 'Analyze deliveries: Jan: 60, Feb: 80.'])->assertOk()
            ->assertJsonPath('response', 'Deliveries rose from 60 to 80.')->assertJsonMissingPath('question');

        JcAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->contains('Jan: 60, Feb: 80'));
        Http::assertNothingSent();
    }

    public function test_explanation_followups_use_response_context_and_cannot_execute_tools_again(): void
    {
        $this->actingAs(User::factory()->create());
        $this->preventOperations();
        JcAgent::fake(fn (string $prompt): string => str_contains($prompt, 'Explain more.') ? 'Choose one task and finish it before switching.' : 'Start with one task.');
        $first = $this->postJson('/jarvis/command', ['command' => 'Give me a productivity tip.'])->assertOk();

        $this->postJson('/jarvis/command', ['command' => 'Explain more.', 'conversation_id' => $first->json('conversation_id')])
            ->assertOk()->assertJsonPath('response', 'Choose one task and finish it before switching.');
        JcAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->contains('Give me a productivity tip.')
            && str_contains($prompt->agent->instructions(), 'Response depth for this request: concise'));
        JcAgent::assertPrompted(function (AgentPrompt $prompt): bool {
            if (! $prompt->contains('Explain more.')) {
                return false;
            }
            $this->assertSame([], [...$prompt->agent->tools()]);
            $this->assertStringContainsString('Start with one task.', $prompt->agent->instructions());
            $this->assertStringContainsString('Response depth for this request: detailed', $prompt->agent->instructions());

            return true;
        });
        JcAgent::assertPromptedTimes(2);
        Http::assertNothingSent();
    }

    public function test_agent_clarification_is_rendered_structurally_and_stops_subsequent_tool_calls(): void
    {
        $this->actingAs(User::factory()->create());
        JarvisSetting::current()->update(['allowed_actions' => ['send_emails']]);
        $this->preventOperations();
        JcAgent::fake([
            new ToolCall('ask', 'AskClarification', ['question' => 'Who should receive it?', 'options' => [['label' => 'Carlo', 'value' => 'carlo@example.com']]]),
            new ToolCall('unsafe', 'SendEmail', ['to' => 'other@example.com', 'subject' => 'Wrong', 'body' => 'Wrong']),
            'This lengthy draft must not replace the question.',
        ]);

        $this->postJson('/jarvis/command', ['command' => 'Send an email.'])->assertOk()
            ->assertJsonPath('type', 'clarification')->assertJsonPath('question', 'Who should receive it?')
            ->assertJsonPath('options.0.value', 'carlo@example.com')->assertDontSee('lengthy draft');

        $this->assertDatabaseCount('pending_email_actions', 0);
        Http::assertNothingSent();
    }

    public function test_email_subject_filters_are_not_mistaken_for_business_data_requests(): void
    {
        $email = Mockery::mock(GoDaddyEmailService::class);
        $email->shouldReceive('findEmails')->once()->with('deliveries', null, null, 20)
            ->andReturn(['success' => true, 'emails' => [['subject' => 'Delivery update']]]);
        $this->app->instance(GoDaddyEmailService::class, $email);

        $this->postJson('/jarvis/command', ['command' => 'Find emails about deliveries.'])->assertOk()
            ->assertJsonPath('type', 'email')->assertJsonPath('emails.0.subject', 'Delivery update')->assertJsonMissingPath('question');
    }

    public function test_needs_reply_keeps_setup_errors_instead_of_claiming_zero_matches(): void
    {
        $this->actingAs(User::factory()->create());
        $status = Mockery::mock(EmailStatusService::class);
        $status->shouldReceive('summarize')->once()->with(10)->andReturn(['configured' => false, 'items' => [], 'summary' => 'Add your email account in Settings.']);
        $this->app->instance(EmailStatusService::class, $status);
        $this->preventOperations();
        $question = $this->postJson('/jarvis/command', ['command' => 'Check my email.']);

        $this->postJson('/jarvis/command', ['command' => 'Needs Reply', 'clarification_id' => $question->json('clarification_id')])->assertOk()
            ->assertJsonPath('response', 'Add your email account in Settings.');
    }

    public function test_needs_reply_filters_actual_items_and_preserves_incomplete_analysis(): void
    {
        $this->actingAs(User::factory()->create());
        $status = Mockery::mock(EmailStatusService::class);
        $status->shouldReceive('summarize')->once()->with(10)->andReturn(['configured' => true, 'not_analyzed_count' => 3,
            'items' => [['subject' => 'Reply needed', 'action' => 'Reply to Carlo.'], ['subject' => 'Review only', 'action' => 'Review the attachment.']], 'summary' => 'Partial analysis.']);
        $this->app->instance(EmailStatusService::class, $status);
        $this->preventOperations();
        $question = $this->postJson('/jarvis/command', ['command' => 'Check my email.']);

        $this->postJson('/jarvis/command', ['command' => 'needs_reply', 'clarification_id' => $question->json('clarification_id')])->assertOk()
            ->assertJsonCount(1, 'items')->assertJsonPath('items.0.subject', 'Reply needed')
            ->assertJsonPath('response', '1 displayed message may need a reply. Some unread messages have not been analyzed yet.');
    }

    public function test_concise_email_status_retains_urgency_and_incomplete_analysis_with_full_detail_available(): void
    {
        $status = Mockery::mock(EmailStatusService::class);
        $status->shouldReceive('summarize')->once()->with(10)->andReturn(['configured' => true, 'analyzed_count' => 7,
            'critical_count' => 1, 'action_required_count' => 2, 'important_count' => 1, 'not_analyzed_count' => 3,
            'items' => [['subject' => 'Deadline', 'action' => 'Review today.']], 'summary' => 'Full analysis with subjects and actions.']);
        $this->app->instance(EmailStatusService::class, $status);
        $this->preventOperations();

        $this->postJson('/jarvis/command', ['command' => 'Show important unread emails.'])->assertOk()
            ->assertJsonPath('response', '4 analyzed unread messages need attention. 1 is urgent. Some unread messages have not been analyzed yet.')
            ->assertJsonPath('summary', 'Full analysis with subjects and actions.')->assertJsonPath('items.0.subject', 'Deadline');
    }

    public function test_clarification_content_and_server_context_redact_credentials(): void
    {
        $this->actingAs(User::factory()->create());
        $this->preventOperations();
        JcAgent::fake([
            new ToolCall('ask', 'AskClarification', ['question' => 'Use Bearer private-token?', 'options' => [['label' => 'api_key=private-key', 'value' => 'Bearer private-token']]]),
            'Question.',
        ]);

        $this->postJson('/jarvis/command', ['command' => 'Help with Bearer private-token.'])->assertOk()
            ->assertJsonPath('type', 'clarification')->assertDontSee('private-token')->assertDontSee('private-key');
        $this->assertStringNotContainsString('private-token', json_encode(session('jarvis_smart_context')));
        $this->assertStringNotContainsString('private-key', json_encode(session('jarvis_smart_context')));
    }

    public function test_typed_custom_focus_and_period_correction_use_safe_default_context(): void
    {
        $this->actingAs(User::factory()->create());
        $this->preventOperations();
        $question = $this->postJson('/jarvis/command', ['command' => 'Analyze our operations.']);

        $this->postJson('/jarvis/command', ['command' => 'inventory', 'clarification_id' => $question->json('clarification_id')])->assertOk()
            ->assertJsonPath('type', 'data_unavailable')->assertSessionHas('jarvis_smart_context.period', 'this month');
        $this->postJson('/jarvis/command', ['command' => 'No, last month.'])->assertOk()
            ->assertSessionHas('jarvis_smart_context.last_command', 'Analyze inventory last month.')->assertJsonMissingPath('question');
    }

    #[DataProvider('responseDepths')]
    public function test_explicit_response_depths_format_the_prior_result_without_tools(string $followup, string $depth): void
    {
        $this->actingAs(User::factory()->create());
        $this->preventOperations();
        JcAgent::fake(fn (string $prompt): string => 'A verified answer.');
        $first = $this->postJson('/jarvis/command', ['command' => 'Give me a productivity tip.'])->assertOk();

        $this->postJson('/jarvis/command', ['command' => $followup, 'conversation_id' => $first->json('conversation_id')])->assertOk();

        JcAgent::assertPrompted(function (AgentPrompt $prompt) use ($followup, $depth): bool {
            if (! $prompt->contains($followup)) {
                return false;
            }
            $this->assertStringContainsString('Response depth for this request: '.$depth, $prompt->agent->instructions());
            $this->assertSame([], [...$prompt->agent->tools()]);

            return true;
        });
        Http::assertNothingSent();
    }

    public static function responseDepths(): array
    {
        return [['Explain.', 'normal'], ['Explain technically.', 'technical'], ['Just give me the answer.', 'concise'], ['Just show me.', 'concise']];
    }

    public function test_agent_answer_resumes_the_original_request_and_still_requires_email_confirmation(): void
    {
        $this->actingAs(User::factory()->create());
        JarvisSetting::current()->update(['allowed_actions' => ['send_emails']]);
        $email = Mockery::mock(GoDaddyEmailService::class);
        $email->shouldReceive('canSendNewEmails')->once()->andReturn(true);
        $email->shouldNotReceive('sendEmail', 'sendReply', 'deleteEmail', 'markAsRead');
        $this->app->instance(GoDaddyEmailService::class, $email);
        Http::preventStrayRequests();
        JcAgent::fake([
            new ToolCall('ask', 'AskClarification', ['question' => 'Who should receive it?', 'options' => [['label' => 'Carlo', 'value' => 'carlo@example.com']]]),
            'Who should receive it?',
        ]);
        $question = $this->postJson('/jarvis/command', ['command' => 'Send an email with subject Update saying Meeting tomorrow.'])->assertOk();
        JcAgent::fake([
            new ToolCall('stage', 'SendEmail', ['to' => 'carlo@example.com', 'subject' => 'Update', 'body' => 'Meeting tomorrow.']),
            'Prepared for your review.',
        ]);

        $this->postJson('/jarvis/command', ['command' => 'carlo@example.com', 'conversation_id' => $question->json('conversation_id'),
            'clarification_id' => $question->json('clarification_id')])->assertOk()->assertJsonMissingPath('question');

        JcAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->contains('Send an email with subject Update saying Meeting tomorrow.')
            && $prompt->contains('User clarification: carlo@example.com'));
        $this->assertDatabaseCount('pending_email_actions', 1);
        $this->assertDatabaseHas('pending_email_actions', ['action' => 'send', 'status' => 'pending']);
        $this->assertSame('carlo@example.com', PendingEmailAction::first()->payload['to']);
        Http::assertNothingSent();
    }

    public function test_guest_conversation_uses_its_existing_owner_cookie_for_followup_context(): void
    {
        $this->withCredentials()->withCookie('jarvis_workflow_owner', 'guest-owner-with-stable-cookie');
        $this->preventOperations();
        $question = $this->postJson('/jarvis/command', ['command' => 'Analyze our projects.', 'conversation_id' => 'guest']);

        $this->postJson('/jarvis/command', ['command' => 'Delays', 'conversation_id' => 'guest', 'clarification_id' => $question->json('clarification_id')])
            ->assertOk()->assertJsonPath('type', 'data_unavailable')->assertJsonMissingPath('question');
        Http::assertNothingSent();
    }

    public function test_cancelling_a_question_clears_the_task_without_executing(): void
    {
        $this->actingAs(User::factory()->create());
        $this->preventOperations();
        $question = $this->postJson('/jarvis/command', ['command' => 'Forecast.']);

        $this->postJson('/jarvis/command', ['command' => 'Cancel.', 'clarification_id' => $question->json('clarification_id')])->assertOk()
            ->assertJsonPath('response', 'Cancelled.')->assertSessionMissing('jarvis_smart_context.pending');
        Http::assertNothingSent();
    }

    private function preventOperations(): void
    {
        $email = Mockery::mock(GoDaddyEmailService::class);
        $email->shouldNotReceive('findEmails', 'latestEmails', 'unreadEmails', 'sendEmail', 'sendReply', 'deleteEmail', 'markAsRead');
        $this->app->instance(GoDaddyEmailService::class, $email);
        Http::preventStrayRequests();
    }
}
