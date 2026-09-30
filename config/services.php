<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have a
    | conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Anthropic / Claude
    |--------------------------------------------------------------------------
    |
    | Used by JARVIS for Claude API requests and credit display.
    |
    */

    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),

        'credit_multiplier' => (float) env(
            'ANTHROPIC_CREDIT_MULTIPLIER',
            2
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | GoDaddy Email
    |--------------------------------------------------------------------------
    |
    | GoDaddy email/IMAP/SMTP configuration used by JARVIS.
    |
    */

    'godaddy' => [
        'email' => env('GODADDY_EMAIL'),
        'password' => env('GODADDY_EMAIL_PASSWORD'),

        'imap' => [
            'host' => env(
                'GODADDY_IMAP_HOST',
                'imap.secureserver.net'
            ),

            'port' => (int) env(
                'GODADDY_IMAP_PORT',
                993
            ),

            'encryption' => env(
                'GODADDY_IMAP_ENCRYPTION',
                'ssl'
            ),
        ],

        'smtp' => [
            'host' => env(
                'GODADDY_SMTP_HOST',
                'smtpout.secureserver.net'
            ),

            'port' => (int) env(
                'GODADDY_SMTP_PORT',
                465
            ),

            'encryption' => env(
                'GODADDY_SMTP_ENCRYPTION',
                'ssl'
            ),
        ],
    ],

];
