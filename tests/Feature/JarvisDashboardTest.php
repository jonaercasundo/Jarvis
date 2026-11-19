<?php

namespace Tests\Feature;

use App\Models\JarvisSetting;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class JarvisDashboardTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_jarvis_route_renders_the_current_prompt_starters_without_the_old_copy(): void
    {
        $response = $this->get('/jarvis');

        $response->assertViewIs('jarvis.dashboard')
            ->assertSee('Start anywhere')
            ->assertSee('Ask, analyze, automate, or take action.')
            ->assertSee('data-prompt="Compare all active projects"', false)
            ->assertSee('data-prompt="Show project delivery progress"', false)
            ->assertSee('data-prompt="Check important emails"', false)
            ->assertSee('data-prompt="Show my workflows"', false)
            ->assertDontSee('How are you?')
            ->assertDontSee('Tell me something interesting')
            ->assertDontSee('Check my latest email')
            ->assertDontSee('Find warranty emails')
            ->assertDontSee('ask JC to help');
    }

    #[DataProvider('configuredNames')]
    public function test_dashboard_uses_the_configured_ai_name_on_the_first_render(string $name): void
    {
        JarvisSetting::current()->update(['ai_name' => $name]);

        $response = $this->get('/jarvis');

        $response->assertSee('placeholder="Ask '.e($name).' anything..."', false)
            ->assertSee('aria-label="Ask '.e($name).' anything"', false)
            ->assertDontSee('Message JC...');
    }

    public static function configuredNames(): array
    {
        return [
            'Astra' => ['Astra'],
            'Vanessa' => ['Vanessa'],
            'escaped identity' => ['Astra" <pilot>'],
        ];
    }

    public function test_saved_ai_name_change_is_visible_after_refresh(): void
    {
        JarvisSetting::current()->update(['ai_name' => 'Astra']);

        $this->postJson('/jarvis/settings', ['ai_name' => 'Vanessa'])
            ->assertOk()->assertJsonPath('settings.ai_name', 'Vanessa');

        $this->get('/jarvis')->assertSee('placeholder="Ask Vanessa anything..."', false)
            ->assertDontSee('Ask Astra anything...');
    }
}
