<?php

namespace App\Contracts\Email;

use App\Services\Email\EmailMessage;

interface EmailProviderInterface
{
    public function providerName(): string;

    public function accountAddress(): string;

    public function isConfigured(): bool;

    public function getUnreadCount(): int;

    /** @return EmailMessage[] */
    public function getUnreadMessages(int $limit = 15): array;

    public function getMessage(string $providerMessageId): ?EmailMessage;
}
