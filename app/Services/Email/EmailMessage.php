<?php

namespace App\Services\Email;

final readonly class EmailMessage
{
    /**
     * @param  string[]  $to
     * @param  string[]  $cc
     * @param  array<int, array{name: ?string, content_type: ?string, size: ?int}>  $attachments
     */
    public function __construct(
        public string $provider,
        public string $account,
        public string $messageId,
        public ?string $threadId,
        public string $from,
        public array $to,
        public array $cc,
        public string $subject,
        public string $body,
        public ?string $receivedAt,
        public array $attachments,
        public bool $isUnread,
        public string $providerMessageId,
    ) {}
}
