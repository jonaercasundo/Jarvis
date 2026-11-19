<?php

namespace App\Services;

use App\Services\Email\Providers\ImapEmailProvider;

/**
 * Compatibility façade for existing JARVIS email tools.
 * New provider-agnostic flows should resolve EmailManager instead.
 */
class GoDaddyEmailService extends ImapEmailProvider {}
