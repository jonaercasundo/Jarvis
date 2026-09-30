<?php

use App\Models\User;

return [
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
