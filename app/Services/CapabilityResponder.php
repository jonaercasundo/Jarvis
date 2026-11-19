<?php

namespace App\Services;

use App\Ai\Agents\JcAgent;
use App\Models\JarvisSetting;
use Illuminate\Http\Request;
use Laravel\Ai\Tools\Request as ToolRequest;
use Laravel\Ai\Tools\ToolNameResolver;
use ReflectionClass;
use ReflectionNamedType;
use Throwable;

class CapabilityResponder
{
    public function __construct(private CapabilityRegistry $registry, private CapabilityExecutionPolicy $policy, private SourceCodeInspector $inspector, private WorkflowOwner $owner, private CapabilityVisualPresenter $visuals) {}

    /** Handle informational and demonstration requests before operational intent shortcuts or the AI provider. */
    public function respond(Request $request, string $prompt, ?GoDaddyEmailService $email = null): ?array
    {
        $conversationId = $request->input('conversation_id');
        $ownerKey = $this->owner->key($request);
        $context = $request->session()->get('jarvis_capability_presentation');
        $previousCategory = is_array($context) && ($context['conversation_id'] ?? null) === $conversationId
            && ($context['owner_key'] ?? null) === $ownerKey ? ($context['category'] ?? null) : null;
        if (is_array($context) && ($context['conversation_id'] ?? null) === $conversationId
            && ($context['owner_key'] ?? null) === $ownerKey
            && in_array($request->input('visual_category'), ['email', 'data', 'automation', 'preferences', 'developer'], true)) {
            $previousCategory = $request->input('visual_category');
        }
        $pending = $request->session()->get('jarvis_capability_demo');
        if (preg_match('/^\s*(?:yes|yes please|sure|go ahead)\s*[.!]*$/i', $prompt)
            && is_array($pending) && ($pending['expires_at'] ?? 0) >= now()->timestamp
            && ($pending['conversation_id'] ?? null) === $conversationId
            && ($pending['owner_key'] ?? null) === $this->owner->key($request)) {
            $prompt = $pending['command'];
            $request->session()->forget('jarvis_capability_demo');
        }
        if (preg_match('/^\s*(?:please\s+)?show (?:me )?(?:our|my|the) (?:real )?delivery trend\b/i', $prompt)
            && ! app(WorkflowOperationsService::class)->capability($ownerKey)['configured']) {
            $request->session()->forget('jarvis_capability_demo');
            $request->session()->forget('jarvis_capability_presentation');

            return ['success' => true, 'type' => 'data_unavailable', 'response' => 'I do not have access to your delivery history here. Share dated delivery totals so I can help you understand the trend.', 'conversation_id' => $conversationId];
        }
        if (! $this->policy->isInformational($prompt) && ! $this->policy->isDemonstration($prompt) && ! $this->visuals->isPresentationRequest($prompt)) {
            $request->session()->forget('jarvis_capability_demo');
            $request->session()->forget('jarvis_capability_presentation');

            return null;
        }
        $request->session()->forget('jarvis_capability_demo');
        $agent = $this->agent($request, $prompt, $email ?? app(GoDaddyEmailService::class));
        $capabilities = $this->registry->discover($agent);
        $category = $this->visuals->category($prompt, $previousCategory);
        $notice = $this->visuals->connectionNotice($capabilities, $prompt);
        $category ??= $notice ? 'automation' : null;
        $request->session()->put('jarvis_capability_presentation', ['category' => $category, 'conversation_id' => $conversationId, 'owner_key' => $ownerKey]);
        $liveDemoRequested = $this->policy->isExplicitSafeDemo($prompt) || preg_match('/\blive (?:demo|demonstration)\b/i', $prompt);
        $technical = $this->visuals->isTechnical($prompt, $agent->preferences());
        if (! $liveDemoRequested && ! $technical) {
            $visual = $this->visuals->present($capabilities, $agent->preferences(), $category, preg_match('/\bjust show me\b/i', $prompt) === 1);
            if ($notice) {
                $visual['summary'] = $notice;
                $visual['response'] = $notice;
                unset($visual['live_action']);
            }

            return ['success' => true, 'conversation_id' => $conversationId, 'wake_name_variants' => $agent->preferences()->wake_name_variants ?? [],
                ...$visual];
        }
        $response = ['success' => true, 'type' => 'capabilities', 'response' => $this->registry->explain($capabilities),
            'capabilities' => $capabilities, 'conversation_id' => $conversationId,
            'wake_name_variants' => $agent->preferences()->wake_name_variants ?? []];
        if (! $technical) {
            unset($response['capabilities']);
            $response['type'] = 'live_demo';
            $response['response'] = '';
        }
        $preference = $agent->preferences()->capability_demonstrations ?? 'ask_first';
        $demoRequested = $this->policy->isDemonstration($prompt);
        $implementationQuestion = preg_match('/\b(?:how (?:does|do)|where (?:is|are)|implemented|implementation|function work)\b/i', $prompt) === 1;
        if ($implementationQuestion && ! $demoRequested) {
            return $this->implementationResponse($response, $agent, $prompt);
        }
        if (! $demoRequested) {
            return $response;
        }
        $requestedRiskyTool = collect($capabilities['tools'])->contains(fn (array $tool): bool => $tool['risk'] !== 'READ_ONLY' && stripos($prompt, $tool['name']) !== false);
        if ($demoRequested && ($requestedRiskyTool || preg_match('/\b(?:send|sending|delet(?:e|ing|ion)|remov(?:e|ing)|writ(?:e|ing)|edit(?:ing)?|modif(?:y|ying)|creat(?:e|ing)|destructive)\b/i', $prompt))) {
            $response['response'] = 'I will not send, stage, delete or change anything merely for a demonstration. These actions have side effects. For a real email request, provide the recipient and content; existing Settings permissions and confirmation-code rules still apply.';

            return $response;
        }
        if ($preference === 'never_demo') {
            $response['response'] .= ' Live demonstrations are switched off in Settings.';

            return $response;
        }
        if ($preference === 'ask_first' && (! $demoRequested || ! $this->policy->isExplicitSafeDemo($prompt))) {
            $demoCommand = $demoRequested && preg_match('/\bemail\b/i', $prompt) ? 'Demo email search.' : 'Demo source inspection.';
            $request->session()->put('jarvis_capability_demo', ['command' => $demoCommand, 'expires_at' => now()->addMinutes(3)->timestamp,
                'conversation_id' => $conversationId, 'owner_key' => $agent->ownerKey()]);
            $response['response'] .= ' Shall I try '.($demoCommand === 'Demo email search.' ? 'searching your inbox' : 'inspecting my code').'?';

            return $response;
        }
        $emailDemo = $demoRequested && preg_match('/\bemail\b/i', $prompt);
        $operation = $emailDemo ? 'search' : 'find_class';
        $toolMetadata = collect($capabilities['tools'])->first(fn (array $tool): bool => in_array($operation, $tool['operations'], true) && $tool['risk'] === 'READ_ONLY');
        if (! $toolMetadata || ! $toolMetadata['executable_now']) {
            $response['response'] .= ' This live demonstration is unavailable. Check your connections and permissions in Settings.';

            return $response;
        }
        $demoPrompt = $emailDemo ? 'Demo email search.' : 'Demo source inspection.';
        $demoAgent = $this->agent($request, $demoPrompt, $email ?? app(GoDaddyEmailService::class));
        $tool = collect($demoAgent->tools())->first(fn ($tool): bool => ToolNameResolver::resolve($tool) === $toolMetadata['name']);
        try {
            $result = json_decode((string) $tool->handle(new ToolRequest($emailDemo ? ['limit' => 3] :
                ['operation' => 'find_class', 'query' => 'JcAgent', 'path' => 'app/Ai'])), true, 512, JSON_THROW_ON_ERROR);
            if (($result['success'] ?? true) === false || (! $emailDemo && ($result['matches'] ?? []) === [])) {
                $response['response'] .= "\n\nThe demonstration did not complete successfully. No success is being claimed.";
            } else {
                $response['demonstration'] = ['tool' => $toolMetadata['name'], 'risk' => 'READ_ONLY', 'simulated' => false, 'result' => $result];
                $response['response'] = 'Completed a real '.($emailDemo ? 'email search' : 'source inspection').'. '.
                        ($emailDemo ? 'Found '.($result['count'] ?? count($result['emails'] ?? [])).' matching messages.' : 'Located JcAgent in app/Ai/Agents/JcAgent.php. No files were changed.');
                $response['type'] = 'live_demo';
                if ($emailDemo) {
                    $response['emails'] = $result['emails'] ?? [];
                }
                unset($response['capabilities']);
            }
        } catch (Throwable) {
            $response['response'] .= "\n\nThe demonstration could not be completed. No success is being claimed.";
        }

        return $response;
    }

