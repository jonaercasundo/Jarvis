<?php

namespace App\Console\Commands;

use App\Services\EmailAiService;
use App\Services\GoDaddyEmailService;
use Illuminate\Console\Command;

class MonitorEmails extends Command
{
    protected $signature = 'jarvis:monitor-emails';

    protected $description = 'Monitor the GoDaddy mailbox for new emails';

    public function handle(
        GoDaddyEmailService $emailService,
        EmailAiService $aiService
    ): int {
        if (! $emailService->isConfigured()) {
            $this->info('Email is not configured; skipping email monitor.');

            return self::SUCCESS;
        }

        $result = $emailService->latestEmails(1);

        if (! $result['success']) {
            $this->error('Unable to check mailbox.');

            return self::FAILURE;
        }

        $email = $result['emails'][0] ?? null;

        if (! $email) {
            $this->line('No emails found.');

            return self::SUCCESS;
        }

        $lastUid = cache('jarvis.last_email_uid');

        if ($lastUid === null) {
            cache()->forever('jarvis.last_email_uid', $email['uid']);

            $this->info("Initialized at UID {$email['uid']}.");

            return self::SUCCESS;
        }

        if ((int) $email['uid'] > (int) $lastUid) {
            cache()->forever('jarvis.last_email_uid', $email['uid']);

            $this->info("NEW EMAIL: UID {$email['uid']}");
            $this->line("From: {$email['from']}");
            $this->line("Subject: {$email['subject']}");

            $fullEmail = $emailService->getEmail((int) $email['uid']);

            if (! $fullEmail['success']) {
                $this->error('Unable to read the new email.');

                return self::FAILURE;
            }

            $analysis = $aiService->analyze(
                $fullEmail['message']
            );
            cache()->put(
                'jarvis.new_email',
                [
                    'uid' => $email['uid'],
                    'from' => $email['from'],
                    'from_name' => $email['from_name'],
                    'subject' => $email['subject'],
                    'analysis' => $analysis['analysis'] ?? [],
                ],
                now()->addHours(24)
            );
            $this->newLine();
            $this->info('JARVIS ANALYSIS');
            $this->line(json_encode(
                $analysis,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
            ));

            return self::SUCCESS;
        }

        $this->line('No new email.');

        return self::SUCCESS;
    }
}
