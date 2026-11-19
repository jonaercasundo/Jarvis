<?php

namespace App\Services;

use Illuminate\Support\Str;

class ArtifactPresentationPlanner
{
    private const MAX_ARTIFACTS = 3;

    /** @var array<int, array<string, mixed>> */
    private array $artifacts = [];

    private array $visualResponse = [];

    /** @var list<float> */
    private array $retrievedNumbers = [];

    private ?string $retrievedSource = null;

    private array $retrievedData = [];

    private ?string $retrievedAt = null;

    private ?array $visualReport = null;

    private bool $reportFailed = false;

    public function noteReportFailure(): void
    {
        $this->reportFailed = true;
    }

    public function reportFailed(): bool
    {
        return $this->reportFailed;
    }

    /** Trust only data returned successfully by a server-side tool in this request. */
    public function rememberRetrievedData(array $data, string $source, ?string $retrievedAt = null): void
    {
        array_walk_recursive($data, function (mixed $value, mixed $key): void {
            if ((is_int($value) || is_float($value)) && ! str_ends_with((string) $key, '_id')) {
                $this->retrievedNumbers[] = (float) $value;
            }
        });
        $this->retrievedSource = $source;
        $this->retrievedData = $data;
        $this->retrievedAt = $retrievedAt ?? now()->toIso8601String();
    }

    public function retrievedData(): array
    {
        return $this->retrievedData;
    }

    public function reportSource(): array
    {
        return ['system' => $this->retrievedSource ?? 'User-supplied data', 'retrieved_at' => $this->retrievedAt ?? now()->toIso8601String()];
    }

    /** Accept only specifications validated at the server-side tool/service boundary. */
    public function addVisualReport(array $artifact): void
    {
        $this->visualReport = $artifact;
    }

    public function visualReport(): ?array
    {
        return $this->visualReport;
    }

    /** @return list<float> */
    public function retrievedNumbers(): array
    {
        return $this->retrievedNumbers;
    }

    public function retrievedSource(): ?string
    {
        return $this->retrievedSource;
    }

    public function addVisualization(array $visual, string $message, string $speech): void
    {
        $this->visualResponse = ['visual' => $visual, 'message' => $message, 'speech_text' => mb_substr($speech, 0, 280)];
    }

    public function rejectVisualization(?string $message = null): void
    {
        if (! isset($this->visualResponse['visual'])) {
            $this->visualResponse = ['visual_error' => true, 'visual_error_message' => $message];
        }
    }

    public function visualResponse(): array
    {
        return $this->visualResponse;
    }

    /** @param array<int, array<string, mixed>> $agents */
    public function addAgentStatus(array $agents): void
    {
        if ($agents === []) {
            return;
        }

        $needsAttention = collect($agents)->contains(
            fn (array $agent): bool => in_array($agent['status'] ?? '', ['needs_attention', 'error'], true),
        );

        $this->add([
            'type' => 'agent_status',
            'title' => count($agents) === 1 ? (string) ($agents[0]['label'] ?? 'Agent status') : 'Agent status',
            'subtitle' => 'Current workflow Agent activity',
            'owner_agent' => 'orchestrator',
            'source' => 'JARVIS workflow task records',
            'status' => 'ready',
            'data_kind' => 'execution_state',
            'priority' => $needsAttention ? 'high' : 'normal',
            'data' => ['agents' => array_slice($agents, 0, 100)],
        ]);
    }

    /** @param array{to: string, subject: string, body: string, confirmation_code: string} $email */
    public function addEmailDraft(array $email): void
    {
        $this->add([
            'type' => 'email',
            'title' => 'Email draft',
            'subtitle' => $email['subject'],
            'owner_agent' => 'secretary',
            'source' => 'Secretary Agent',
            'status' => 'awaiting_approval',
            'data_kind' => 'generated_content',
            'data' => $email,
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    public function artifacts(): array
    {
        return $this->artifacts;
    }

    /** @param array<string, mixed> $artifact */
    private function add(array $artifact): void
    {
        if (count($this->artifacts) >= self::MAX_ARTIFACTS) {
            return;
        }

        $this->artifacts[] = [
            'id' => (string) Str::uuid(),
            'type' => $artifact['type'],
            'title' => mb_substr(trim((string) $artifact['title']), 0, 120),
            'subtitle' => mb_substr(trim((string) ($artifact['subtitle'] ?? '')), 0, 180),
            'owner_agent' => $artifact['owner_agent'] ?? null,
            'source' => mb_substr(trim((string) ($artifact['source'] ?? '')), 0, 120),
            'status' => $artifact['status'] ?? 'ready',
            'data_kind' => $artifact['data_kind'] ?? 'retrieved_data',
            'priority' => $artifact['priority'] ?? 'normal',
            'generated_at' => now()->toIso8601String(),
            'data' => $artifact['data'] ?? [],
            'actions' => [],
            'refreshable' => false,
        ];
    }
}
