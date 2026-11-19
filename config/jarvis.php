<?php

use App\Models\User;

return [
    'ai' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'default_model' => 'claude-fable-5',
        'models' => ['claude-fable-5', 'claude-sonnet-5', 'claude-haiku-4-5-20251001', 'claude-opus-5-5'],
        'max_tool_iterations' => 8,
    ],
    'test_mode' => env('JARVIS_TEST_MODE', false),

    // Any Notification::send()-compatible channels. Add 'mail' or a
    // custom Slack/SMS channel here once you decide on one.
    'notification_channels' => ['database', 'broadcast'],

    // Who gets notified about important emails. Point this at a User
    // model (first row will be used) or swap the lookup in
    // CheckImportantEmails::handle() for something more specific
    // (e.g. User::where('email', 'you@yourdomain.com')->first()).
    'notifiable' => User::class,

    // How long a "confirm CODE" window stays open before expiring.
    'pending_action_ttl_minutes' => 30,

    'importance' => [
        // How many recent emails to check per scheduled run.
        'poll_limit' => 15,
    ],
];
