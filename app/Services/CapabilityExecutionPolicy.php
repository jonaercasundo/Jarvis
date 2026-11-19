<?php

namespace App\Services;

class CapabilityExecutionPolicy
{
    public const READ_ONLY = 'READ_ONLY';

    public const WRITE = 'WRITE';

    public const EXTERNAL_SIDE_EFFECT = 'EXTERNAL_SIDE_EFFECT';

    public const DESTRUCTIVE = 'DESTRUCTIVE';

    public function isDemonstration(string $prompt): bool
    {
        return preg_match('/\b(?:demo|demonstrat(?:e|ion|ions))\b|\bshow me (?:how|what you can do)\b/i', $prompt) === 1;
    }

    public function isInformational(string $prompt): bool
    {
        return preg_match('/^\s*(?:(?:please|hi|hello)[,\s]+)*(?:what (?:can you|are your|tools|functions)|which tools|how (?:does your|do you)|where (?:is|are)|do you (?:currently )?support|can you (?:read|search|send|access|modify|inspect)|what can .* do|what .*capabilit)/i', $prompt) === 1
            || preg_match('/\b(?:your (?:actual )?(?:capabilities|functions|tools)|your own code|self.inspection|source code|implementation)\b/i', $prompt) === 1
            || preg_match('/^\s*(?:please\s+)?(?:explain|describe|tell me about|how can you help|how does (?:this|it) work|just show me|inspect (?:your|the) (?:source|code))\b/i', $prompt) === 1;
    }

    public function isExplicitSafeDemo(string $prompt): bool
    {
        return preg_match('/^\s*(?:please\s+)?(?:demo|demonstrate)\s+(?:email search|source (?:code )?inspection)\s*[.!?]*$/i', $prompt) === 1;
    }

    /** @param array<string, mixed> $metadata */
    public function permits(array $metadata, string $prompt, string $preference): bool
    {
        if (($metadata['category'] ?? '') === 'presentation') {
            return trim($prompt) !== '' && (! $this->isDemonstration($prompt) || $preference !== 'never_demo');
        }
        if (($metadata['category'] ?? '') === 'self_awareness'
            && (! $this->isDemonstration($prompt) || in_array('describe_capabilities', $metadata['operations'] ?? [], true))) {
            return true;
        }
        if ($this->isDemonstration($prompt)) {
            return ($metadata['risk'] ?? self::EXTERNAL_SIDE_EFFECT) === self::READ_ONLY
                && $preference !== 'never_demo'
                && ($preference === 'automatic_safe_demo' || $this->isExplicitSafeDemo($prompt));
        }

        if (trim($prompt) === '' || $this->isInformational($prompt)) {
            return false;
        }
        if (($metadata['risk'] ?? self::EXTERNAL_SIDE_EFFECT) !== self::READ_ONLY) {
            foreach ($metadata['intent_verbs'] ?? [] as $verb) {
                if (preg_match('/\b'.preg_quote($verb, '/').'\b/i', $prompt)) {
                    return true;
                }
            }

            return false;
        }

        return true;
    }
}
