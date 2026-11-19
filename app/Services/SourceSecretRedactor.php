<?php

namespace App\Services;

class SourceSecretRedactor
{
    /** Return structural source, masking literal values and comments rather than guessing which are secrets. */
    public function source(string $source, bool $php): string
    {
        if ($php) {
            $tokens = token_get_all($source);
            $output = '';
            foreach ($tokens as $index => $token) {
                if (! is_array($token)) {
                    $output .= $token;

                    continue;
                }
                [$type, $text] = $token;
                if ($type === T_CONSTANT_ENCAPSED_STRING) {
                    $next = $this->nextToken($tokens, $index);
                    $identifier = substr($text, 1, -1);
                    $isStructuralKey = in_array($next, [']', '=>'], true)
                        && preg_match('/^[a-zA-Z][a-zA-Z0-9_.-]{0,29}$/D', $identifier);
                    $output .= $isStructuralKey ? $this->text($text) : $this->mask($text, "'[REDACTED LITERAL]'");
                } elseif (in_array($type, [T_COMMENT, T_DOC_COMMENT, T_ENCAPSED_AND_WHITESPACE, T_LNUMBER, T_DNUMBER], true)) {
                    $output .= $this->mask($text, '[REDACTED]');
                } elseif ($type === T_INLINE_HTML) {
                    $output .= $this->frontend($text);
                } else {
                    $output .= $text;
                }
            }

            return $this->text($output);
        }

        return $this->frontend($source);
    }

    /** Defense in depth for metadata, paths, and non-literal source. Never resolve environment/configuration values. */
    public function text(string $text): string
    {
        $patterns = [
            '/-----BEGIN [A-Z ]*(?:PRIVATE KEY|CERTIFICATE)-----.*?-----END [A-Z ]*(?:PRIVATE KEY|CERTIFICATE)-----/s',
            '/\bBearer\s+[A-Za-z0-9._~+\/-]+=*/i',
            '/\b(?:sk[-_]|ghp_|github_pat_|xox[baprs]-)[A-Za-z0-9_-]+/',
            '/\b(?:APP_KEY|password|secret|api[_-]?key|access[_-]?token|client[_-]?secret)\s*[:=]\s*[^\s,;]+/i',
            '/(?<=:\/\/)[^\s\/@]+:[^\s\/@]+@/',
            '/\b(?=[A-Za-z0-9_+=-]{32,}\b)(?=[A-Za-z0-9_+=-]*[0-9])[A-Za-z0-9_+=-]{32,}\b/',
        ];
        foreach ($patterns as $pattern) {
            $text = preg_replace_callback($pattern, fn (array $match): string => $this->mask($match[0], '[REDACTED]'), $text) ?? '[REDACTED]';
        }

        return $text;
    }

    private function frontend(string $source): string
    {
        $source = preg_replace_callback('/(["\x27`])(?:\\\\.|(?!\1).)*\1/s',
            fn (array $match): string => $this->mask($match[0], '"[REDACTED LITERAL]"'), $source) ?? '[REDACTED]';
        $source = preg_replace_callback('/\/\*.*?\*\/|<!--.*?-->|\/\/[^\r\n]*/s',
            fn (array $match): string => $this->mask($match[0], '[COMMENT REDACTED]'), $source) ?? '[REDACTED]';
        $source = preg_replace_callback('/>[^<>]+</s',
            fn (array $match): string => '>'.$this->mask(substr($match[0], 1, -1), '[TEXT REDACTED]').'<', $source) ?? '[REDACTED]';

        return $this->text($source);
    }

    private function mask(string $original, string $replacement): string
    {
        return $replacement.str_repeat("\n", substr_count($original, "\n"));
    }

    private function nextToken(array $tokens, int $index): string
    {
        for ($nextIndex = $index + 1; $nextIndex < count($tokens); $nextIndex++) {
            $token = $tokens[$nextIndex];
            if (is_array($token) && $token[0] === T_WHITESPACE) {
                continue;
            }

            return is_array($token) ? $token[1] : $token;
        }

        return '';
    }
}
