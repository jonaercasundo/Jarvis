<?php

namespace App\Services\Email;

class EmailNotificationDecision
{
    /** @param array<string, mixed> $analysis */
    public function shouldNotify(array $analysis): bool
    {
        if (($analysis['classification'] ?? null) === 'CRITICAL' || strtoupper((string) ($analysis['priority'] ?? '')) === 'CRITICAL') {
            return true;
        }

        if (! ($analysis['notification_required'] ?? false)) {
            return false;
        }

        $hasImpactSignal = ($analysis['requires_action'] ?? false)
            || ($analysis['time_sensitive'] ?? false)
            || ($analysis['business_impact'] ?? false);

        if ($hasImpactSignal) {
            return true;
        }

        return in_array($analysis['classification'] ?? null, ['ACTION_REQUIRED', 'IMPORTANT'], true)
            && in_array(strtoupper((string) ($analysis['priority'] ?? '')), ['CRITICAL', 'HIGH'], true);
    }
}
