<?php

namespace App\Services;

use Laravel\Ai\AnonymousAgent;
use Throwable;

class EmailImportanceClassifier
{
    /**
     * @param  array  $email  Expects 'from', 'subject', and 'body' (the
     *                        caller should fetch the full body via
     *                        GoDaddyEmailService::getEmail() first —
     *                        latestEmails()/findEmails() only return
     *                        headers).
     * @return array{important: bool, reason: string}
     */
    public function classify(array $email): array
    {
        $agent = AnonymousAgent::make(
            instructions: <<<'PROMPT'
                You triage business emails for importance.

                Respond with ONLY a JSON object, nothing else, no markdown fences:
                {"important": true|false, "reason": "short reason, under 20 words"}

                Treat an email as important if it involves: money, invoices, or
                payments; legal or contractual matters; an urgent deadline; a
                client or executive directly asking for a response; or a
                security/account alert.

                Treat newsletters, marketing, and routine automated notices as
                NOT important.
                PROMPT,
            messages: [],
            tools: [],
        );

        $payload = sprintf(
            "From: %s\nSubject: %s\nSnippet: %s",
            $email['from'] ?? 'unknown',
            $email['subject'] ?? '(no subject)',
            $email['body'] ?? '',
        );

        try {
            $response = $agent->prompt($payload);
            $decoded = json_decode(trim($response->text), true);

            if (is_array($decoded) && array_key_exists('important', $decoded)) {
                return [
                    'important' => (bool) $decoded['important'],
                    'reason' => (string) ($decoded['reason'] ?? ''),
                ];
            }
        } catch (Throwable $e) {
            report($e);
        }

        // Classifier failed or returned something unparseable — fail
        // quiet rather than spamming a notification on bad data.
        return ['important' => false, 'reason' => 'Could not classify'];
    }
}
