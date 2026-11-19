<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class JarvisSetting extends Model
{
    protected $fillable = [
        'user_id', 'ai_name', 'owner_name', 'api_key_encrypted', 'api_key_hint',
        'allowed_actions', 'confirm_before_send', 'tone', 'daily_credit_limit',
        'scope_of_work', 'limitations', 'workflow_url', 'learned_memory', 'wake_name_variants', 'active_vrm', 'capability_demonstrations',

        // Email settings
        'email_address', 'email_password_encrypted',
        'imap_host', 'imap_port', 'imap_encryption',
        'smtp_host', 'smtp_port', 'smtp_encryption',
    ];

    protected $hidden = ['api_key_encrypted', 'email_password_encrypted'];

    protected $casts = [
        'allowed_actions' => 'array',
        'confirm_before_send' => 'boolean',
        'daily_credit_limit' => 'integer',
        'wake_name_variants' => 'array',
    ];

    /** Get the settings row for the current user (created on first use). */
    public static function current(): self
    {
        return static::firstOrCreate(
            ['user_id' => auth()->id()],
            [
                'ai_name' => 'JC',
                'allowed_actions' => ['read_emails', 'search_emails', 'draft_replies', 'analysis', 'summarize', 'reply'],
            ]
        );
    }

    /** Decrypted Anthropic API key for server-side use only. */
    public function apiKey(): ?string
    {
        return $this->api_key_encrypted
            ? Crypt::decryptString($this->api_key_encrypted)
            : null;
    }

    /** Decrypted mailbox password for server-side use only. */
    public function emailPassword(): ?string
    {
        return $this->email_password_encrypted
            ? Crypt::decryptString($this->email_password_encrypted)
            : null;
    }

    /** Email credentials for server-side use. Falls back to .env if a field is empty. */
    public function emailConfig(): array
    {
        return [
            'address' => $this->email_address ?: config('services.godaddy.email'),
            'password' => $this->emailPassword() ?: config('services.godaddy.password'),
            'imap_host' => $this->imap_host ?: 'imap.secureserver.net',
            'imap_port' => $this->imap_port ?: 993,
            'imap_enc' => $this->imap_encryption ?: 'ssl',
            'smtp_host' => $this->smtp_host ?: 'smtpout.secureserver.net',
            'smtp_port' => $this->smtp_port ?: 465,
            'smtp_enc' => $this->smtp_encryption ?: 'ssl',
        ];
    }

    /** Safe version to send to the browser (never includes any secret). */
    public function toPublic(): array
    {
        return [
            'ai_name' => $this->ai_name,
            'owner_name' => $this->owner_name,
            'has_api_key' => (bool) $this->api_key_encrypted,
            'api_key_hint' => $this->api_key_hint,
            'allowed_actions' => $this->allowed_actions ?? [],
            'confirm_before_send' => $this->confirm_before_send,
            'tone' => $this->tone,
            'capability_demonstrations' => $this->capability_demonstrations ?? 'ask_first',
            'daily_credit_limit' => $this->daily_credit_limit,
            'scope_of_work' => $this->scope_of_work,
            'limitations' => $this->limitations,
            'learned_memory' => $this->learned_memory,
            'wake_name_variants' => $this->wake_name_variants ?? [],
            'workflow_url' => $this->workflow_url,

            // Email (password is never sent)
            'email_address' => $this->email_address,
            'has_email_password' => (bool) $this->email_password_encrypted,
            'imap_host' => $this->imap_host,
            'imap_port' => $this->imap_port,
            'imap_encryption' => $this->imap_encryption,
            'smtp_host' => $this->smtp_host,
            'smtp_port' => $this->smtp_port,
            'smtp_encryption' => $this->smtp_encryption,
        ];
    }
}
