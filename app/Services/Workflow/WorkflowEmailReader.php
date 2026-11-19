<?php

namespace App\Services\Workflow;

use App\Services\Email\EmailNormalizer;
use App\Services\GoDaddyEmailService;

class WorkflowEmailReader
{
    public function __construct(private EmailNormalizer $normalizer) {}

    public function isConfigured(?int $accountId = null): bool
    {
        return (new GoDaddyEmailService($this->normalizer, $accountId))->isConfigured();
    }

    /** @return array{success: true, count: int, emails: array<int, array<string, mixed>>} */
    public function unreadEmails(?int $accountId, int $limit = 10): array
    {
        $service = new GoDaddyEmailService($this->normalizer, $accountId);
        $emails = $service->getUnreadMessages(min(max($limit, 1), 10));

        return [
            'success' => true,
            'count' => count($emails),
            'emails' => array_map(fn ($email): array => [
                'from' => $email->from,
                'subject' => $email->subject,
                'received_at' => $email->receivedAt,
                'body' => mb_substr($email->body, 0, 12000),
            ], $emails),
        ];
    }
}
