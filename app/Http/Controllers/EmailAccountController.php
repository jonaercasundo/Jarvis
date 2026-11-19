<?php

namespace App\Http\Controllers;

use App\Models\EmailAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Webklex\PHPIMAP\ClientManager;

class EmailAccountController extends Controller
{
    public function index(): JsonResponse
    {
        $accounts = EmailAccount::query()
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get()
            ->map(function (EmailAccount $account) {
                return [
                    'id' => $account->id,
                    'name' => $account->name,
                    'email' => $account->email,

                    'imap_host' => $account->imap_host,
                    'imap_port' => $account->imap_port,
                    'imap_encryption' => $account->imap_encryption,
                    'imap_username' => $account->imap_username,

                    'smtp_host' => $account->smtp_host,
                    'smtp_port' => $account->smtp_port,
                    'smtp_encryption' => $account->smtp_encryption,
                    'smtp_username' => $account->smtp_username,

                    'is_default' => $account->is_default,
                    'is_active' => $account->is_active,
                ];
            });

        return response()->json([
            'success' => true,
            'accounts' => $accounts,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255'],

            'imap_host' => ['required', 'string', 'max:255'],
            'imap_port' => ['required', 'integer', 'between:1,65535'],
            'imap_encryption' => ['required', 'in:ssl,tls,none'],
            'imap_username' => ['required', 'string', 'max:255'],
            'imap_password' => ['required', 'string'],

            'smtp_host' => ['required', 'string', 'max:255'],
            'smtp_port' => ['required', 'integer', 'between:1,65535'],
            'smtp_encryption' => ['required', 'in:ssl,tls,none'],
            'smtp_username' => ['required', 'string', 'max:255'],
            'smtp_password' => ['required', 'string'],

            'is_default' => ['boolean'],
            'is_active' => ['boolean'],
        ]);

        if (! empty($validated['is_default'])) {
            EmailAccount::query()->update([
                'is_default' => false,
            ]);
        }

        $account = EmailAccount::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Email account saved.',
            'account' => [
                'id' => $account->id,
                'name' => $account->name,
                'email' => $account->email,
            ],
        ]);
    }

    public function destroy(EmailAccount $emailAccount): JsonResponse
    {
        $emailAccount->delete();

        return response()->json([
            'success' => true,
            'message' => 'Email account deleted.',
        ]);
    }

    public function testConnection(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],

            'imap_host' => ['required', 'string', 'max:255'],
            'imap_port' => ['required', 'integer', 'between:1,65535'],
            'imap_encryption' => ['required', 'in:ssl,tls,none'],
            'imap_username' => ['required', 'string', 'max:255'],
            'imap_password' => ['required', 'string'],

            'smtp_host' => ['required', 'string', 'max:255'],
            'smtp_port' => ['required', 'integer', 'between:1,65535'],
            'smtp_encryption' => ['required', 'in:ssl,tls,none'],
            'smtp_username' => ['required', 'string', 'max:255'],
            'smtp_password' => ['required', 'string'],
        ]);

        try {
            // IMAP test
            $imapManager = new ClientManager;

            $imapClient = $imapManager->make([
                'host' => $validated['imap_host'],
                'port' => $validated['imap_port'],
                'encryption' => $validated['imap_encryption'] === 'none'
                    ? false
                    : $validated['imap_encryption'],
                'validate_cert' => true,
                'username' => $validated['imap_username'],
                'password' => $validated['imap_password'],
                'protocol' => 'imap',
                'timeout' => 15,
            ]);

            $imapClient->connect();

            $folders = $imapClient->getFolders();

            $imapClient->disconnect();

            // SMTP test
            $smtpPort = (int) $validated['smtp_port'];

            config([
                'mail.mailers.smtp.host' => $validated['smtp_host'],
                'mail.mailers.smtp.port' => $smtpPort,
                'mail.mailers.smtp.username' => $validated['smtp_username'],
                'mail.mailers.smtp.password' => $validated['smtp_password'],
                'mail.mailers.smtp.scheme' => $smtpPort === 465 ? 'smtps' : null,
                'mail.from.address' => $validated['email'],
            ]);

            Mail::purge('smtp');

            Mail::mailer('smtp')->raw(
                'JARVIS connection test.',
                function ($message) use ($validated) {
                    $message
                        ->to($validated['email'])
                        ->from($validated['email'])
                        ->subject('JARVIS Email Connection Test');
                }
            );

            return response()->json([
                'success' => true,
                'message' => 'IMAP and SMTP connection successful.',
                'email' => $validated['email'],
                'imap_folders' => $folders->count(),
            ]);

        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Email connection failed.',
                'error' => config('app.debug')
                    ? $e->getMessage()
                    : 'Unable to connect to the email server.',
            ], 422);
        }
    }
}
