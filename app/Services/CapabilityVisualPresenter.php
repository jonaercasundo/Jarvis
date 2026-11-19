<?php

namespace App\Services;

use App\Models\JarvisSetting;

class CapabilityVisualPresenter
{
    public function isPresentationRequest(string $prompt): bool
    {
        return preg_match('/^\s*(?:please\s+)?(?:how can you (?:help|automate|handle|manage)|how does (?:this|it) work|show me how|just show me|explain technically|show (?:me )?how .* works|how can you understand)/i', $prompt) === 1;
    }

    public function isTechnical(string $prompt, JarvisSetting $settings): bool
    {
        if (preg_match('/\b(?:just show me|show me how|visually|non.technical|simply)\b/i', $prompt)) {
            return false;
        }

        return preg_match('/\b(?:technically|technical details|actual functions|tools|FindEmails|SendEmail|CodeInspector|DescribeCapabilities|implemented|implementation|source class|PHP|schema)\b/i', $prompt) === 1
            || preg_match('/technical_level\s*[:=]\s*(?:technical|developer)\b/i', $settings->learned_memory ?? '') === 1;
    }

    public function category(string $prompt, ?string $previous = null): ?string
    {
        foreach (['email' => 'email|inbox|mailbox|draft a reply|replies', 'data' => 'data|analy[sz]|forecast|trend', 'automation' => 'automat|workflow|business system|connection',
            'preferences' => 'prefer|adapt|remember', 'developer' => 'code|developer|source|modify yourself'] as $category => $pattern) {
            if (preg_match('/\b(?:'.$pattern.')/i', $prompt)) {
                return $category;
            }
        }
        if (preg_match('/^\s*(?:please\s+)?(?:show me how|just show me|explain technically|how does (?:this|it) work)\s*[.!?]*$/i', $prompt)) {
            return $previous;
        }

        return null;
    }

    /** @param array<string, mixed> $capabilities */
    public function connectionNotice(array $capabilities, string $prompt): ?string
    {
        foreach ($capabilities['saved_connections'] ?? [] as $connection) {
            if ($connection['name'] !== '' && stripos($prompt, $connection['name']) !== false) {
                if ($connection['available_to_agent']) {
                    return $connection['reachability_verified']
                        ? $connection['name'].' is connected through your Operations Agent. A read request succeeded recently; each question still needs current data.'
                        : $connection['name'].' has a read-only route through your Operations Agent. The connection is unverified until a request succeeds.';
                }

                return $connection['configured']
                    ? $connection['name'].' is saved for connected workflows. I cannot read its data directly in this chat.'
                    : 'Finish setting up '.$connection['name'].' before using it in connected workflows.';
            }
        }
        foreach ($capabilities['integrations'] ?? [] as $integration) {
            $name = trim(preg_replace('/\s*\([^)]*\)/', '', $integration['name']));
            if ($name !== '' && stripos($prompt, $name) !== false && ! $integration['implemented']) {
                return $name.' is not available for connected work here.';
            }
        }

        return null;
    }

    /** @param array<string, mixed> $capabilities */
    public function present(array $capabilities, JarvisSetting $settings, ?string $category, bool $minimal = false): array
    {
        $cards = $this->cards($capabilities, $settings);
        $chosen = collect($cards)->firstWhere('id', $category);
        $summary = $chosen['summary'] ?? 'What do you want help with?';
        $response = ['type' => 'visual_demo', 'explanation_only' => true, 'mode' => $chosen ? 'flow' : 'categories',
            'title' => $chosen ? $chosen['flow_title'] : 'How I can help', 'summary' => $minimal ? '' : $summary,
            'response' => $minimal ? '' : $summary];
        if ($chosen) {
            $response['category'] = $chosen['id'];
            foreach (['id', 'steps', 'availability', 'chart', 'live_action'] as $key) {
                if (isset($chosen[$key])) {
                    $response[$key] = $chosen[$key];
                }
            }
        } else {
            $response['categories'] = $cards;
        }
        if (preg_match('/visual_explanations\s*[:=]\s*(?:false|off|text)\b/i', $settings->learned_memory ?? '') && ! $minimal) {
            return ['type' => 'capability_summary', 'response' => $chosen ? $summary : 'I can help with '.implode(', ', array_column($cards, 'title')).'.'];
        }

        return $response;
    }

