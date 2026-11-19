<?php

namespace App\Services\Workflow;

use App\Contracts\Workflows\WorkflowAppExecutor;
use App\Models\ApiConnection;
use App\Models\JarvisSetting;
use RuntimeException;

class OutlookWorkflowExecutor implements WorkflowAppExecutor
{
    public function __construct(private CustomApiWorkflowExecutor $api) {}

    public function app(): string
    {
        return 'outlook';
    }

    public function capabilities(): array
    {
        return ['actions' => ['read', 'draft', 'save_draft', 'send'], 'configuration' => 'saved_microsoft_graph_connection', 'description' => 'Email and communication'];
    }

    /** Drafts are prepared locally for review. They do not create or send a remote message. */
    public function execute(array $node, bool $dryRun = false): array
    {
        $action = $node['config']['action'] ?? '';
        $values = $node['config']['values'] ?? [];
        if (! in_array($action, ['read', 'draft', 'save_draft', 'send'], true)) {
            throw new RuntimeException('Choose a supported Outlook action.');
        }
        if ($action === 'send') {
            $settings = preg_match('/^user:(\d+)$/D', $node['_owner_key'] ?? '', $owner)
                ? JarvisSetting::query()->where('user_id', (int) $owner[1])->first()
                : JarvisSetting::current();
            if (! in_array('send_emails', $settings?->allowed_actions ?? [], true)) {
                throw new RuntimeException('Sending email is disabled in JARVIS Settings.');
            }
        }
        if ($action !== 'read') {
            if (! filter_var($values['to'] ?? '', FILTER_VALIDATE_EMAIL) || ! is_string($values['subject'] ?? null) || ! is_string($values['body'] ?? null)) {
                throw new RuntimeException('An email draft needs a valid recipient, subject and message.');
            }
            if ($action === 'draft') {
                return ['success' => true, 'draft' => ['to' => $values['to'], 'subject' => $values['subject'], 'body' => $values['body']],
                    'sent' => false, 'remote_draft_created' => false, 'message' => 'Draft prepared for review. Sending is a separate permission-controlled action.'];
            }
        }
        $connection = ApiConnection::query()->where('owner_key', $node['_owner_key'] ?? '')
            ->whereKey($values['connection_id'] ?? $values['connection'] ?? 0)->first();
        if (! $connection || parse_url($connection->base_url, PHP_URL_HOST) !== 'graph.microsoft.com') {
            throw new RuntimeException('Choose an owned Microsoft Graph connection to read or send Outlook email.');
        }
        $message = $action !== 'read' ? ['subject' => $values['subject'], 'body' => ['contentType' => 'Text', 'content' => $values['body']],
            'toRecipients' => [['emailAddress' => ['address' => $values['to']]]]] : null;
        $body = $action === 'send' ? ['message' => $message, 'saveToSentItems' => true] : $message;
        $node['config'] = ['app' => 'custom_api', 'action' => 'request', 'values' => [
            'connection_id' => $connection->id, 'method' => $action === 'read' ? 'GET' : 'POST',
            'url' => $action === 'send' ? '/v1.0/me/sendMail' : '/v1.0/me/messages',
            'query' => $action === 'read' ? ['$top' => 20, '$select' => 'id,subject,from,bodyPreview,receivedDateTime', ...(! empty($values['query']) ? ['$search' => '"'.$values['query'].'"'] : [])] : [], 'body' => $body,
        ]];

        $result = $this->api->execute($node, $dryRun);

        return [...$result, 'sent' => false, 'submission_accepted' => $action === 'send' && ($result['success'] ?? false),
            'remote_draft_created' => $action === 'save_draft' && ($result['success'] ?? false)];
    }
}
