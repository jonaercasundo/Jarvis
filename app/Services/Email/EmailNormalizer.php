<?php

namespace App\Services\Email;

class EmailNormalizer
{
    public function normalize(
        array $message,
        string $provider,
        string $account,
        string $providerMessageId,
    ): EmailMessage {
        $messageId = trim((string) ($message['message_id'] ?? ''));
        $receivedAt = isset($message['date']) ? (string) $message['date'] : null;

        if ($messageId === '') {
            $messageId = $provider.':'.hash('sha256', implode('|', [
                $account,
                $providerMessageId,
                $receivedAt ?? '',
                (string) ($message['from'] ?? ''),
                (string) ($message['subject'] ?? ''),
            ]));
        }

        return new EmailMessage(
            provider: $provider,
            account: $account,
            messageId: $messageId,
            threadId: isset($message['thread_id']) ? (string) $message['thread_id'] : null,
            from: (string) ($message['from'] ?? ''),
            to: array_values(array_filter($message['to_addresses'] ?? [], 'is_string')),
            cc: array_values(array_filter($message['cc_addresses'] ?? [], 'is_string')),
            subject: (string) ($message['subject'] ?? ''),
            body: (string) ($message['body'] ?? ''),
            receivedAt: $receivedAt,
            attachments: $message['attachments'] ?? [],
            isUnread: ! (bool) ($message['seen'] ?? false),
            providerMessageId: $providerMessageId,
        );
    }
}