    public function agent(Request $request, string $prompt, GoDaddyEmailService $email): JcAgent
    {
        $settings = JarvisSetting::current();

        return new JcAgent($email, $settings->ai_name ?: 'JC', $settings->learned_memory ?? '',
            agentStatusService: app(WorkflowAgentStatusService::class), workflowOwnerKey: $this->owner->key($request),
            userPrompt: $prompt, settings: $settings);
    }

    private function implementationResponse(array $response, JcAgent $agent, string $prompt): array
    {
        $metadata = collect($response['capabilities']['tools'])->first(fn (array $tool): bool => stripos($prompt, $tool['name']) !== false);
        if (! $metadata && preg_match('/\b(?:search|find)\b.*\bemail\b|\bemail search\b/i', $prompt)) {
            $metadata = collect($response['capabilities']['tools'])->first(fn (array $tool): bool => $tool['category'] === 'email' && in_array('search', $tool['operations'], true));
        }
        if (! $metadata) {
            $response['response'] .= "\n\nI cannot identify a matching registered tool from this question. Name a tool or source class for a targeted read-only inspection.";

            return $response;
        }
        $tool = collect($agent->registeredTools())->first(fn ($tool): bool => ToolNameResolver::resolve($tool) === $metadata['name']);
        $reflection = new ReflectionClass($tool);
        $method = $reflection->getMethod('handle');
        $source = $this->inspector->inspect('read_file', path: $metadata['implementation'],
            startLine: $method->getStartLine(), lineCount: min(120, $method->getEndLine() - $method->getStartLine() + 1));
        $evidence = [$source];
        $chain = [$metadata['name'].'::handle'];
        preg_match_all('/->([A-Za-z][A-Za-z0-9_]*)\(/', $source['source'], $calls);
        foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();
            if (! $type instanceof ReflectionNamedType || ! str_starts_with($type->getName(), 'App\\Services\\')) {
                continue;
            }
            $service = new ReflectionClass($type->getName());
            foreach (array_unique($calls[1]) as $call) {
                if (! $service->hasMethod($call)) {
                    continue;
                }
                $implementation = $service->getMethod($call);
                $path = substr(str_replace('\\', '/', $implementation->getFileName()), strlen(str_replace('\\', '/', base_path())) + 1);
                $evidence[] = $this->inspector->inspect('read_file', path: $path, startLine: $implementation->getStartLine(),
                    lineCount: min(120, $implementation->getEndLine() - $implementation->getStartLine() + 1));
                $chain[] = $implementation->getDeclaringClass()->getName().'::'.$call;
                break;
            }
            if (count($evidence) >= 3) {
                break;
            }
        }
        $response['source_inspection'] = $evidence;
        $response['response'] = $metadata['name'].' is implemented in '.$metadata['implementation'].'. '.
            $metadata['description']."\n\nVerified call chain: ".implode(' → ', $chain).'. '.
            'Its tool parameters are '.implode(', ', array_keys($metadata['schema'])).'. '.
            'The attached source excerpts were inspected read-only; literal values/comments are masked, and no operational tool was executed.';

        return $response;
    }
}
