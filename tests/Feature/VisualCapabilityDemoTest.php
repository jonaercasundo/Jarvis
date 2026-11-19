<?php

namespace Tests\Feature;

use App\Ai\Agents\JcAgent;
use App\Models\ApiConnection;
use App\Models\EmailAccount;
use App\Models\JarvisSetting;
use App\Models\User;
use App\Services\GoDaddyEmailService;
use App\Services\SourceCodeInspector;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class VisualCapabilityDemoTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[DataProvider('generalQuestions')]
    public function test_normal_capability_questions_return_short_visual_choices_without_executing_tools(string $prompt): void
    {
        $this->preventOperationalTools();
        JcAgent::fake();

        $response = $this->postJson('/jarvis/command', ['command' => $prompt]);

        $response->assertOk()->assertJsonPath('type', 'visual_demo')->assertJsonPath('mode', 'categories')
            ->assertJsonMissingPath('capabilities')->assertJsonMissingPath('source_inspection')
            ->assertJsonMissingPath('demonstration')->assertJsonMissingPath('chart');
        $this->assertSame(['email', 'data', 'automation', 'preferences', 'developer'], array_column($response->json('categories'), 'id'));
        $this->assertLessThanOrEqual(8, str_word_count($response->json('title')));
        $this->assertLessThan(200, strlen($response->json('summary')));
        foreach ($response->json('categories') as $category) {
            $this->assertNotEmpty($category['icon']);
            $this->assertLessThan(150, strlen($category['summary']));
            $this->assertLessThanOrEqual(7, count($category['steps']));
            $this->assertArrayHasKey('availability', $category);
        }
        $email = collect($response->json('categories'))->firstWhere('id', 'email');
        $this->assertArrayNotHasKey('live_action', $email);
        $response->assertDontSee('FindEmails')->assertDontSee('READ_ONLY')->assertDontSee('EXTERNAL_SIDE_EFFECT')->assertDontSee('workflow_data');
        $this->assertDatabaseCount('pending_email_actions', 0);
        JcAgent::assertNeverPrompted();
        Http::assertNothingSent();
    }

    public static function generalQuestions(): array
    {
        return [
            'general abilities' => ['What can you do?'],
            'personal help' => ['How can you help me?'],
            'general explanation' => ['How does this work?'],
        ];
    }

    #[DataProvider('categoryQuestions')]
    public function test_category_questions_return_a_visual_flow_without_source_or_internal_metadata(string $prompt, string $category): void
    {
        $this->preventOperationalTools();

        $response = $this->postJson('/jarvis/command', ['command' => $prompt]);

        $response->assertOk()->assertJsonPath('type', 'visual_demo')->assertJsonPath('mode', 'flow')
            ->assertJsonPath('category', $category)->assertJsonMissingPath('capabilities')
            ->assertJsonMissingPath('source_inspection')->assertJsonMissingPath('demonstration');
        $this->assertGreaterThanOrEqual(3, count($response->json('steps')));
        $this->assertLessThanOrEqual(7, count($response->json('steps')));
        foreach ($response->json('steps') as $step) {
            $this->assertNotEmpty($step['icon']);
            $this->assertLessThanOrEqual(5, str_word_count($step['title']));
        }
        $this->assertLessThan(250, strlen($response->json('summary')));
        $response->assertDontSee('FindEmails')->assertDontSee('CodeInspector')->assertDontSee('executor')->assertDontSee('READ_ONLY');
        $this->assertDatabaseCount('pending_email_actions', 0);
        Http::assertNothingSent();
    }

    public static function categoryQuestions(): array
    {
        return [
            'email' => ['What can you do with email?', 'email'],
            'data' => ['What can you do with our data?', 'data'],
            'automation' => ['What can you do with workflows?', 'automation'],
            'preferences' => ['How do you remember my preferences?', 'preferences'],
            'developer explanation' => ['How can you understand your own code?', 'developer'],
        ];
    }

    public function test_show_me_how_preserves_the_previous_category_and_never_reads_the_real_inbox(): void
    {
        $this->actingAs(User::factory()->create());
        $this->preventOperationalTools();
        $this->postJson('/jarvis/command', ['command' => 'What can you do with email?', 'conversation_id' => 'visual-context'])->assertOk();

        $response = $this->postJson('/jarvis/command', ['command' => 'Show me how.', 'conversation_id' => 'visual-context']);

        $response->assertOk()->assertJsonPath('type', 'visual_demo')->assertJsonPath('mode', 'flow')
            ->assertJsonPath('category', 'email')->assertJsonMissingPath('demonstration');
        $this->assertDatabaseCount('pending_email_actions', 0);
        Http::assertNothingSent();
    }

    public function test_visual_context_does_not_leak_between_conversations(): void
    {
        $this->actingAs(User::factory()->create());
        $this->preventOperationalTools();
        $this->postJson('/jarvis/command', ['command' => 'What can you do with email?', 'conversation_id' => 'email-chat'])->assertOk();

        $response = $this->postJson('/jarvis/command', [
            'command' => 'Show me how.', 'conversation_id' => 'different-chat', 'visual_category' => 'email',
        ]);

        $response->assertOk()->assertJsonPath('type', 'visual_demo')->assertJsonPath('mode', 'categories');
        Http::assertNothingSent();
    }

    public function test_visual_context_does_not_leak_when_the_signed_in_owner_changes(): void
    {
        $this->preventOperationalTools();
        $this->actingAs(User::factory()->create())->postJson('/jarvis/command', [
            'command' => 'What can you do with email?', 'conversation_id' => 'shared-browser',
        ])->assertOk();

        $response = $this->actingAs(User::factory()->create())->postJson('/jarvis/command', [
            'command' => 'Show me how.', 'conversation_id' => 'shared-browser', 'visual_category' => 'email',
        ]);

        $response->assertOk()->assertJsonPath('type', 'visual_demo')->assertJsonPath('mode', 'categories');
        Http::assertNothingSent();
    }

    public function test_show_me_how_uses_a_locally_selected_card_only_in_the_scoped_catalog_conversation(): void
    {
        $this->actingAs(User::factory()->create());
        $this->preventOperationalTools();
        $this->postJson('/jarvis/command', ['command' => 'What can you do?', 'conversation_id' => 'card-selection'])
            ->assertOk()->assertJsonPath('mode', 'categories');

        $response = $this->postJson('/jarvis/command', [
            'command' => 'Show me how.', 'conversation_id' => 'card-selection', 'visual_category' => 'email',
        ]);

        $response->assertOk()->assertJsonPath('type', 'visual_demo')->assertJsonPath('mode', 'flow')
            ->assertJsonPath('category', 'email')->assertJsonMissingPath('demonstration');
        Http::assertNothingSent();
    }

    public function test_explain_technically_uses_the_current_email_context_without_calling_an_email_tool(): void
    {
        $this->actingAs(User::factory()->create());
        $this->preventOperationalTools();
        $this->postJson('/jarvis/command', ['command' => 'What can you do with email?', 'conversation_id' => 'technical-context'])->assertOk();

        $response = $this->postJson('/jarvis/command', ['command' => 'Explain technically.', 'conversation_id' => 'technical-context']);

        $response->assertOk()->assertJsonPath('type', 'capabilities')->assertJsonMissingPath('demonstration');
        $this->assertContains('FindEmails', array_column($response->json('capabilities.tools'), 'name'));
        Http::assertNothingSent();
    }

    public function test_just_show_me_overrides_a_saved_technical_presentation_preference(): void
    {
        $this->actingAs(User::factory()->create());
        JarvisSetting::current()->update(['learned_memory' => 'technical_level = technical']);
        $this->preventOperationalTools();
        $this->postJson('/jarvis/command', ['command' => 'What can you do with email?', 'conversation_id' => 'saved-technical'])
            ->assertOk()->assertJsonPath('type', 'capabilities');

        $response = $this->postJson('/jarvis/command', ['command' => 'Just show me.', 'conversation_id' => 'saved-technical']);

        $response->assertOk()->assertJsonPath('type', 'visual_demo')->assertJsonPath('category', 'email')
            ->assertJsonMissingPath('capabilities')->assertJsonMissingPath('source_inspection');
        $this->assertLessThan(250, strlen($response->json('response')));
        $this->assertSame('technical_level = technical', JarvisSetting::current()->learned_memory);
        Http::assertNothingSent();
    }

    #[DataProvider('demonstrationPreferences')]
    public function test_visual_explanations_remain_available_and_never_run_live_tools_for_every_demo_preference(string $preference): void
    {
        JarvisSetting::current()->update(['capability_demonstrations' => $preference]);
        EmailAccount::query()->create($this->mailbox());
        $this->preventOperationalTools();

        $response = $this->postJson('/jarvis/command', ['command' => 'What can you do with email?']);

        $response->assertOk()->assertJsonPath('type', 'visual_demo')->assertJsonPath('category', 'email')
            ->assertJsonMissingPath('demonstration');
        if ($preference === 'never_demo') {
            $response->assertJsonMissingPath('live_action');
        } else {
            $response->assertJsonPath('live_action.command', 'Demo email search.');
        }
        Http::assertNothingSent();
    }

    public static function demonstrationPreferences(): array
    {
        return [
            'ask first' => ['ask_first'],
            'automatic live demo' => ['automatic_safe_demo'],
            'never live demo' => ['never_demo'],
        ];
    }

    public function test_email_live_action_is_hidden_when_settings_do_not_permit_email_search(): void
    {
        JarvisSetting::current()->update(['allowed_actions' => ['analysis']]);
        EmailAccount::query()->create($this->mailbox());
        $this->preventOperationalTools();

        $response = $this->postJson('/jarvis/command', ['command' => 'What can you do with email?']);

        $response->assertOk()->assertJsonPath('type', 'visual_demo')->assertJsonMissingPath('live_action');
        Http::assertNothingSent();
    }

    public function test_direct_live_demo_commands_still_cannot_bypass_disabled_email_permission(): void
    {
        JarvisSetting::current()->update(['allowed_actions' => ['analysis']]);
        EmailAccount::query()->create($this->mailbox());
        $this->preventOperationalTools();

        $response = $this->postJson('/jarvis/command', ['command' => 'Demo email search.']);

        $response->assertOk()->assertJsonMissingPath('demonstration');
        $this->assertDatabaseCount('pending_email_actions', 0);
        Http::assertNothingSent();
    }

    public function test_data_explanation_labels_sample_values_and_distinguishes_estimates_from_history(): void
    {
        $this->preventOperationalTools();

        $response = $this->postJson('/jarvis/command', ['command' => 'What can you do with our data?']);

        $response->assertOk()->assertJsonPath('type', 'visual_demo')->assertJsonPath('chart.label', 'Illustrative sample data');
        $series = $response->json('chart.series');
        $this->assertNotEmpty(array_filter($series, fn (array $point): bool => ($point['forecast'] ?? false) === true));
        $this->assertNotEmpty(array_filter($series, fn (array $point): bool => ($point['forecast'] ?? false) === false));
        $response->assertJsonMissingPath('demonstration')->assertJsonMissingPath('capabilities');
        Http::assertNothingSent();
    }

    public function test_real_delivery_trend_does_not_fabricate_a_chart_when_no_delivery_read_tool_exists(): void
    {
        $this->preventOperationalTools();

        $response = $this->postJson('/jarvis/command', ['command' => 'Show our real delivery trend.']);

        $response->assertOk()->assertJsonPath('type', 'data_unavailable')->assertJsonMissingPath('chart')
            ->assertJsonMissingPath('artifacts')->assertJsonMissingPath('demonstration')
            ->assertDontSee('Illustrative sample data');
        $this->assertLessThan(300, strlen($response->json('response')));
        Http::assertNothingSent();
    }

    public function test_saved_chart_preference_can_hide_the_illustrative_chart_without_hiding_the_flow(): void
    {
        JarvisSetting::current()->update(['learned_memory' => 'charts_for_trends = false']);
        $this->preventOperationalTools();

        $response = $this->postJson('/jarvis/command', ['command' => 'What can you do with our data?']);

        $response->assertOk()->assertJsonPath('type', 'visual_demo')->assertJsonPath('mode', 'flow')
            ->assertJsonPath('category', 'data')->assertJsonMissingPath('chart');
        $this->assertNotEmpty($response->json('steps'));
        Http::assertNothingSent();
    }

    public function test_saved_text_preference_returns_a_short_summary_without_technical_metadata(): void
    {
        JarvisSetting::current()->update(['learned_memory' => 'visual_explanations = off']);
        $this->preventOperationalTools();

        $response = $this->postJson('/jarvis/command', ['command' => 'What can you do?']);

        $response->assertOk()->assertJsonPath('type', 'capability_summary')->assertJsonMissingPath('categories')
            ->assertJsonMissingPath('capabilities')->assertJsonMissingPath('source_inspection')
            ->assertDontSee('FindEmails')->assertDontSee('READ_ONLY');
        $this->assertLessThan(200, strlen($response->json('response')));
        Http::assertNothingSent();
    }

    public function test_disabled_data_analysis_is_not_advertised_as_an_available_category(): void
    {
        JarvisSetting::current()->update(['allowed_actions' => ['read_emails', 'search_emails']]);
        $this->preventOperationalTools();

        $response = $this->postJson('/jarvis/command', ['command' => 'What can you do?']);

        $response->assertOk()->assertJsonPath('type', 'visual_demo');
        $this->assertNotContains('data', array_column($response->json('categories'), 'id'));
        Http::assertNothingSent();
    }

    public function test_saved_connection_explanation_does_not_claim_direct_chat_access_or_expose_secrets(): void
    {
        $user = User::factory()->create();
        ApiConnection::factory()->create([
            'owner_key' => 'user:'.$user->id, 'name' => 'MMC Tracker', 'token' => 'private-mmc-tracker-token',
        ]);
        $this->preventOperationalTools();

        $response = $this->actingAs($user)->postJson('/jarvis/command', ['command' => 'Can you access MMC Tracker?']);

        $response->assertOk()->assertJsonPath('type', 'visual_demo')->assertJsonPath('mode', 'flow')
            ->assertJsonPath('category', 'automation')
            ->assertJsonPath('summary', 'MMC Tracker is saved for connected workflows. I cannot read its data directly in this chat.')
            ->assertJsonMissingPath('capabilities')->assertJsonMissingPath('source_inspection')->assertJsonMissingPath('chart')
            ->assertDontSee('private-mmc-tracker-token')->assertDontSee('FindEmails')->assertDontSee('READ_ONLY');
        $this->assertLessThan(200, strlen($response->json('response')));
        Http::assertNothingSent();
    }

    public function test_unsupported_integration_explanation_does_not_invent_real_data_or_a_live_action(): void
    {
        $this->preventOperationalTools();

        $response = $this->postJson('/jarvis/command', ['command' => 'Do you currently support Google Sheets?']);

        $response->assertOk()->assertJsonPath('type', 'visual_demo')
            ->assertJsonPath('summary', 'Google Sheets is not available for connected work here.')
            ->assertJsonMissingPath('chart')->assertJsonMissingPath('live_action')
            ->assertJsonMissingPath('capabilities')->assertJsonMissingPath('demonstration')
            ->assertDontSee('FindEmails')->assertDontSee('executor');
        $this->assertLessThan(200, strlen($response->json('response')));
        Http::assertNothingSent();
    }

    private function preventOperationalTools(): void
    {
        $email = Mockery::mock(GoDaddyEmailService::class);
        $email->shouldNotReceive('findEmails', 'latestEmails', 'unreadEmails', 'sendEmail', 'sendReply', 'deleteEmail', 'markAsRead');
        $this->app->instance(GoDaddyEmailService::class, $email);
        $inspector = Mockery::mock(SourceCodeInspector::class);
        $inspector->shouldNotReceive('inspect');
        $this->app->instance(SourceCodeInspector::class, $inspector);
        Http::preventStrayRequests();
    }

    private function mailbox(): array
    {
        return ['name' => 'Visual demo mailbox', 'email' => 'owner@test.example', 'is_active' => true, 'is_default' => true,
            'imap_host' => 'imap.test.example', 'imap_username' => 'owner@test.example', 'imap_port' => 993, 'imap_password' => 'hidden-imap-password',
            'smtp_host' => 'smtp.test.example', 'smtp_username' => 'owner@test.example', 'smtp_port' => 465, 'smtp_password' => 'hidden-smtp-password'];
    }
}
