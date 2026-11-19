<?php

namespace Tests\Feature;

use App\Ai\Agents\JcAgent;
use App\Models\User;
use App\Services\ConversationResponsePresenter;
use App\Services\ForecastService;
use App\Services\VisualizationBuilder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Responses\Data\ToolCall;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class VisualizationResponseTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_exact_expense_sample_is_published_as_a_visual_with_separate_speech(): void
    {
        Http::preventStrayRequests();
        $visual = app(VisualizationBuilder::class)->buildForecastChart(
            [['label' => 'Last year', 'value' => 6000000], ['label' => 'This year', 'value' => 2000000]],
            [['label' => 'Next year', 'value' => 2100000]], 'Expense Trend & Forecast',
            ['y_axis' => ['label' => 'Expenses', 'format' => 'currency', 'currency' => 'PHP'], 'explanation' => 'An illustrative five percent increase, not a calculated company forecast.'],
        );
        $speech = 'Expenses dropped sharply this year. Next year is forecast to increase slightly.';
        JcAgent::fake([new ToolCall('visual', 'RenderVisualization', [
            'visual_json' => json_encode($visual), 'message' => 'Expenses dropped sharply this year.', 'speech_text' => $speech,
        ]), 'As a chart, this draws a line. | Period | Value |']);

        $response = $this->postJson('/jarvis/command', ['command' => 'Plot this sample expense forecast: Last year: 6000000 actual. This year: 2000000 actual. Next year: 2100000 forecast.'])->assertOk();

        $response->assertJsonPath('visual.type', 'chart')->assertJsonPath('visual.chart_type', 'line')
            ->assertJsonPath('visual.is_demo', true)->assertJsonPath('visual.source', 'Illustrative sample data')
            ->assertJsonPath('visual.series.0.data.0.value', 6000000)->assertJsonPath('visual.series.0.data.1.value', 2000000)
            ->assertJsonPath('visual.series.1.data.1.value', 2100000)->assertJsonPath('visual.series.1.forecast', true)
            ->assertJsonPath('speech_text', $speech)->assertJsonPath('message', 'Expenses dropped sharply this year.')
            ->assertJsonPath('response', 'Expenses dropped sharply this year.')->assertJsonMissingPath('display.details')
            ->assertJsonPath('display.visual', $response->json('visual'));
        Http::assertNothingSent();
    }

    #[DataProvider('visualCases')]
    public function test_business_visual_types_reach_the_command_response(array $visual, string $command, string $expectedType): void
    {
        Http::preventStrayRequests();
        JcAgent::fake([new ToolCall('visual', 'RenderVisualization', ['visual_json' => json_encode($visual), 'message' => 'Here is the result.', 'speech_text' => 'Here is the result.']), 'Here is the result.']);

        $this->postJson('/jarvis/command', ['command' => $command])->assertOk()
            ->assertJsonPath('visual.type', $expectedType)->assertJsonPath('visual.is_demo', false)->assertJsonPath('visual.source', 'User-supplied data');
        Http::assertNothingSent();
    }

    public static function visualCases(): array
    {
        $series = [['name' => 'Actual', 'data' => [['label' => 'January', 'value' => 10], ['label' => 'February', 'value' => 20]]]];

        return [
            'monthly deliveries' => [['type' => 'chart', 'chart_type' => 'line', 'title' => 'Monthly deliveries', 'series' => $series], 'Show monthly deliveries: Jan: 10, Feb: 20.', 'chart'],
            'warehouses' => [['type' => 'comparison', 'title' => 'Warehouses', 'series' => $series], 'Compare our warehouses: East: 10, West: 20.', 'chart'],
            'project status' => [['type' => 'chart', 'chart_type' => 'donut', 'title' => 'Project status', 'series' => $series], 'Show project status: Ongoing: 10, Finished: 20.', 'chart'],
            'single value' => [['type' => 'metrics', 'title' => 'Projects', 'metrics' => [['label' => 'Ongoing', 'value' => 10]]], 'How many projects are ongoing? Ongoing: 10.', 'metrics'],
            'raw records' => [['type' => 'table', 'title' => 'Records', 'columns' => ['period', 'value'], 'rows' => [['period' => 'January', 'value' => 10]]], 'Show raw data: Jan: 10.', 'table'],
        ];
    }

    public function test_visual_values_cannot_be_invented_or_disguised_as_real_records(): void
    {
        JcAgent::fake([new ToolCall('visual', 'RenderVisualization', ['visual_json' => json_encode(['type' => 'metrics', 'title' => 'Expenses', 'is_demo' => false, 'source' => 'Company API', 'metrics' => [['label' => 'Expenses', 'value' => 999]]]), 'message' => 'Expenses are high.', 'speech_text' => 'Expenses are high.']), 'I showed the chart.']);

        $this->postJson('/jarvis/command', ['command' => 'Plot these expenses: January: 10, February: 20.'])->assertOk()
            ->assertJsonMissingPath('visual')->assertJsonPath('display.type', 'status')
            ->assertJsonPath('message', 'I could not build a visual from those records. Share the underlying data or ask for a sample.');
    }

    #[DataProvider('unsafeVisuals')]
    public function test_unsafe_or_invalid_visuals_are_rejected(array $changes): void
    {
        $visual = ['type' => 'chart', 'chart_type' => 'line', 'title' => 'Safe chart', 'series' => [['name' => 'Actual', 'data' => [['label' => 'January', 'value' => 10]]]]];

        $this->expectException(ValidationException::class);
        app(VisualizationBuilder::class)->validate(array_replace($visual, $changes));
    }

    public static function unsafeVisuals(): array
    {
        return [
            'unknown type' => [['type' => 'script']], 'unknown chart' => [['chart_type' => 'iframe']],
            'HTML title' => [['title' => '<script>alert(1)</script>']], 'URL title' => [['title' => 'https://example.com']],
            'long title' => [['title' => str_repeat('a', 121)]],
            'invalid numeric value' => [['series' => [['name' => 'Actual', 'data' => [['label' => 'January', 'value' => 'NaN']]]]]],
            'empty series' => [['series' => []]], 'missing all values' => [['series' => [['name' => 'Actual', 'data' => [['label' => 'January', 'value' => null]]]]]],
            'executable point' => [['series' => [['name' => 'Actual', 'data' => [['label' => 'January', 'value' => 10, 'onclick' => 'alert(1)']]]]]],
            'executable action' => [['actions' => [['label' => 'Send', 'action' => 'send_email']]]],
            'oversized points' => [['series' => [['name' => 'Actual', 'data' => array_fill(0, 101, ['label' => 'January', 'value' => 10])]]]],
            'negative donut' => [['chart_type' => 'donut', 'series' => [['name' => 'Actual', 'data' => [['label' => 'January', 'value' => -10]]]]]],
        ];
    }

    public function test_forecast_builder_requires_history_and_forecast_results(): void
    {
        $this->expectException(ValidationException::class);
        app(VisualizationBuilder::class)->buildForecastChart([], [['label' => 'Next month', 'value' => 10]], 'Forecast');
    }

    public function test_raw_data_and_forecast_explanation_followups_reuse_the_validated_visual(): void
    {
        $this->actingAs(User::factory()->create());
        $visual = app(VisualizationBuilder::class)->buildForecastChart([['label' => 'January', 'value' => 10], ['label' => 'February', 'value' => 20]], [['label' => 'March', 'value' => 30]], 'Deliveries', ['explanation' => 'This is a supplied estimate, not a calculated forecast.']);
        JcAgent::fake([new ToolCall('visual', 'RenderVisualization', ['visual_json' => json_encode($visual), 'message' => 'Deliveries may rise.', 'speech_text' => 'Deliveries may rise.']), 'Deliveries may rise.']);
        $first = $this->postJson('/jarvis/command', ['command' => 'Plot deliveries: Jan: 10, Feb: 20 actual, March: 30 forecast.'])->assertOk();
        JcAgent::fake();

        $this->postJson('/jarvis/command', ['command' => 'Show the raw data.', 'conversation_id' => $first->json('conversation_id')])->assertOk()
            ->assertJsonPath('visual.type', 'table')->assertJsonPath('visual.rows.3.value', 30);
        $this->postJson('/jarvis/command', ['command' => 'Why this forecast?', 'conversation_id' => $first->json('conversation_id')])->assertOk()
            ->assertJsonPath('message', 'This is a supplied estimate, not a calculated forecast.');
        JcAgent::assertPromptedTimes(1);
    }

    public function test_invalid_direct_visual_payload_cannot_reach_the_renderer(): void
    {
        $result = app(ConversationResponsePresenter::class)->present(['success' => true, 'response' => 'A chart is ready.', 'visual' => ['type' => 'chart', 'title' => '<script>bad</script>']]);

        $this->assertArrayNotHasKey('visual', $result);
        $this->assertSame('status', $result['display']['type']);
    }

    public function test_forecast_is_calculated_from_history_in_the_application_and_automatically_visualized(): void
    {
        Http::preventStrayRequests();
        $visual = app(VisualizationBuilder::class)->buildLineChart([
            ['label' => '2026-01', 'value' => 100], ['label' => '2026-02', 'value' => 110], ['label' => '2026-03', 'value' => 120],
        ], 'Delivery forecast');
        $visual['forecast_horizon'] = 2;
        JcAgent::fake([new ToolCall('visual', 'RenderVisualization', ['visual_json' => json_encode($visual), 'message' => 'The forecast is ready.', 'speech_text' => 'The forecast is ready.']), 'The forecast is ready.']);

        $this->postJson('/jarvis/command', ['command' => 'Forecast deliveries for the next two months using 2026-01: 100, 2026-02: 110, 2026-03: 120.'])->assertOk()
            ->assertJsonPath('visual.series.1.data.1.label', '2026-04')->assertJsonPath('visual.series.1.data.1.value', 130)
            ->assertJsonPath('visual.series.1.data.2.label', '2026-05')->assertJsonPath('visual.series.1.data.2.value', 140)
            ->assertJsonPath('visual.series.1.forecast', true)->assertJsonPath('visual.is_demo', false)
            ->assertJsonPath('message', 'The forecast continues upward.')->assertJsonPath('speech_text', 'The forecast continues upward.')
            ->assertJsonPath('visual.explanation', 'A simple linear trend using 3 consecutive monthly observations. It assumes the recent trend continues; estimates are uncertain and do not account for seasonality.');
        Http::assertNothingSent();
    }

    public function test_forecast_with_insufficient_history_returns_a_human_answer_without_a_broken_graph(): void
    {
        $visual = app(VisualizationBuilder::class)->buildLineChart([['label' => '2026-01', 'value' => 100], ['label' => '2026-02', 'value' => 110]], 'Forecast');
        $visual['forecast_horizon'] = 1;
        JcAgent::fake([new ToolCall('visual', 'RenderVisualization', ['visual_json' => json_encode($visual), 'message' => 'The forecast is ready.', 'speech_text' => 'The forecast is ready.']), 'Here is the chart.']);

        $this->postJson('/jarvis/command', ['command' => 'Forecast deliveries next month using 2026-01: 100, 2026-02: 110.'])->assertOk()
            ->assertJsonMissingPath('visual')->assertJsonPath('message', "I don't have enough historical data to forecast this yet. Supply at least three consecutive periods.");
    }

    public function test_forecast_never_invents_missing_historical_periods(): void
    {
        $this->expectException(ValidationException::class);
        app(ForecastService::class)->forecast([
            ['label' => '2026-01', 'value' => 100], ['label' => '2026-03', 'value' => 120], ['label' => '2026-04', 'value' => 130],
        ], 1);
    }

    public function test_request_for_real_data_without_samples_does_not_authorize_mock_values(): void
    {
        $this->assertFalse(app(VisualizationBuilder::class)->isSampleRequest('Plot real expenses, not sample data.'));
        JcAgent::fake([new ToolCall('visual', 'RenderVisualization', ['visual_json' => json_encode(['type' => 'metrics', 'title' => 'Expenses', 'is_demo' => true, 'metrics' => [['label' => 'Expenses', 'value' => 100]]]), 'message' => 'Here are expenses.', 'speech_text' => 'Here are expenses.']), 'Done.']);

        $this->postJson('/jarvis/command', ['command' => 'Plot real expenses, not sample data.'])->assertOk()->assertJsonMissingPath('visual');
    }

    public function test_visual_followup_data_cannot_cross_owners_or_new_conversations(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $visual = app(VisualizationBuilder::class)->buildLineChart([['label' => 'January', 'value' => 10], ['label' => 'February', 'value' => 20]], 'Private records');
        JcAgent::fake([new ToolCall('visual', 'RenderVisualization', ['visual_json' => json_encode($visual), 'message' => 'Here is the result.', 'speech_text' => 'Here is the result.']), 'Here is the result.']);
        $first = $this->postJson('/jarvis/command', ['command' => 'Plot January: 10, February: 20.'])->assertOk();
        JcAgent::fake(['No chart was supplied in this conversation.']);

        $this->actingAs(User::factory()->create());
        $this->postJson('/jarvis/command', ['command' => 'Show the raw data.', 'conversation_id' => $first->json('conversation_id')])->assertOk()->assertJsonMissingPath('visual');
        $this->actingAs($owner);
        $this->postJson('/jarvis/command', ['command' => 'Show the raw data.'])->assertOk()->assertJsonMissingPath('visual');
    }

    public function test_legacy_chart_artifact_gets_the_validated_visual_contract_without_losing_its_values(): void
    {
        $result = app(ConversationResponsePresenter::class)->present(['success' => true, 'response' => 'Expenses fell sharply.', 'artifacts' => [[
            'type' => 'chart', 'title' => 'Expenses', 'source' => 'Verified application records', 'data' => ['format' => 'line', 'series' => [
                ['label' => 'Last year', 'value' => 6000000], ['label' => 'This year', 'value' => 2000000], ['label' => 'Next year', 'value' => 2100000, 'kind' => 'forecast'],
            ]],
        ]]]);

        $this->assertSame('line', $result['visual']['chart_type']);
        $this->assertSame('Verified application records', $result['visual']['source']);
        $this->assertTrue($result['visual']['series'][1]['forecast']);
        $this->assertSame(2100000.0, $result['visual']['series'][1]['data'][1]['value']);
        $this->assertFalse($result['visual']['is_demo']);
    }
}
