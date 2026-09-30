<?php

namespace App\Console\Commands;

use App\Notifications\ImportantEmailNotification;
use App\Services\EmailImportanceClassifier;
use App\Services\GoDaddyEmailService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

class CheckImportantEmails extends Command
{
    protected $signature = 'jarvis:check-important-emails';

    protected $description = 'Scan recent emails and notify the configured user about important ones';

    public function handle(GoDaddyEmailService $emailService, EmailImportanceClassifier $classifier): int
    {
        if (! $emailService->isConfigured()) {
            $this->info('Email is not configured; skipping important-email check.');

            return self::SUCCESS;
        }

        $result = $emailService->latestEmails(
            config('jarvis.importance.poll_limit', 15)
        );
        if (! ($result['success'] ?? false)) {
            $this->error('Could not fetch emails: '.($result['error'] ?? 'unknown error'));

            return self::FAILURE;
        }

        $emails = $result['emails'];

        $notifiableClass = config('jarvis.notifiable');
        $notifiable = is_string($notifiableClass) ? $notifiableClass::first() : $notifiableClass;

        if (! $notifiable) {
            $this->warn('No notifiable found (check config/jarvis.php); skipping run.');

            return self::SUCCESS;
        }

        foreach ($emails as $email) {
            $uid = $email['uid'] ?? null;

            if (! $uid) {
                continue;
            }

            // Don't re-classify/re-notify the same email on every run.
            $cacheKey = "jarvis:important-checked:{$uid}";

            if (Cache::has($cacheKey)) {
                continue;
            }

            Cache::put($cacheKey, true, now()->addDays(30));

            $full = $emailService->getEmail($uid);

            if ($full['success'] ?? false) {
                $email['body'] = $full['message']['body'] ?? '';
            }

            $classification = $classifier->classify($email);

            if ($classification['important']) {
                Notification::send($notifiable, new ImportantEmailNotification($email, $classification['reason']));
            }
        }

        return self::SUCCESS;
    }
}
