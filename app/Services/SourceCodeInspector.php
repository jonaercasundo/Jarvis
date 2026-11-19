<?php

namespace App\Services;

use FilesystemIterator;
use Illuminate\Support\Facades\Route;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

class SourceCodeInspector
{
    public const ROOTS = ['app/Ai', 'app/Services', 'app/Http', 'app/Models', 'app/Contracts', 'app/Providers', 'app/Jobs', 'app/Console', 'routes', 'resources/js', 'resources/views', 'config'];

    public const OPERATIONS = ['search_code', 'read_file', 'find_class', 'find_method', 'find_tool', 'find_route', 'inspect_service', 'inspect_workflow_action'];

    private const MAX_FILE_BYTES = 1048576;

    public function __construct(private SourceSecretRedactor $redactor, private ?string $baseDirectory = null) {}

    /** @return array<string, mixed> */
    public function inspect(string $operation, string $query = '', ?string $path = null, int $startLine = 1, int $lineCount = 80): array
    {
        if (! in_array($operation, self::OPERATIONS, true) || strlen($query) > 160 || strlen($path ?? '') > 500) {
            throw new RuntimeException('Use a supported inspection operation and a short source query.');
        }
        if ($operation === 'read_file') {
            return $this->read($path ?? '', $startLine, $lineCount);
        }
        if (trim($query) === '') {
            throw new RuntimeException('Enter a class, method, tool, route, or source search term.');
        }
        if ($operation === 'find_route') {
            $routes = [];
            foreach (Route::getRoutes() as $route) {
                if (str_contains(strtolower($route->uri().' '.$route->getActionName()), strtolower($query))) {
                    $routes[] = ['uri' => $this->redactor->text($route->uri()), 'methods' => $route->methods(), 'controller' => $this->redactor->text($route->getActionName())];
                }
                if (count($routes) === 30) {
                    break;
                }
            }

            return ['routes' => $routes, 'read_only' => true];
        }
        if ($operation === 'inspect_workflow_action') {
            $executors = app(WorkflowAppExecutorRegistry::class)->capabilities();

            return ['executors' => array_filter($executors, fn (array $metadata, string $app): bool => str_contains(strtolower($app.' '.implode(' ', $metadata['actions'])), strtolower($query)), ARRAY_FILTER_USE_BOTH), 'read_only' => true];
        }
        $scope = match ($operation) {
            'find_tool' => 'app/Ai/Tools', 'inspect_service' => 'app/Services',
            'inspect_workflow_action' => 'app/Services', default => $path,
        };
        $symbol = basename(str_replace('\\', '/', $query));
        $pattern = match ($operation) {
            'find_class', 'find_tool', 'inspect_service' => '/\b(?:class|interface|trait|enum)\s+'.preg_quote($symbol, '/').'\b/i',
            'find_method' => '/\bfunction\s+'.preg_quote($symbol, '/').'\s*\(/i',
            default => null,
        };
        $matches = [];
        $filesScanned = 0;
        $bytesScanned = 0;
        foreach ($this->files($scope) as $file) {
            if (++$filesScanned > 500 || ($bytesScanned += filesize($file)) > 4194304) {
                break;
            }
            $lines = explode("\n", $this->sanitized($file));
            foreach ($lines as $index => $line) {
                $matched = $pattern ? preg_match($pattern, $line) === 1 : stripos($line, $query) !== false;
                if ($matched) {
                    $matches[] = ['path' => $this->redactor->text($this->relative($file)), 'line' => $index + 1, 'code' => mb_substr($line, 0, 500)];
                }
                if (count($matches) >= 30) {
                    break 2;
                }
            }
        }

        return ['matches' => $matches, 'read_only' => true, 'bounded_search' => true,
            'note' => 'Literal values and comments are masked. A missing match does not prove a capability is absent.'];
    }

