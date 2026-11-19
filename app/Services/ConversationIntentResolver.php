<?php

namespace App\Services;

use App\Models\JarvisSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ConversationIntentResolver
{
    public function __construct(private WorkflowOwner $owner, private CapabilityRegistry $capabilities, private SourceSecretRedactor $redactor) {}

    /** @return array{command: string, response: ?array<string, mixed>} */
    public function resolve(Request $request, string $command): array
    {
        $state = $this->state($request);
        $request->attributes->remove('jarvis_operations_project');
        $request->attributes->remove('jarvis_resolved_operations_project');
        $settings = JarvisSetting::current();
        $depth = $this->depth($command, $settings);
        $request->attributes->set('jarvis_response_depth', $depth);
        if (! isset($state['pending']) && is_array($state['last_visual'] ?? null)) {
            $visual = $state['last_visual'];
            if (preg_match('/^\s*(?:show (?:me )?(?:the )?(?:raw )?data|show (?:the )?table)\s*[.!?]*$/i', $command) && ($visual['type'] ?? '') === 'chart') {
                $rows = [];
                foreach ($visual['series'] as $series) {
                    foreach ($series['data'] as $point) {
                        $rows[] = ['series' => $series['name'], 'period' => $point['label'], 'value' => $point['value'], 'kind' => ($series['forecast'] ?? false) ? 'Forecast' : 'Actual'];
                    }
                }
                $table = app(VisualizationBuilder::class)->buildTable(['series', 'period', 'value', 'kind'], array_slice($rows, 0, 100), $visual['title']);
                if (count($rows) > 100) {
                    $table['subtitle'] = 'Showing the first 100 of '.count($rows).' observations. Expand the chart to see its complete Data view.';
                }

                return $this->result($command, ['response' => 'Here are the values behind the chart.', 'message' => 'Here are the values behind the chart.', 'visual' => [...$table, 'is_demo' => $visual['is_demo'] ?? false, 'source' => $visual['source'] ?? '']]);
            }
            if (preg_match('/^\s*why (?:this|the) forecast\s*[.!?]*$/i', $command) && ! empty($visual['explanation'])) {
                return $this->result($command, ['response' => $visual['explanation']]);
            }
        }
        if ($request->filled('clarification_id') && ($state['pending']['id'] ?? null) !== $request->input('clarification_id')) {
            return $this->result($command, ['type' => 'clarification_expired', 'response' => 'That question has expired. Please repeat your request.']);
        }
        if (preg_match('/^\s*(?:cancel|never mind|nevermind)\s*[.!]*$/i', $command) && isset($state['pending'])) {
            unset($state['pending']);
            $this->save($request, $state);

            return $this->result($command, ['type' => 'clarification_cancelled', 'response' => 'Cancelled.']);
        }
        if ($default = $this->explicitEmailPreference($command)) {
            $notes = preg_split('/\R+/', $settings->learned_memory ?? '') ?: [];
            $notes = array_values(array_filter($notes, fn (string $note): bool => trim($note) !== '' && ! preg_match('/^\s*(?:[-*]\s*)?check_email_default\s*[:=]/i', $note)));
            $notes[] = 'check_email_default = '.$default;
            $settings->update(['learned_memory' => implode(PHP_EOL, array_slice($notes, -20))]);
            unset($state['pending']);
            $this->save($request, $state);

            return $this->result($command, ['type' => 'preference_saved', 'response' => 'Remembered. “Check my email” will show '.str_replace('_', ' ', $default).' emails.']);
        }
        if (isset($state['pending'])) {
            $pending = $state['pending'];
            if (($pending['expires_at'] ?? 0) < now()->timestamp) {
                unset($state['pending']);
                $this->save($request, $state);
                if ($request->filled('clarification_id')) {
                    return $this->result($command, ['type' => 'clarification_expired', 'response' => 'That question has expired. Please repeat your request.']);
                }
            } elseif (! $this->startsNewRequest($command)) {
                $answer = trim($command, " \t\n\r\0\x0B.!?");
                $selection = collect($pending['options'])->first(fn (array $option): bool => in_array(mb_strtolower($answer), [mb_strtolower($option['value']), mb_strtolower($option['label'])], true));
                $value = $selection['value'] ?? $answer;
                if ($value === '' || mb_strlen($value) > 160 || $this->containsSideEffectIntent($value)) {
                    return $this->result($command, $this->questionPayload($pending));
                }
                if ($pending['kind'] === 'forecast' && mb_strtolower($value) === 'other') {
                    return $this->ask($request, $state, $pending['kind'], 'What should I forecast?', [], $pending['original']);
                }
                unset($state['pending']);
                $this->save($request, $state);
                if ($pending['kind'] === 'email') {
                    $choice = $this->emailChoice($value);
                    if ($choice === null) {
                        $command = 'Find emails '.(preg_match('/\b(?:from|about|on)\b/i', $value) ? $value : 'about '.$value).'.';
                    } else {
                        $request->attributes->set('jarvis_email_focus', $choice);
                        $command = $this->emailCommand($choice);
                    }
                } elseif ($pending['kind'] === 'agent') {
                    $command = $pending['original']."\nUser clarification: ".$value;
                } elseif ($pending['kind'] === 'project') {
                    $state['project'] = preg_replace('/^project\s+/i', '', $value);
                    $command = 'Show deliveries for Project '.$state['project'].'.';
                } else {
                    $command = $pending['original'].' Focus: '.$value.'.';
                    $state['metric'] = str_replace('_', ' ', $value);
                    $request->attributes->set('jarvis_business_request', true);
                }
            } else {
                unset($state['pending']);
            }
        }

        $isDepthFollowup = preg_match('/^\s*(?:explain(?: more| technically)?|just give me (?:the )?answer|just show me)\s*[.!?]*$/i', $command) === 1;
        if ($isDepthFollowup && isset($state['last_result']) && ! in_array($state['last_type'] ?? '', ['visual_demo', 'capabilities', 'capability_summary', 'live_demo'], true)) {
            $request->attributes->set('jarvis_explanation_only', true);
            $request->attributes->set('jarvis_task_context', array_intersect_key($state, array_flip(['project', 'metric', 'period', 'last_command', 'last_result'])));
            if (($state['last_type'] ?? '') === 'data_unavailable') {
                $response = $state['last_result'];
                if ($depth === 'technical') {
                    $response .= ' No registered chat tool retrieves these business records.';
                }

                return $this->result($command, ['type' => $state['last_type'], 'response' => $response, 'response_depth' => $depth]);
            }

            return ['command' => $command, 'response' => null];
        }

        $deliveryFollowup = preg_match('/^\s*(?:and\s+)?how many\s+(?:(?:have been|were|are|have already been|have)\s+)?delivered\s*[.!?]*$/i', $command) === 1;
        if ($deliveryFollowup && ! $this->containsSideEffectIntent($command)) {
            $project = $this->resolvedProject($state['resolved_project'] ?? null);
            if ($project !== null && ($state['domain'] ?? '') === 'business') {
                $request->attributes->set('jarvis_operations_project', $project);
                $command = 'Show completed deliveries for the current project.';
                $state['metric'] = 'deliveries';
                $request->attributes->set('jarvis_business_request', true);
            } else {
                return $this->ask($request, $state, 'project', 'Which project should I check?', [], $command);
            }
        } elseif ($this->changesProjectOrDomain($command)) {
            unset($state['resolved_project'], $state['project']);
        } elseif (preg_match('/\b(?:current|this|that|same)\s+project\b/i', $command)
            && ! $this->containsSideEffectIntent($command) && ($state['domain'] ?? '') === 'business') {
            $project = $this->resolvedProject($state['resolved_project'] ?? null);
            if ($project !== null) {
                $request->attributes->set('jarvis_operations_project', $project);
            }
        }

        $this->extractContext($command, $state);
        $providedNumbers = app(VisualizationBuilder::class)->suppliedNumbers($command);
        if ($providedNumbers !== [] && ($this->hasProvidedHistory($command) || preg_match('/[:=]\s*-?\d[\d,]*(?:\.\d+)?/u', $command))) {
            $state['provided_numbers'] = $providedNumbers;
            $state['provided_data_demo'] = app(VisualizationBuilder::class)->isSampleRequest($command);
        }
        $emailRequest = preg_match('/^\s*(?:find|show|list|check)\b.*\b(?:emails?|inbox|mail)\b/i', $command) === 1;
        if ($emailRequest) {
            $state['domain'] = 'email';
            unset($state['metric'], $state['period'], $state['task']);
        }
        if (preg_match('/^\s*(?:please\s+)?check\s+(?:(?:my|our|the)\s+)?(?:emails?|inbox|mail)\s*[.!?]*$/i', $command)) {
            $default = preg_match('/\bcheck_email_default\s*[:=]\s*(important_unread|important|unread|needs_reply|today)\b/i', $settings->learned_memory ?? '', $matches) ? mb_strtolower($matches[1]) : null;
            if ($default === null) {
                return $this->ask($request, $state, 'email', 'What should I look for?', $this->emailOptions(), $command);
            }
            $request->attributes->set('jarvis_email_focus', $default);
            $command = $this->emailCommand($default);
        }
        if (preg_match('/^\s*(?:please\s+)?(?:analy[sz]e|review)\s+(?:(?:our|my|the)\s+)?(projects?|operations?|business|data|something|anything)\s*[.!?]*$/i', $command, $matches)) {
            $projects = str_starts_with(mb_strtolower($matches[1]), 'project');

            return $this->ask($request, $state, 'analysis', $projects ? 'What would you like to focus on?' : 'What should I focus on?',
                $this->options($projects ? ['Progress', 'Delays', 'Deliveries', 'Budget', 'Overall'] : ['Projects', 'Deliveries', 'Inventory', 'Overall']), $command);
        }
        if (preg_match('/^\s*(?:please\s+)?(?:show me (?:a )?)?forecast(?:\s+(?:(?:our|my|the)\s+)?data|\s+next month)?\s*[.!?]*$/i', $command)) {
            if (($state['domain'] ?? '') === 'business' && ! empty($state['metric']) && ! in_array($state['metric'], ['projects', 'operations', 'business'], true)) {
                $command = 'Forecast '.$state['metric'].' '.($state['period'] ?? 'next month').$this->projectSuffix($state).'.';
            } else {
                return $this->ask($request, $state, 'forecast', 'What should I forecast?', $this->options(['Deliveries', 'Project Completion', 'Inventory', 'Sales', 'Other']), $command);
            }
        }
        if (preg_match('/^\s*(?:show\s+(?:its|their)\s+)?deliveries\s*[.!?]*$/i', $command)) {
            if (empty($state['project'])) {
                return $this->ask($request, $state, 'project', 'Which project should I check?', [], $command);
            }
            $state['metric'] = 'deliveries';
            $resolvedProject = $this->resolvedProject($state['resolved_project'] ?? null);
            if ($resolvedProject !== null && ($state['domain'] ?? '') === 'business') {
                $request->attributes->set('jarvis_operations_project', $resolvedProject);
                $command = 'Show deliveries for the current project.';
            } else {
                $command = 'Show deliveries'.$this->projectSuffix($state).'.';
            }
        }
        if (preg_match('/^\s*(?:no[,\s]+)?(?:last month|this month|this year|last year|today|this week)\s*[.!?]*$/i', $command) && ! empty($state['metric'])) {
            $state['period'] = mb_strtolower(trim(preg_replace('/^no[,\s]+/i', '', $command), '.!? '));
            $command = ($state['task'] ?? 'Show').' '.$state['metric'].$this->projectSuffix($state).' '.$state['period'].'.';
        }
        $this->extractContext($command, $state);
        $business = ! $emailRequest && ($request->attributes->get('jarvis_business_request') || preg_match('/^\s*(?:show|list|find|check|analy[sz]e|review|forecast|compare|how are|how is)\b.*\b(?:projects?|deliveries|delivery|inventory|sales|operations?|warehouses?|budget|completion)\b/i', $command));
        if ($business && ! $this->containsSideEffectIntent($command)) {
            $state['domain'] = 'business';
            $state['period'] ??= ($state['task'] ?? '') === 'Forecast' ? 'next month' : 'this month';
            $request->attributes->set('jarvis_business_request', true);
            $state['last_command'] = $this->redactor->text($command);
            $this->save($request, $state);
            $request->attributes->set('jarvis_task_context', array_intersect_key($state, array_flip(['project', 'metric', 'period', 'last_command'])));
            if (! $this->hasProvidedHistory($command) && empty($state['provided_numbers']) && ! $this->hasBusinessReadTool($request, $command)) {
                $scope = ! empty($state['project']) ? ' for Project '.$state['project'] : '';
                $topic = $state['metric'] ?? 'business';

                return $this->result($command, ['type' => 'data_unavailable', 'response' => ucfirst($topic).' data'.$scope.' is not available in this chat. Share the records to continue.', 'response_depth' => $depth]);
            }
        }
        $state['last_command'] = $this->redactor->text($command);
        $this->save($request, $state);
        $request->attributes->set('jarvis_task_context', array_intersect_key($state, array_flip(['project', 'metric', 'period', 'provided_numbers', 'provided_data_demo'])));

        return ['command' => $command, 'response' => null];
    }

    /** Keep minimal response evidence server-side; no email bodies, credentials or frontend-supplied context. */
    public function rememberResult(Request $request, array $response): void
    {
        if (($response['type'] ?? '') === 'clarification') {
            $this->save($request, $this->state($request), $response['conversation_id'] ?? null);

            return;
        }
        if (! ($response['success'] ?? false) || in_array($response['type'] ?? '', ['clarification', 'clarification_expired', 'clarification_cancelled', 'preference_saved'], true)) {
            return;
        }
        $state = $this->state($request);
        $state['last_result'] = $this->redactor->text(mb_substr((string) ($response['response'] ?? ''), 0, 4000));
        $state['last_type'] = $response['type'] ?? 'ai_response';
        $resolvedProject = $this->resolvedProject($request->attributes->get('jarvis_resolved_operations_project'));
        if ($resolvedProject !== null) {
            $state['resolved_project'] = $resolvedProject;
            $state['project'] = $resolvedProject['project_name'];
            $state['domain'] = 'business';
        }
        if (is_array($response['visual'] ?? null) && ($response['visual']['type'] ?? '') !== 'table') {
            $state['last_visual'] = $response['visual'];
        }
        $this->save($request, $state, $response['conversation_id'] ?? null);
    }

    /** Used by the registered clarification tool; model-supplied text remains display data. */
    public function askFromAgent(Request $request, string $question, array $options): array
    {
        $resolution = $this->ask($request, $this->state($request), 'agent', $question, $options,
            $request->attributes->get('jarvis_resolved_command', $request->input('command', $request->input('message', ''))));
        $request->attributes->set('jarvis_agent_clarification', $resolution['response']);

        return $resolution['response'];
    }

    private function state(Request $request): array
    {
        $state = $request->session()->get('jarvis_smart_context', []);
        if (! is_array($state) || ($state['owner_key'] ?? null) !== $this->owner->key($request)
            || ($state['conversation_id'] ?? null) !== $request->input('conversation_id') || ($state['expires_at'] ?? 0) < now()->timestamp) {
            return [];
        }

        return $state;
    }

    private function save(Request $request, array $state, ?string $conversationId = null): void
    {
        $request->session()->put('jarvis_smart_context', [...$state, 'owner_key' => $this->owner->key($request),
            'conversation_id' => $conversationId ?? $request->input('conversation_id'), 'expires_at' => now()->addHours(2)->timestamp]);
    }

    private function ask(Request $request, array $state, string $kind, string $question, array $options, string $original): array
    {
        $question = $this->redactor->text($question);
        $options = array_map(fn (array $option): array => ['label' => $this->redactor->text($option['label']), 'value' => $this->redactor->text($option['value'])], $options);
        $state['pending'] = ['id' => (string) Str::uuid(), 'kind' => $kind, 'question' => $question, 'options' => $options,
            'original' => $this->redactor->text($original), 'expires_at' => now()->addMinutes(10)->timestamp];
        $request->session()->forget('jarvis_capability_demo');
        $this->save($request, $state);

        return $this->result($original, $this->questionPayload($state['pending']));
    }

    private function questionPayload(array $pending): array
    {
        return ['type' => 'clarification', 'question' => $pending['question'], 'response' => $pending['question'], 'options' => $pending['options'],
            'allow_custom_answer' => true, 'clarification_id' => $pending['id']];
    }

    private function result(string $command, array $response): array
    {
        return ['command' => $command, 'response' => ['success' => true, ...$response]];
    }

    private function options(array $labels): array
    {
        return array_map(fn (string $label): array => ['label' => $label, 'value' => mb_strtolower(str_replace(' ', '_', $label))], $labels);
    }

    private function emailOptions(): array
    {
        return $this->options(['Important', 'Unread', 'Needs Reply', 'Today']);
    }

    private function emailChoice(string $answer): ?string
    {
        foreach (['important_unread', 'important', 'unread', 'needs_reply', 'today'] as $choice) {
            if (preg_match('/\b'.str_replace('_', '[_ ]+', $choice).'\b/i', $answer)) {
                return $choice;
            }
        }

        return null;
    }

    private function emailCommand(string $choice): string
    {
        return match ($choice) {
            'important', 'important_unread' => 'Show important unread emails.',
            'unread' => 'Show my unread emails.',
            'needs_reply' => 'Is there anything I need to respond to?',
            'today' => 'Find emails today.',
        };
    }

    private function explicitEmailPreference(string $command): ?string
    {
        if (! preg_match('/^\s*(?:whenever|when) I say [“"\x27]?check my email[”"\x27]?,?\s+(?:please\s+)?(?:just\s+|only\s+)?show (important unread|important|unread|today[\x27’]?s) (?:emails|messages)\s*[.!]*$/i', $command, $matches)) {
            return null;
        }

        return str_starts_with(mb_strtolower($matches[1]), 'today') ? 'today' : str_replace(' ', '_', mb_strtolower($matches[1]));
    }

    private function depth(string $command, JarvisSetting $settings): string
    {
        if (preg_match('/\bexplain technically\b/i', $command)) {
            return 'technical';
        }
        if (preg_match('/\bexplain more\b|\bin detail\b/i', $command)) {
            return 'detailed';
        }
        if (preg_match('/^\s*explain\s*[.!?]*$/i', $command)) {
            return 'normal';
        }
        if (preg_match('/\bjust (?:show me|give me (?:the )?answer)\b/i', $command)) {
            return 'concise';
        }

        return preg_match('/\bresponse_(?:length|depth)\s*[:=]\s*(concise|normal|detailed|technical)\b/i', $settings->learned_memory ?? '', $matches) ? mb_strtolower($matches[1]) : 'concise';
    }

    private function extractContext(string $command, array &$state): void
    {
        if (preg_match('/\bProject\s+(?:"([^"\r\n]{1,80})"|([A-Za-z0-9][A-Za-z0-9_-]{0,79}))/i', $command, $matches)) {
            $project = $matches[1] ?: ($matches[2] ?? '');
            if (! in_array(mb_strtolower($project), ['completion', 'progress', 'delays', 'budget', 'deliveries'], true)) {
                $state['project'] = $project;
            }
        }
        if (preg_match_all('/\b(deliveries|delivery|inventory|sales|completion|progress|delays|budget|projects|operations)\b/i', $command, $matches)) {
            $metric = mb_strtolower(end($matches[1]));
            $state['metric'] = $metric === 'delivery' ? 'deliveries' : $metric;
        }
        if (preg_match('/\b(last \d+ months|last six months|next month|last month|this month|this year|last year|today|this week)\b/i', $command, $matches)) {
            $state['period'] = mb_strtolower($matches[1]);
        }
        if (preg_match('/^\s*(show|forecast|analy[sz]e|compare|review)\b/i', $command, $matches)) {
            $state['task'] = ucfirst(mb_strtolower($matches[1]));
        }
    }

    private function projectSuffix(array $state): string
    {
        return empty($state['project']) ? '' : ' for Project '.$state['project'];
    }

    /** @return array{project_id: int, project_name: string, connection_id: int}|null */
    private function resolvedProject(mixed $project): ?array
    {
        if (! is_array($project) || ! is_int($project['project_id'] ?? null) || $project['project_id'] < 1
            || ! is_int($project['connection_id'] ?? null) || $project['connection_id'] < 1
            || ! is_string($project['project_name'] ?? null) || trim($project['project_name']) === ''
            || mb_strlen($project['project_name']) > 1000) {
            return null;
        }

        return ['project_id' => $project['project_id'], 'project_name' => $this->redactor->text($project['project_name']),
            'connection_id' => $project['connection_id']];
    }

    /** A new named project or another data domain cannot inherit the previous identity. */
    private function changesProjectOrDomain(string $command): bool
    {
        if (preg_match('/\b(?:emails?|mail|inbox|mailbox|sales)\b/i', $command)) {
            return true;
        }
        if (preg_match('/\b(?:status|progress|(?:warehouse\s+)?readiness|deliver(?:y|ies))\s+(?:of|for)\s+([^.!?\r\n]+)/i', $command, $match)) {
            $subject = trim($match[1], " \t\"'()");
            if (! preg_match('/^(?:(?:the\s+)?(?:current|this|that|same)\s+project|the\s+project|it|them|this|that)\b/i', $subject)) {
                return true;
            }
        }
        if (preg_match('/\bproject\s+(?:"[^"\r\n]+"|([A-Za-z][A-Za-z0-9_-]*))/i', $command, $match)
            && ! in_array(mb_strtolower($match[1] ?? ''), ['status', 'completion', 'progress', 'delays', 'budget', 'deliveries', 'delivery', 'inventory', 'report', 'have', 'has', 'in', 'for'], true)) {
            return true;
        }
        if (preg_match_all('/\b([A-Za-z]{2,})[\s._-]*\d+\b/', $command, $matches)) {
            foreach ($matches[1] as $prefix) {
                if (! in_array(mb_strtolower($prefix), ['fy', 'year', 'in', 'for', 'from', 'during', 'since', 'until', 'to'], true)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function startsNewRequest(string $command): bool
    {
        return preg_match('/^\s*(?:hi|hello|thanks|what|how|can you|do you|show|find|check|analy[sz]e|forecast|send|delete|remember|confirm|cancel|demo|explain)\b/i', $command) === 1;
    }

    private function containsSideEffectIntent(string $command): bool
    {
        return preg_match('/\b(?:send|delete|remove|mark|modify|update|edit|approve|confirm|remember|save|draft|compose|run|execute)\b/i', $command) === 1;
    }

    private function hasBusinessReadTool(Request $request, string $command): bool
    {
        $agent = app(CapabilityResponder::class)->agent($request, $command, app(GoDaddyEmailService::class));
        foreach ($this->capabilities->discover($agent)['tools'] as $tool) {
            if (in_array($tool['category'], ['data', 'projects', 'deliveries', 'inventory', 'sales'], true)
                && $tool['risk'] === 'READ_ONLY'
                && ($tool['executable_now'] || ($tool['name'] === 'AskOperationsAgent' && $tool['can_attempt']
                    && app(WorkflowOperationsService::class)->supportsRequest($command)))) {
                return true;
            }
        }

        return false;
    }

    private function hasProvidedHistory(string $command): bool
    {
        return preg_match_all('/\b(?:Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:tember)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?|\d{4}-\d{2}(?:-\d{2})?)\s*[:=,]\s*-?\d+(?:\.\d+)?\b/i', $command) >= 2;
    }
}
