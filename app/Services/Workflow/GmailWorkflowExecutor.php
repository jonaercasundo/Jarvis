<?php

namespace App\Services\Workflow;

use App\Contracts\Workflows\WorkflowAppExecutor;
use App\Models\EmailAccount;
use RuntimeException;

class GmailWorkflowExecutor implements WorkflowAppExecutor
{
    private const ACTIONS = ['read', 'list_unread', 'list unread'];

    public function capabilities(): array
    {
        return ['actions' => self::ACTIONS, 'risk' => 'READ_ONLY', 'configuration' => 'imap'];
    }

    public function __construct(private WorkflowEmailReader $emailReader) {}

    public function app(): string
    {
        return 'gmail';
    }

    /** @param array<string, mixed> $node */
    public function execute(array $node, bool $dryRun = false): array
    {
        $config = $node['config'] ?? [];
        $app = strtolower((string) ($config['app'] ?? ''));
        $action = strtolower((string) ($config['action'] ?? ''));

        if ($app !== 'gmail' || ! in_array($action, self::ACTIONS, true)) {
            throw new RuntimeException("The connected app action '{$app}: {$action}' is not available yet.");
        }

        if ($dryRun) {
            return [
                'app' => 'gmail',
                'action' => 'list_unread',
                'count' => 1,
                'emails' => [[
                    'uid' => 'TEST-EMAIL-001',
                    'from' => 'customer@example.com',
                    'subject' => 'Sample workflow test email',
                    'body' => 'This message is sample data for a safe workflow test.',
                ]],
                'simulated' => true,
            ];
        }

        $values = is_array($config['values'] ?? null) ? $config['values'] : [];
        $accountId = $values['connection'] ?? null;

        if ($accountId !== null && $accountId !== '') {
            if (! is_numeric($accountId) || (int) $accountId < 1) {
                throw new RuntimeException('Choose a valid email mailbox connection.');
            }

            $account = EmailAccount::query()->whereKey((int) $accountId)->where('is_active', true)->first();

            if (! $account) {
                throw new RuntimeException('The selected email mailbox is not configured or active.');
            }

            $accountId = (int) $account->id;
        } else {
            $accountId = null;
        }

        $result = $this->emailReader->unreadEmails($accountId);

        return [
            'app' => 'gmail',
            'action' => 'list_unread',
            'count' => $result['count'],
            'emails' => $result['emails'],
        ];
    }
}