    private function read(string $path, int $startLine, int $lineCount): array
    {
        $file = $this->resolve($path);
        if (! is_file($file) || ! $this->sourceFile($file)) {
            throw new RuntimeException('Only approved application source files can be inspected.');
        }
        $lines = explode("\n", $this->sanitized($file));
        $startLine = max(1, $startLine);
        $lineCount = max(1, min(120, $lineCount));
        $selected = [];
        foreach (array_slice($lines, $startLine - 1, $lineCount, true) as $index => $line) {
            $selected[] = ($index + 1).': '.mb_substr($line, 0, 600);
        }

        return ['path' => $this->redactor->text($this->relative($file)), 'source' => implode("\n", $selected), 'total_lines' => count($lines),
            'read_only' => true, 'literal_values_redacted' => true,
            'note' => 'Source structure only. Literal values and comments are redacted to protect hard-coded credentials.'];
    }

    private function sanitized(string $file): string
    {
        $file = $this->resolve($file);
        if (filesize($file) > self::MAX_FILE_BYTES) {
            throw new RuntimeException('This source file exceeds the inspection size limit.');
        }
        $content = file_get_contents($file);
        if ($content === false || str_contains($content, "\0")) {
            throw new RuntimeException('This source file cannot be inspected.');
        }

        return $this->redactor->source($content, str_ends_with(strtolower($file), '.php'));
    }

    private function files(?string $scope): iterable
    {
        $directories = $scope ? [$this->resolve($scope)] : array_map(fn (string $root): string => $this->base().'/'.$root, self::ROOTS);
        foreach ($directories as $directory) {
            if (is_file($directory)) {
                if ($this->sourceFile($directory)) {
                    yield $this->resolve($directory);
                }

                continue;
            }
            if (! is_dir($directory)) {
                continue;
            }
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $entry) {
                if (! $entry->isFile() || $entry->isLink()) {
                    continue;
                }
                try {
                    $file = $this->resolve($entry->getPathname());
                } catch (RuntimeException) {
                    continue;
                }
                if ($this->sourceFile($file) && filesize($file) <= self::MAX_FILE_BYTES) {
                    yield $file;
                }
            }
        }
    }

    private function resolve(string $path): string
    {
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '://')) {
            throw new RuntimeException('The requested path is outside approved application source.');
        }
        $isAbsolute = str_starts_with($path, '/') || preg_match('/^(?:[A-Za-z]:[\\\\\/]|\\\\\\\\)/', $path) === 1;
        $candidate = realpath($isAbsolute ? $path : $this->base().'/'.$path);
        if ($candidate === false) {
            throw new RuntimeException('The requested source path is unavailable.');
        }
        $candidate = str_replace('\\', '/', $candidate);
        foreach (self::ROOTS as $root) {
            $approved = $this->base().'/'.$root;
            if ($this->within($candidate, $approved)) {
                if ($this->blocked($this->relative($candidate))) {
                    break;
                }

                return $candidate;
            }
        }
        throw new RuntimeException('The requested path is outside approved application source.');
    }

    private function sourceFile(string $path): bool
    {
        return ! $this->blocked($this->relative($path)) && preg_match('/\.(?:php|js|ts|jsx|tsx|vue|html|css)$/iD', $path) === 1;
    }

    private function blocked(string $path): bool
    {
        return preg_match('~(?:^|/)(?:\.[^/]*|storage|vendor|node_modules|(?:[^/]*[_-])?(?:credentials?|tokens?|secrets?|certificates?|private[_-]?keys?))(?:/|\.|$)|\.(?:pem|key|crt|cer|p12|pfx|sql|sqlite|bak)(?:\.|$)|(?:^|/)id_(?:rsa|ed25519)~i', $path) === 1;
    }

    private function within(string $path, string $root): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $path = strtolower($path);
            $root = strtolower($root);
        }

        return $path === $root || str_starts_with($path, $root.'/');
    }

    private function relative(string $path): string
    {
        return substr(str_replace('\\', '/', $path), strlen($this->base()) + 1);
    }

    private function base(): string
    {
        $base = realpath($this->baseDirectory ?? base_path());
        if ($base === false) {
            throw new RuntimeException('Application source is unavailable.');
        }

        return str_replace('\\', '/', $base);
    }
}
