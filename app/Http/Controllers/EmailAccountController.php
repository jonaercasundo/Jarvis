<?php

namespace App\Http\Controllers;

use App\Models\EmailAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
}
