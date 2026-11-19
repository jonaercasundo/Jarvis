<?php

namespace App\Services\Email;

use App\Contracts\Email\EmailProviderInterface;
use RuntimeException;

class EmailManager
{
    public function provider(): EmailProviderInterface
    {
        $providerName = (string) config('email.provider', 'godaddy');
        $providerClass = config("email.providers.{$providerName}");

        if (! is_string($providerClass) || ! class_exists($providerClass)) {
            throw new RuntimeException("Email provider [{$providerName}] is not configured.");
        }

        $provider = app($providerClass);

        if (! $provider instanceof EmailProviderInterface) {
            throw new RuntimeException("Email provider [{$providerName}] does not implement the email provider contract.");
        }

        return $provider;
    }

    public function isConfigured(): bool
    {
        return $this->provider()->isConfigured();
    }

    public function getUnreadCount(): int
    {
        return $this->provider()->getUnreadCount();
    }

    /** @return EmailMessage[] */
    public function getUnreadEmails(int $limit = 15): array
    {
        return $this->provider()->getUnreadMessages($limit);
    }

    public function getEmail(string $providerMessageId): ?EmailMessage
    {
        return $this->provider()->getMessage($providerMessageId);
    }
}
