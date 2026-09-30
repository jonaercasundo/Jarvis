<?php

namespace App\Http\Controllers;

use App\Models\JarvisSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

class JarvisSettingsController extends Controller
{
    public function show()
    {
        return response()->json([
            'success' => true,
            'settings' => JarvisSetting::current()->toPublic(),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'ai_name' => 'required|string|max:30',
            'owner_name' => 'nullable|string|max:60',
            'api_key' => 'nullable|string|max:300',
            'allowed_actions' => 'array',
            'allowed_actions.*' => 'in:read_emails,search_emails,draft_replies,send_replies,send_emails,analysis',
            'confirm_before_send' => 'boolean',
            'tone' => 'in:professional,friendly,concise,formal',
            'daily_credit_limit' => 'integer|min:0',
            'scope_of_work' => 'nullable|string|max:2000',
            'limitations' => 'nullable|string|max:2000',

            // Email settings
            'email_address' => 'nullable|email|max:255',
            'email_password' => 'nullable|string|max:255',
            'imap_host' => 'nullable|string|max:255',
            'imap_port' => 'nullable|integer|min:1|max:65535',
            'imap_encryption' => 'nullable|in:ssl,tls,none',
            'smtp_host' => 'nullable|string|max:255',
            'smtp_port' => 'nullable|integer|min:1|max:65535',
            'smtp_encryption' => 'nullable|in:ssl,tls,none',
        ]);

        $settings = JarvisSetting::current();

        // API key: only replace if a new one was typed
        $apiKey = $data['api_key'] ?? null;
        unset($data['api_key']);

        if ($apiKey) {
            $data['api_key_encrypted'] = Crypt::encryptString($apiKey);
            $data['api_key_hint'] = '…'.substr($apiKey, -4);
        }

        // Email password: only replace if a new one was typed
        $emailPassword = $data['email_password'] ?? null;
        unset($data['email_password']);

        if ($emailPassword) {
            $data['email_password_encrypted'] = Crypt::encryptString($emailPassword);
        }

        $settings->update($data);

        return response()->json([
            'success' => true,
            'settings' => $settings->fresh()->toPublic(),
        ]);
    }
}
