<?php

namespace App\Ai\Tools;

use App\Services\ArtifactPresentationPlanner;
use App\Services\ForecastService;
use App\Services\VisualizationBuilder;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use JsonException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class RenderVisualization implements Tool
{
    public function __construct(private VisualizationBuilder $builder, private ?ArtifactPresentationPlanner $planner, private string $prompt, private array $context = []) {}

    public function capabilityMetadata(): array
    {
        return ['category' => 'presentation', 'risk' => 'READ_ONLY', 'operations' => ['render_visualization'], 'configuration' => null, 'requires_confirmation' => false];
    }

    public function description(): Stringable|string
    {
        return 'Render a real visual instead of describing a graph. Supply visual_json with type chart, metrics, table, process, timeline or email_preview; chart_type line, bar, area, donut, pie or scatter. Charts require title and series [{name, forecast:false, data:[{label,value}]}]. Use a separate forecast:true series for supplied forecast results. To calculate a forecast, include forecast_horizon from 1 to 12 and one actual series of at least three consecutive monthly YYYY-MM or annual YYYY observations. The application calculates the forecast and connects it to history; never invent forecast numbers. Optional x_axis:{label}, y_axis:{label,format:currency,currency:PHP}, explanation. Supply a short message and separate natural speech_text. Numerical observations must be present in user-supplied records or successful read-only tool results from this request. Explicitly requested sample/demo values are labeled by the server. No API requests or business actions are performed.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        try {
            $raw = (string) $request['visual_json'];
            if (strlen($raw) > 100000) {
                throw ValidationException::withMessages(['visual' => 'The visual is too large.']);
            }
            $input = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($input)) {
                throw ValidationException::withMessages(['visual' => 'Provide a visual object.']);
            }
            $visual = $this->builder->validate([...$input, 'insight' => (string) $request['message']]);
            $rejectSamples = $this->builder->rejectsSamples($this->prompt);
            $demo = ! $rejectSamples && ((bool) ($this->context['provided_data_demo'] ?? false) || $this->builder->isSampleRequest($this->prompt));
            if (! $demo) {
                $priorEvidence = $rejectSamples && ($this->context['provided_data_demo'] ?? false) ? [] : ($this->context['provided_numbers'] ?? []);
                $evidence = [...$this->builder->suppliedNumbers($this->prompt), ...$priorEvidence, ...($this->planner?->retrievedNumbers() ?? [])];
                $numbers = [];
                foreach ($visual['series'] ?? [] as $series) {
                    foreach ($series['data'] as $point) {
                        if ($point['value'] !== null) {
                            $numbers[] = (float) $point['value'];
                        }
                        if (isset($point['x'])) {
                            $numbers[] = (float) $point['x'];
                        }
                    }
                }
                foreach ($visual['metrics'] ?? [] as $metric) {
                    $numbers[] = (float) $metric['value'];
                }
                foreach ($visual['rows'] ?? [] as $row) {
                    foreach ($row as $value) {
                        if (is_numeric($value)) {
                            $numbers[] = (float) $value;
                        }
                    }
                }
                foreach ($numbers as $number) {
                    if (! in_array($number, $evidence, true)) {
                        throw ValidationException::withMessages(['data' => 'Share the underlying records or explicitly request a sample. Do not invent observations or forecasts.']);
                    }
                }
            }
            if (isset($input['forecast_horizon'])) {
                if (! is_int($input['forecast_horizon']) || $input['forecast_horizon'] < 1 || $input['forecast_horizon'] > 12
                    || count($visual['series'] ?? []) !== 1 || ($visual['series'][0]['forecast'] ?? false)
                    || preg_match('/\b(?:forecast|predict|estimate)\b/i', $this->prompt) !== 1) {
                    throw ValidationException::withMessages(['forecast' => 'Request a forecast with one historical series and a horizon from one to twelve periods.']);
                }
                $result = app(ForecastService::class)->forecast($visual['series'][0]['data'], $input['forecast_horizon']);
                $difference = $result['forecast'][0]['value'] - end($result['historical'])['value'];
                $insight = abs($difference) < 0.01 ? 'The forecast is roughly stable.' : ($difference > 0 ? 'The forecast continues upward.' : 'The forecast trends downward.');
                $visual = $this->builder->buildForecastChart($result['historical'], $result['forecast'], $visual['title'],
                    array_intersect_key($visual, array_flip(['subtitle', 'x_axis', 'y_axis'])) + ['explanation' => $result['explanation'], 'insight' => $insight]);
            }
            $visual['is_demo'] = $demo;
            $visual['source'] = $demo ? 'Illustrative sample data' : ($this->planner?->retrievedSource() ?? 'User-supplied data');
            $visual['actions'] = [['label' => 'View data', 'action' => 'show_visual_data']];
            $this->planner?->addVisualization($visual, $visual['insight'], isset($input['forecast_horizon']) ? $visual['insight'] : (string) $request['speech_text']);

            return json_encode(['success' => $this->planner !== null, 'message' => $this->planner ? 'The visual is ready on screen. Return only the short insight, without tables, JSON or a description of the graph.' : 'Visual presentation is unavailable.'], JSON_THROW_ON_ERROR);
        } catch (ValidationException|JsonException $exception) {
            $forecastError = $exception instanceof ValidationException ? ($exception->errors()['forecast'][0] ?? null) : null;
            $this->planner?->rejectVisualization($forecastError);

            return json_encode(['success' => false, 'message' => 'The visual could not be validated. Use safe labels and supplied numerical records, or ask for missing history.', 'errors' => $exception instanceof ValidationException ? array_keys($exception->errors()) : ['visual_json']], JSON_THROW_ON_ERROR);
        }
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'visual_json' => $schema->string()->description('Structured visual JSON. No HTML, URLs, scripts, executable options or invented company values.')->required(),
            'message' => $schema->string()->description('One short useful insight; do not describe the chart or repeat every value.')->required(),
            'speech_text' => $schema->string()->description('One or two natural spoken sentences explaining the insight, with no JSON, markup or visual labels.')->required(),
        ];
    }
}
