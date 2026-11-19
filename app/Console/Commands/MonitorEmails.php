<?php

namespace App\Console\Commands;

use App\Services\Email\EmailAnalyzer;
use App\Services\Email\EmailManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class MonitorEmails extends Command
{
    protected $signature = 'jarvis:monitor-emails';

    protected $description = 'Monitor the configured email provider for unread messages';

    public function handle(EmailManager $emailManager, EmailAnalyzer $analyzer): int
    {
        try {
            $provider = $emailManager->provider();

            if (! $provider->isConfigured()) {
                $this->info('Email provider is not configured; skipping email monitor.');

                return self::SUCCESS;
            }

            $email = $provider->getUnreadMessages(1)[0] ?? null;

            if (! $email) {
                $this->line('No unread email found.');

                return self::SUCCESS;
            }

            $cacheKey = 'jarvis.last_email_message.'.hash('sha256', $email->provider.'|'.$email->account);
            $lastMessageId = Cache::get($cacheKey);

            if ($lastMessageId === null) {
                Cache::forever($cacheKey, $email->messageId);
                $this->info('Email monitor initialized for the configured mailbox.');

                return self::SUCCESS;
            }

            if ($lastMessageId === $email->messageId) {
                $this->line('No new unread email.');

                return self::SUCCESS;
            }

            Cache::forever($cacheKey, $email->messageId);
            $analysis = $analyzer->analyze($email);

            if (! ($analysis['success'] ?? false)) {
                Log::warning('JARVIS monitor could not analyze email', [
                    'provider' => $email->provider,
                    'account' => $email->account,
                    'message_id' => $email->messageId,
                    'error' => $analysis['error'] ?? 'unknown error',
                ]);

                return self::FAILURE;
            }

            cache()->put(
                'jarvis.new_email',
                [
                    'provider' => $email->provider,
                    'account' => $email->account,
                    'message_id' => $email->messageId,
                    'from' => $email->from,
                    'subject' => $email->subject,
                    'analysis' => $analysis['analysis'],
                ],
                now()->addHours(24),
            );

            $this->info('New unread email analyzed.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            Log::error('JARVIS email monitor failed', ['exception' => $exception]);
            $this->error('Unable to monitor email provider.');

            return self::FAILURE;
        }
    }
}
