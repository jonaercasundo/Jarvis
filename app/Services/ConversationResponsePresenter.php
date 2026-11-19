<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class ConversationResponsePresenter
{
    public function __construct(private SourceSecretRedactor $redactor) {}

    /** Presentation diagnostics belong in logs unless the user asks about them. */
    public function omitArtifactFailure(string $answer, string $request): string
    {
        if (preg_match('/\b(?:artifact|report|rendering|validation)\b/i', $request)
            && preg_match('/\b(?:why|fail(?:ed|ure)?|errors?|debug|diagnos\w*)\b/i', $request)) {
            return $answer;
        }
        $parts = preg_split('/(?<=[.!?])\s+|\R/u', $answer) ?: [$answer];
        $parts = array_filter($parts, fn (string $part): bool => ! (preg_match('/\b(?:artifact|report|rendering|visualization)\b/i', $part)
            && preg_match('/\b(?:fail(?:ed|ure)?|invalid|didn[\x{2019}\x{27}]t\s+(?:validate|render)|did\s+not\s+(?:validate|render)|couldn[\x{2019}\x{27}]t\s+(?:validate|render)|could\s+not\s+(?:validate|render))\b/iu', $part)));

        return trim(implode(' ', $parts));
    }

    /** Add presentation fields while preserving the original response contract and complete result. */
    public function present(array $response, string $depth = 'concise'): array
    {
        $original = $this->redactor->text((string) ($response['response'] ?? $response['message'] ?? $response['error'] ?? ''));
        $message = $this->summary($original);
        $display = ['type' => 'conversation', 'message' => $message, 'expanded' => in_array($depth, ['detailed', 'technical'], true)];
        $speech = $this->speech($message);
        if (! ($response['success'] ?? true)) {
            $display['type'] = 'error';
            $display['message'] = $this->humanError($original);
            $speech = $this->speech($display['message'], "I couldn't finish that. The details are on screen.");
            if (preg_match('/\b(?:401|403|unauthorized|authentication failed|permission denied)\b/i', $original)) {
                $display['connection_url'] = route('jarvis.connections.index', absolute: false);
            }
        } elseif (($response['type'] ?? '') === 'clarification') {
            $display = ['type' => 'clarification', 'payload' => array_intersect_key($response, array_flip(['type', 'question', 'options', 'allow_custom_answer', 'clarification_id']))];
            $speech = $this->speech((string) $response['question'], 'I need one detail first. You can choose or type your answer.');
        } elseif (($response['type'] ?? '') === 'visual_demo') {
            $display = ['type' => ($response['mode'] ?? '') === 'categories' ? 'capability_cards' : 'visual_demo',
                'payload' => array_intersect_key($response, array_flip(['type', 'mode', 'title', 'summary', 'categories', 'category', 'steps', 'chart', 'availability', 'live_action']))];
            $speech = ($response['mode'] ?? '') === 'categories' ? 'Pick what you want help with, and I will show you.'
                : $this->speech((string) ($response['summary'] ?? ''), 'Here is how I can help.');
        } elseif (($response['type'] ?? '') === 'email_status' && ($response['configured'] ?? false)) {
            $display['type'] = 'email_preview';
            $display['items'] = $this->emailPreviews($response['items'] ?? []);
            $display['metrics'] = [];
            foreach (['critical_count' => 'Urgent', 'action_required_count' => 'Needs attention', 'important_count' => 'Important'] as $key => $label) {
                if (isset($response[$key])) {
                    $display['metrics'][] = ['label' => $label, 'value' => (int) $response[$key]];
                }
            }
        } elseif (is_array($response['emails'] ?? null)) {
            $display['type'] = 'email_preview';
            $display['items'] = $this->emailPreviews($response['emails']);
            $count = count($response['emails']);
            $display['message'] = $count === 0 ? ($message ?: 'No matching messages came up.') : 'I found '.$count.' message'.($count === 1 ? '' : 's').' in the recent emails checked.';
            $speech = $this->speech($display['message']);
        } elseif (in_array($response['type'] ?? '', ['data_unavailable', 'preference_saved', 'clarification_cancelled', 'clarification_expired', 'confirmation'], true)) {
            $display['type'] = 'status';
        }

        foreach (($response['success'] ?? true) && ($response['type'] ?? '') !== 'clarification' ? ($response['artifacts'] ?? []) : [] as $artifact) {
            if (! is_array($artifact) || ($artifact['is_mock'] ?? false)) {
                continue;
            }
            $data = $artifact['data'] ?? [];
            if (($artifact['type'] ?? '') === 'email' && preg_match('/^[A-Z0-9]{6}$/D', (string) ($data['confirmation_code'] ?? ''))) {
                $code = $data['confirmation_code'];
                $display = ['type' => 'confirmation', 'message' => 'The email is ready. Want me to send it?', 'draft' => $data,
                    'actions' => [['label' => 'Send', 'command' => 'confirm '.$code], ['label' => 'Edit', 'fill' => 'Revise the email draft: '], ['label' => 'Cancel', 'command' => 'cancel '.$code]]];
                $speech = 'The email is ready. Want me to send it?';
                break;
            }
            if (in_array($artifact['type'] ?? '', ['chart', 'table', 'report', 'agent_status', 'workflow_graph', 'workflow_run', 'sync_status'], true)) {
                $display['artifact'] = $artifact;
                $display['type'] = match ($artifact['type']) {
                    'chart' => 'chart', 'table' => 'table', 'report', 'sync_status' => 'metrics', default => 'status',
                };
                if (is_array($data['metrics'] ?? $data['counts'] ?? null)) {
                    $display['metrics'] = [];
                    foreach (array_slice($data['metrics'] ?? $data['counts'], 0, 6, true) as $label => $value) {
                        if (is_scalar($value)) {
                            $display['metrics'][] = ['label' => $label, 'value' => $value];
                        }
                    }
                }
                if (isset($data['summary'])) {
                    $display['message'] = $this->summary($this->redactor->text((string) $data['summary']));
                    $speech = $this->speech($display['message']);
                }
                if (isset($data['assumptions'])) {
                    $display['explanation'] = $this->redactor->text(is_string($data['assumptions']) ? $data['assumptions'] : json_encode($data['assumptions'], JSON_THROW_ON_ERROR));
                }
                if ($artifact['type'] === 'chart') {
                    try {
                        $response['visual'] = app(VisualizationBuilder::class)->fromChartArtifact($artifact);
                        $response['message'] = $display['message'];
                        $response['speech_text'] = $speech;
                    } catch (ValidationException) {
                        unset($display['artifact']);
                        $response['visual_error'] = true;
                    }
                }
                break;
            }
        }
        if ($original !== '' && $original !== ($display['message'] ?? '')) {
            $display['details'] = $original;
        }
        if (($response['type'] ?? '') === 'capabilities' || $depth === 'technical') {
            $speech = 'The technical details are on screen.';
            $display['expanded'] = true;
            if ($original !== ($display['message'] ?? '')) {
                $display['details'] = $original;
            }
        }
        if (preg_match('/\bconfirm\s+([A-Z0-9]{6})\b/i', $original, $confirmation) && ($display['type'] ?? '') !== 'confirmation') {
            $display['review_note'] = 'To approve this action, type confirm '.strtoupper($confirmation[1]).'.';
            $speech = 'Review the details before approving the action. The confirmation is on screen.';
        }

        if (($response['success'] ?? true) && ! in_array($display['type'], ['clarification', 'confirmation', 'error'], true) && is_array($response['visual'] ?? null)) {
            try {
                $visual = app(VisualizationBuilder::class)->validate($response['visual']);
                $message = $this->summary($this->redactor->text((string) ($response['message'] ?? $visual['insight'] ?? 'The visual is ready.')));
                $speech = $this->speech($this->redactor->text((string) ($response['speech_text'] ?? $message)));
                $display = array_intersect_key($display, array_flip(['artifact', 'explanation'])) + ['type' => $visual['type'], 'message' => $message, 'visual' => $visual];
                $response['visual'] = $visual;
                $response['response'] = $message;
                $response['actions'] = $visual['actions'] ?? [];
            } catch (ValidationException) {
                unset($response['visual']);
                $response['visual_error'] = true;
            }
        }
        if (($response['visual_error'] ?? false) && ! isset($display['visual']) && ! in_array($display['type'], ['clarification', 'confirmation'], true)) {
            $display = ['type' => 'status', 'message' => $this->summary($this->redactor->text($response['visual_error_message'] ?? 'I could not build a visual from those records. Share the underlying data or ask for a sample.'))];
            $speech = $display['message'];
            $response['response'] = $display['message'];
        }

        if (($response['success'] ?? true) && in_array($response['artifact']['type'] ?? null, ['project_status', ...ArtifactValidationService::REPORT_TYPES], true) && ! in_array($display['type'], ['clarification', 'confirmation', 'error'], true)) {
            $response['response'] = $original;
            $display = ['type' => 'conversation', 'message' => $this->summary($original), 'report' => $response['artifact']];
            $speech = $this->speech($display['message']);
        }

        return [...$response, 'message' => $display['message'] ?? $display['payload']['question'] ?? $display['payload']['summary'] ?? $message, 'speech_text' => $speech, 'display' => $display];
    }

    /** Select a brief result without losing the original content kept in Details. */
    private function summary(string $text): string
    {
        $original = $text;
        $text = preg_replace('/```[\s\S]*?(?:```|$)/', '', $text) ?? '';
        $paragraphs = [];
        foreach (preg_split('/\R+/', $text) ?: [] as $line) {
            if (preg_match('/^\s*(?:#{1,6}\s|[|{\[]|(?:[-*+] |\d+[.)] ))/', $line)) {
                continue;
            }
            $line = preg_replace('/\[([^\]]+)\]\([^)]*\)/', '$1', $line) ?? '';
            $line = preg_replace('/\*\*([^*]+)\*\*|__([^_]+)__/', '$1$2', $line) ?? '';
            if (trim($line) !== '') {
                $paragraphs[] = trim($line);
            }
        }
        $summary = '';
        foreach (array_slice(preg_split('/(?<=[.!?])\s+/', implode(' ', $paragraphs)) ?: [], 0, 4) as $sentence) {
            if (mb_strlen($summary.' '.$sentence) > 480) {
                break;
            }
            $summary .= ($summary === '' ? '' : ' ').$sentence;
        }

        return $summary ?: ($original === '' ? '' : 'The details are ready for you below.');
    }

    /** Speak a short interpretation; structured and technical content stays on screen. */
    private function speech(string $text, string $fallback = 'The details are on screen.'): string
    {
        $spoken = [];
        foreach (array_slice(preg_split('/(?<=[.!?])\s+/', trim($text)) ?: [], 0, 2) as $sentence) {
            $sentence = preg_replace('/(\d+(?:\.\d+)?)%/', '$1 percent', $sentence) ?? '';
            if ($sentence === '' || mb_strlen(implode(' ', [...$spoken, $sentence])) > 280
                || preg_match('/[^\p{L}\p{N}\s.,!?\x{27}\x{2019}\-]/u', $sentence)
                || preg_match('/\b(?:READ_ONLY|EXTERNAL_SIDE_EFFECT|DESTRUCTIVE|Authorization|Bearer|tool|executor|class|schema|HTTP)\b/i', $sentence)
                || preg_match('/\b(?:[A-Z][a-z]+(?:[A-Z][a-z]+)+|[a-z]+_[a-z_]+)\b/', $sentence)) {
                continue;
            }
            $spoken[] = $sentence;
        }

        return $spoken === [] ? $fallback : implode(' ', $spoken);
    }

    private function humanError(string $text): string
    {
        return match (true) {
            preg_match('/\b(?:401|unauthorized|authentication failed)\b/i', $text) === 1 => 'The connection needs to be authorized again. Check it in Settings.',
            preg_match('/\b(?:403|forbidden|permission denied)\b/i', $text) === 1 => 'I do not have permission to access that. Check the connection permissions.',
            preg_match('/\b429\b|rate.limit/i', $text) === 1 => 'That service is busy. Please try again shortly.',
            preg_match('/time.?out|did not respond/i', $text) === 1 => 'That service took too long to respond. Try again in a moment.',
            preg_match('/\b5\d{2}\b/i', $text) === 1 => 'That service is having trouble. Please try again shortly.',
            default => $this->speech($this->summary($text), "I couldn't finish that. You can check the details below."),
        };
    }

    private function emailPreviews(array $emails): array
    {
        return array_map(function (array $email): array {
            $uid = (string) ($email['uid'] ?? $email['email_uid'] ?? $email['id'] ?? $email['message_id'] ?? '');

            return ['uid' => ctype_digit($uid) ? $uid : null, 'sender' => $this->redactor->text((string) ($email['from_name'] ?? $email['from'] ?? '')),
                'subject' => $this->redactor->text((string) ($email['subject'] ?? '(No subject)')), 'date' => $email['date'] ?? null,
                'action' => $this->redactor->text((string) ($email['action'] ?? '')), 'category' => $email['category'] ?? null];
        }, $emails);
    }
}
