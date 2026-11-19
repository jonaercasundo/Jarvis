<?php

namespace App\Ai\Tools;

use App\Services\ArtifactPresentationPlanner;
use App\Services\ArtifactValidationService;
use App\Services\VisualizationBuilder;
use App\Services\VisualReportService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use Throwable;

class RenderVisualReport implements Tool
{
    public function __construct(private ArtifactValidationService $validator, private ?ArtifactPresentationPlanner $planner, private string $prompt) {}

    public function capabilityMetadata(): array
    {
        return ['category' => 'presentation', 'risk' => 'READ_ONLY', 'operations' => ['render_visual_report'], 'configuration' => null, 'requires_confirmation' => false];
    }

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Publish a reusable Visual Report behind a View Visual Report button. '.app(VisualReportService::class)->instructions().' Source paths are relative to the data returned by the successful read tool, not its metadata. No additional business API requests are made. Suitable for Operations, Finance, HR, Sales and Secretary reports.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        try {
            $raw = (string) ($request['artifact_json'] ?? '');
            if (strlen($raw) > 100000 || ! $this->planner) {
                throw new \RuntimeException('Report presentation is unavailable or too large.');
            }
            $input = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($input)) {
                throw new \RuntimeException('A report object is required.');
            }
            $artifact = $this->validator->validate($input, $this->planner->retrievedData(), $this->planner->reportSource(), app(VisualizationBuilder::class)->suppliedNumbers($this->prompt));
            if (isset($this->planner->retrievedData()['project']) || isset($this->planner->retrievedData()['projects'])) {
                $artifact = app(VisualReportService::class)->operations($this->planner->retrievedData(), $this->prompt, $this->planner->reportSource(), $artifact) ?? $artifact;
            }
            $this->planner->addVisualReport($artifact);

            return json_encode(['success' => true, 'message' => 'The visual report is ready. Give one concise answer; the report contains the detailed values.'], JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            $this->planner?->noteReportFailure();
            Log::notice('Artifact validation failed', $this->validator->diagnostics($exception));

            return json_encode(['success' => false, 'message' => 'No presentation was added. Finish the normal business answer using verified data. Omit internal presentation diagnostics.'], JSON_THROW_ON_ERROR);
        }
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'artifact_json' => $schema->string()->description('Declarative visual_report or project_status JSON only; bind values to the verified result. No HTML, JavaScript, credentials or invented values.')->required(),
        ];
    }
}
