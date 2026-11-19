<?php

namespace Tests\Feature;

use App\Ai\Agents\JcAgent;
use App\Ai\Tools\PolicyControlledTool;
use App\Models\ApiConnection;
use App\Models\EmailAccount;
use App\Models\JarvisSetting;
use App\Models\User;
use App\Services\CapabilityExecutionPolicy;
use App\Services\CapabilityRegistry;
use App\Services\GoDaddyEmailService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Laravel\Ai\Tools\ToolNameResolver;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class JarvisCapabilitiesTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[DataProvider('capabilityQuestions')]
    public function test_capability_questions_never_execute_email_or_remote_operations(string $question, string $presentation): void
    {
        $email = Mockery::mock(GoDaddyEmailService::class);
        $email->shouldNotReceive('findEmails', 'latestEmails', 'unreadEmails', 'sendEmail', 'sendReply', 'deleteEmail', 'markAsRead');
        $this->app->instance(GoDaddyEmailService::class, $email);
        Http::preventStrayRequests();

        $response = $this->postJson('/jarvis/command', ['command' => $question]);

        $response->assertOk()->assertJsonPath('type', $presentation);
        if ($presentation === 'capabilities') {
            $response->assertJsonPath('capabilities.capability_demonstrations', 'ask_first');
            $this->assertContains('FindEmails', array_column($response->json('capabilities.tools'), 'name'));
        } else {
            $response->assertJsonMissingPath('capabilities')->assertJsonMissingPath('source_inspection');
        }
        $this->assertDatabaseCount('pending_email_actions', 0);
        Http::assertNothingSent();
    }

    public static function capabilityQuestions(): array
    {
        return [
            ['What can you do?', 'visual_demo'],
            ['What are your actual functions?', 'capabilities'],
            ['What tools do you currently have?', 'capabilities'],
            ['Can you read email?', 'visual_demo'],
            ['Can you send email?', 'visual_demo'],
            ['Can you search my email?', 'visual_demo'],
            ['Can you access MMC Tracker?', 'visual_demo'],
            ['What can you do with workflows?', 'visual_demo'],
            ['Do you currently support Google Sheets?', 'visual_demo'],
            ['Can you modify your own code?', 'visual_demo'],
            ['How does your FindEmails function work?', 'capabilities'],
            ['Where is FindEmails implemented?', 'capabilities'],
            ['How do you search my email?', 'visual_demo'],
        ];
    }

    public function test_tool_registry_tracks_actual_registration_and_sdk_has_tools_contract(): void
    {
        $email = Mockery::mock(GoDaddyEmailService::class);
        $agent = new JcAgent($email);

        $capabilities = app(CapabilityRegistry::class)->discover($agent);

        $this->assertInstanceOf(HasTools::class, $agent);
        $registered = array_map(fn ($tool): string => ToolNameResolver::resolve($tool), [...$agent->registeredTools()]);
        $this->assertSame($registered, array_column($capabilities['tools'], 'name'));
        $this->assertNotContains('GetAgentStatus', $registered);
        $this->assertContains('CodeInspector', $registered);
        $this->assertContains('DescribeCapabilities', $registered);
    }

    public function test_configured_connection_metadata_is_owned_has_no_secrets_and_never_claims_verified_connectivity(): void
    {
        $user = User::factory()->create();
        ApiConnection::factory()->create(['owner_key' => 'user:'.$user->id, 'name' => 'MMC Tracker', 'token' => 'saved-secret-must-stay-private']);
        ApiConnection::factory()->create(['owner_key' => 'user:other', 'name' => 'Private other-owner API']);
        Http::preventStrayRequests();

        $response = $this->actingAs($user)->postJson('/jarvis/command', ['command' => 'What tools do you actually have? Include configured connections.']);

        $response->assertOk()->assertJsonCount(1, 'capabilities.saved_connections')
            ->assertJsonPath('capabilities.saved_connections.0.name', 'MMC Tracker')
            ->assertJsonPath('capabilities.saved_connections.0.configured', true)
            ->assertJsonPath('capabilities.saved_connections.0.available_to_agent', false)
            ->assertJsonPath('capabilities.saved_connections.0.reachability_verified', false)
            ->assertDontSee('saved-secret-must-stay-private')->assertDontSee('Private other-owner API')
            ->assertJsonPath('capabilities.integrations.sheets.implemented', false)
            ->assertJsonPath('capabilities.integrations.custom_api.implemented', true);
        Http::assertNothingSent();
    }

    public function test_actual_source_location_and_provider_call_chain_are_inspected_without_searching_mail(): void
    {
        $email = Mockery::mock(GoDaddyEmailService::class);
        $email->shouldNotReceive('findEmails');
        $this->app->instance(GoDaddyEmailService::class, $email);

        $response = $this->postJson('/jarvis/command', ['command' => 'How does your FindEmails function work?']);

        $response->assertOk()->assertJsonPath('source_inspection.0.path', 'app/Ai/Tools/FindEmails.php')
            ->assertJsonPath('source_inspection.1.path', 'app/Services/Email/Providers/ImapEmailProvider.php')
            ->assertSee('ImapEmailProvider::findEmails', false);
    }

    public function test_saved_connection_status_refreshes_without_waiting_for_metadata_cache_expiry(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->postJson('/jarvis/command', ['command' => 'What tools do you actually have?'])
            ->assertOk()->assertJsonCount(0, 'capabilities.saved_connections');

        $connection = ApiConnection::factory()->create(['owner_key' => 'user:'.$user->id]);
        $this->postJson('/jarvis/command', ['command' => 'What tools do you actually have?'])
            ->assertOk()->assertJsonCount(1, 'capabilities.saved_connections');

        $connection->delete();
        $this->postJson('/jarvis/command', ['command' => 'What tools do you actually have?'])
            ->assertOk()->assertJsonCount(0, 'capabilities.saved_connections');
    }

    #[DataProvider('demoPreferences')]
    public function test_generic_demo_follows_saved_preference(string $preference, bool $expectsDemo): void
    {
        JarvisSetting::current()->update(['capability_demonstrations' => $preference]);

        $response = $this->postJson('/jarvis/command', ['command' => 'Live demo your source capabilities.']);

        $response->assertOk();
        if ($expectsDemo) {
            $response->assertJsonPath('demonstration.tool', 'CodeInspector')->assertJsonPath('demonstration.simulated', false);
        } else {
            $response->assertJsonMissingPath('demonstration');
        }
        $this->assertDatabaseCount('pending_email_actions', 0);
    }

    public static function demoPreferences(): array
    {
        return [['ask_first', false], ['automatic_safe_demo', true], ['never_demo', false]];
    }

    public function test_agreement_to_a_pending_safe_demo_is_scoped_to_current_conversation(): void
    {
        $this->actingAs(User::factory()->create());
        $this->postJson('/jarvis/command', ['command' => 'Live demo your source capabilities.', 'conversation_id' => 'same-conversation'])->assertOk();

        $this->postJson('/jarvis/command', ['command' => 'Yes please', 'conversation_id' => 'same-conversation'])
            ->assertOk()->assertJsonPath('demonstration.tool', 'CodeInspector');
    }

    #[DataProvider('sideEffectDemos')]
    public function test_demos_cannot_stage_send_delete_or_write_even_when_automatic_demo_is_enabled(string $prompt): void
    {
        JarvisSetting::current()->update(['capability_demonstrations' => 'automatic_safe_demo', 'allowed_actions' => ['send_emails', 'read_emails', 'delete_emails', 'draft_replies']]);
        $email = Mockery::mock(GoDaddyEmailService::class);
        $email->shouldNotReceive('sendEmail', 'sendReply', 'deleteEmail', 'markAsRead');
        $this->app->instance(GoDaddyEmailService::class, $email);

        $this->postJson('/jarvis/command', ['command' => $prompt])->assertOk()->assertJsonMissingPath('demonstration');

        $this->assertDatabaseCount('pending_email_actions', 0);
    }

    public static function sideEffectDemos(): array
    {
        return [['Send a demo email.'], ['Demo sending email.'], ['Demo deleting email.'], ['Demo creating a workflow.'], ['Demo modifying your own code.']];
    }

    public function test_explicit_email_search_demo_calls_real_read_only_tool_after_permission_check(): void
    {
        EmailAccount::query()->create($this->mailbox());
        $email = Mockery::mock(GoDaddyEmailService::class);
        $email->shouldReceive('findEmails')->once()->with(null, null, null, 3)->andReturn(['success' => true, 'count' => 1, 'emails' => [['subject' => 'Real test result']]]);
        $this->app->instance(GoDaddyEmailService::class, $email);

        $this->postJson('/jarvis/command', ['command' => 'Demo email search.'])->assertOk()
            ->assertJsonPath('demonstration.tool', 'FindEmails')->assertJsonPath('demonstration.result.emails.0.subject', 'Real test result');
    }

    public function test_never_demo_blocks_explicit_email_demo(): void
    {
        JarvisSetting::current()->update(['capability_demonstrations' => 'never_demo']);
        $email = Mockery::mock(GoDaddyEmailService::class);
        $email->shouldNotReceive('findEmails');
        $this->app->instance(GoDaddyEmailService::class, $email);

        $this->postJson('/jarvis/command', ['command' => 'Demo email search.'])->assertOk()->assertJsonMissingPath('demonstration');
    }

    public function test_demo_preference_round_trips_through_settings_and_rejects_unknown_values(): void
    {
        $this->postJson('/jarvis/settings', ['ai_name' => 'JC', 'capability_demonstrations' => 'never_demo'])
            ->assertOk()->assertJsonPath('settings.capability_demonstrations', 'never_demo');
        $this->getJson('/jarvis/settings')->assertJsonPath('settings.capability_demonstrations', 'never_demo');
        $this->assertDatabaseHas('jarvis_settings', ['capability_demonstrations' => 'never_demo']);
        $this->postJson('/jarvis/settings', ['ai_name' => 'JC', 'capability_demonstrations' => 'unsafe_demo'])
            ->assertUnprocessable()->assertJsonValidationErrors('capability_demonstrations');
    }

    public function test_tool_policy_refuses_unknown_demo_risks_before_the_tool_handler(): void
    {
        $tool = Mockery::mock(Tool::class);
        $tool->shouldNotReceive('handle');

        $result = (new PolicyControlledTool($tool, 'Demo your capabilities.', 'automatic_safe_demo'))->handle(new Request);

        $this->assertFalse(json_decode($result, true)['success']);
    }

    #[DataProvider('operationalIntentPrompts')]
    public function test_risky_tool_calls_require_matching_user_intent(string $prompt, bool $expected): void
    {
        $policy = app(CapabilityExecutionPolicy::class);
        $metadata = ['risk' => $policy::EXTERNAL_SIDE_EFFECT, 'intent_verbs' => ['send']];

        $this->assertSame($expected, $policy->permits($metadata, $prompt, 'automatic_safe_demo'));
    }

    public static function operationalIntentPrompts(): array
    {
        return [
            ['Hello there', false],
            ['Where is SendEmail implemented?', false],
            ['Explain the source code that says send an email', false],
            ['Can you send emails?', false],
            ['Send a demo email', false],
            ['Search my unread emails', false],
            ['Send an email to owner@example.com about the meeting', true],
        ];
    }

    private function mailbox(): array
    {
        return ['name' => 'Test mailbox', 'email' => 'owner@test.example', 'is_active' => true, 'is_default' => true,
            'imap_host' => 'imap.test.example', 'imap_username' => 'owner@test.example', 'imap_port' => 993, 'imap_password' => 'hidden-imap-password',
            'smtp_host' => 'smtp.test.example', 'smtp_username' => 'owner@test.example', 'smtp_port' => 465, 'smtp_password' => 'hidden-smtp-password'];
    }
}