    /** @return array<int, array<string, mixed>> */
    private function cards(array $capabilities, JarvisSetting $settings): array
    {
        $tools = collect($capabilities['tools']);
        $operation = fn (string $category, string $operation): ?array => $tools->first(fn (array $tool): bool => $tool['category'] === $category && in_array($operation, $tool['operations'], true));
        $cards = [];
        $email = $operation('email', 'search');
        if ($email) {
            $steps = [['mail', 'Email arrives'], ['search', 'I find messages']];
            if ($operation('email', 'stage_reply')) {
                $steps[] = ['pen', 'I prepare a reply'];
            }
            if ($operation('email', 'stage_new_email')) {
                $steps[] = ['user-check', 'You review and approve'];
                $steps[] = ['send', 'I send your message'];
            }
            $card = $this->card('email', 'mail', 'Email', 'Handle your inbox', 'How I help with email',
                'I can help with repetitive email work while you stay in control.', $steps,
                $email['configured'] && $email['permitted'] ? 'Ready to try' : ($email['configured'] ? 'Enable email access in Settings' : 'Connect your inbox in Settings'));
            if ($email['executable_now'] && ($settings->capability_demonstrations ?? 'ask_first') !== 'never_demo') {
                $card['live_action'] = ['label' => 'Try with my inbox', 'command' => 'Demo email search.'];
            }
            $cards[] = $card;
        }

        if (in_array('analysis', $settings->allowed_actions ?? [], true)) {
            $operations = $tools->firstWhere('name', 'AskOperationsAgent');
            $card = $this->card('data', 'chart', 'Data', 'Understand data you provide', 'From data to useful insights',
                'Share your historical data so I can help explain patterns and possible trends.',
                [['database', 'You share your data'], ['search', 'I look for patterns'], ['chart', 'I show the trend'], ['sparkles', 'Estimate what may follow'], ['lightbulb', 'Explain what matters']], 'Needs data you provide');
            if ($operations && $operations['configured'] && $operations['permitted']) {
                $card = $this->card('data', 'chart', 'Data', 'Understand operations data', 'From operations questions to verified records',
                    'Your Operations Agent can try to retrieve read-only Tracker data. Answers depend on a successful request and the fields returned.',
                    [['message', 'You ask about operations'], ['brain', 'Your Operations Agent chooses a read'], ['database', 'Tracker returns records'], ['lightbulb', 'I explain the result']],
                    $operations['connection_verified'] ? 'A read succeeded recently' : 'Connection verification pending');
            }
            if (! preg_match('/charts_for_trends\s*[:=]\s*(?:false|off)\b/i', $settings->learned_memory ?? '')) {
                $card['chart'] = ['title' => 'Example delivery trend', 'label' => 'Illustrative sample data', 'format' => 'line',
                    'note' => 'The forecast illustrates a possible trend, not a guaranteed result.',
                    'series' => [['label' => 'Jan', 'value' => 60], ['label' => 'Feb', 'value' => 70], ['label' => 'Mar', 'value' => 90], ['label' => 'Apr', 'value' => 100], ['label' => 'May', 'value' => 110, 'forecast' => true]]];
            }
            $cards[] = $card;
        }

        if (($capabilities['application']['workflow_executors'] ?? []) !== []) {
            $card = $this->card('automation', 'settings', 'Automation', 'Connect repetitive steps', 'How connected work happens',
                'Build a workflow to connect repetitive steps using the systems you have set up.',
                [['play', 'A workflow starts'], ['brain', 'Follow your chosen steps'], ['settings', 'Connected work runs'], ['check', 'Review the result']], 'Set up connected workflows');
            $status = $tools->first(fn (array $tool): bool => in_array('workflow_agent_status', $tool['operations'], true));
            if ($status && $status['executable_now'] && ($settings->capability_demonstrations ?? 'ask_first') !== 'never_demo') {
                $card['live_action'] = ['label' => 'Check my workflows', 'command' => 'Check my workflow Agent status.'];
            }
            $cards[] = $card;
        }
        if ($operation('preferences', 'remember')) {
            $cards[] = $this->card('preferences', 'brain', 'Preferences', 'Work the way you prefer', 'How I adapt to you',
                'I can remember the preferences you ask me to save.',
                [['user', 'You choose a preference'], ['brain', 'I remember your choice'], ['message', 'Future answers adapt']], 'Your choice');
        }
        $source = $tools->first(fn (array $tool): bool => in_array('find_class', $tool['operations'], true));
        if ($source) {
            $card = $this->card('developer', 'code', 'Developer', 'Understand my own code', 'How I understand my code',
                'I can inspect how my features work and explain them simply. I cannot change my code.',
                [['message', 'You ask a question'], ['search', 'I inspect approved source'], ['puzzle', 'Find the relevant function'], ['brain', 'Understand how it works'], ['message', 'Explain it simply']], 'Inspection only');
            if ($source['executable_now'] && ($settings->capability_demonstrations ?? 'ask_first') !== 'never_demo') {
                $card['live_action'] = ['label' => 'Inspect your code', 'command' => 'Demo source inspection.'];
            }
            $cards[] = $card;
        }

        return $cards;
    }

    private function card(string $id, string $icon, string $title, string $description, string $flowTitle, string $summary, array $steps, string $availability): array
    {
        return ['id' => $id, 'icon' => $icon, 'title' => $title, 'description' => $description, 'flow_title' => $flowTitle, 'summary' => $summary,
            'availability' => $availability, 'steps' => array_map(fn (array $step): array => ['icon' => $step[0], 'title' => $step[1]], $steps)];
    }
}
