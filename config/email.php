<?php

use App\Services\Email\Providers\ImapEmailProvider;

return [
    'provider' => env('EMAIL_PROVIDER', 'godaddy'),

    // These provider labels use the existing IMAP transport. Native Gmail
    // and Microsoft Graph adapters can be mapped here when added.
    'providers' => [
        'godaddy' => ImapEmailProvider::class,
        'imap' => ImapEmailProvider::class,
        'gmail' => ImapEmailProvider::class,
        'outlook' => ImapEmailProvider::class,
        'microsoft365' => ImapEmailProvider::class,
    ],
];
