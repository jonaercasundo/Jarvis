<?php

namespace App\Services\Workflow;

use App\Contracts\Workflows\WorkflowAppExecutor;
use App\Exceptions\WorkflowHttpException;
use App\Models\ApiConnection;
use App\Services\SourceSecretRedactor;
use App\Services\WorkflowHttpService;
use GuzzleHttp\TransferStats;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class CustomApiWorkflowExecutor implements WorkflowAppExecutor
{
    private const ACTIONS = ['request', 'call_api'];

    private const METHOD_RISKS = ['GET' => 'READ_ONLY', 'POST' => 'EXTERNAL_SIDE_EFFECT', 'PUT' => 'EXTERNAL_SIDE_EFFECT', 'PATCH' => 'EXTERNAL_SIDE_EFFECT', 'DELETE' => 'DESTRUCTIVE'];

    public function capabilities(): array
    {
        return ['actions' => self::ACTIONS, 'method_risks' => self::METHOD_RISKS, 'configuration' => 'saved_api_connection_or_public_https'];
    }

    public function __construct(private WorkflowHttpService $http) {}

    public function app(): string
    {
        return 'custom_api';
    }

    /** @param array<string, mixed> $node */
    public function execute(array $node, bool $dryRun = false, int $timeout = 12): array
    {
        if ($timeout < 1 || $timeout > 30) {
            throw new RuntimeException('The diagnostic timeout must be between 1 and 30 seconds.');
        }
        $config = $node['config'] ?? [];
        if (! in_array($config['action'] ?? '', self::ACTIONS, true)) {
            throw new RuntimeException('Choose Call an API for the Custom API step.');
        }
        $values = $config['values'] ?? $config;
        $timeout = $values['timeout'] ?? $timeout;
        if (! is_int($timeout) || $timeout < 1 || $timeout > 30) {
            throw new RuntimeException('The request timeout must be between 1 and 30 seconds.');
        }
        $method = $values['method'] ?? 'GET';
        if (! array_key_exists($method, self::METHOD_RISKS)) {
            throw new RuntimeException('Choose GET, POST, PUT, PATCH, or DELETE.');
        }
        $connectionId = $values['connection_id'] ?? $values['connection'] ?? null;
        $connection = null;
        if ($connectionId !== null && $connectionId !== '') {
            $connection = ApiConnection::query()->where('owner_key', $node['_owner_key'] ?? '')
                ->whereKey($connectionId)->first();
            if (! $connection) {
                throw new RuntimeException('The saved API connection is unavailable.');
            }
        }
        $url = $values['url'] ?? '';
        if (! is_string($url) || strlen($url) > 2048) {
            throw new RuntimeException('Use a valid HTTPS URL or relative path.');
        }
        if (str_starts_with($url, '/') && ! str_starts_with($url, '//') && $connection) {
            $url = rtrim($connection->base_url, '/').$url;
        }
        if (str_contains($url, '\\') || preg_match('/[\x00-\x20{}]/', $url)) {
            throw new RuntimeException('Use a valid HTTPS URL or relative path.');
        }
        if ($connection && $this->origin($url) !== $this->origin($connection->base_url)) {
            throw new RuntimeException('The API endpoint must use the saved connection base URL origin.');
        }
        $this->http->validateUrl($url);
        $headers = $this->pairs($values['headers'] ?? [], ':');
        foreach ($headers as $name => $value) {
            if (! preg_match('/^[A-Za-z0-9-]+$/D', (string) $name) || ! is_scalar($value) || preg_match('/[\r\n]/', (string) $value)) {
                throw new RuntimeException('Extra headers must contain valid names and single-line values.');
            }
            if (preg_match('/authorization|cookie|token|secret|api[-_]?key|metabase-session|host|proxy|content-length|transfer-encoding/i', (string) $name)) {
                throw new RuntimeException('Authentication and transport headers cannot be overridden. Use a saved connection.');
            }
        }
        $query = $this->pairs($values['query'] ?? [], '=');
        $body = $values['body'] ?? null;
        if (is_string($body) && trim($body) !== '') {
            try {
                $body = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new RuntimeException('JSON body must contain valid JSON.');
            }
        } elseif ($body === '') {
            $body = null;
        }
        $paths = $values['response_paths'] ?? $values['response_fields'] ?? [];
        $paths = is_string($paths) ? preg_split('/[,\r\n]+/', $paths) : $paths;
        if (! is_array($paths) || count($paths) > 30) {
            throw new RuntimeException('Response paths must be a list of dotted paths.');
        }
        foreach ($paths as $path) {
            if (! is_string($path) || (trim($path) !== '' && ! preg_match('/^[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)*$/D', trim($path)))) {
                throw new RuntimeException('Response paths must use dotted field names.');
            }
        }
        $paths = array_values(array_filter(array_map('trim', $paths)));
        if ($dryRun) {
            return ['success' => true, 'simulated' => true, 'status' => 200, 'response' => []];
        }
        $authenticationHeaders = $connection?->authenticationHeaders() ?? [];
        $token = $connection?->token;
        $requestOptions = $this->http->requestOptions($url);
        $diagnostic = $this->requestDiagnostic($method, $url, $query, $token, $requestOptions, $timeout);
        $diagnostic['connection'] = $this->redact($connection?->name ?? 'Direct HTTPS request', $token);
        $transferStats = [];
        $requestOptions['on_stats'] = function (TransferStats $stats) use (&$transferStats): void {
            $transferStats = array_intersect_key($stats->getHandlerStats(), array_flip([
                'primary_ip', 'namelookup_time', 'connect_time', 'appconnect_time',
                'starttransfer_time', 'total_time', 'size_download',
            ]));
            $transferStats['curl_errno'] = is_int($stats->getHandlerErrorData()) ? $stats->getHandlerErrorData() : null;
        };
        $startedAt = microtime(true);
        try {
            $request = Http::connectTimeout(5)->timeout($timeout)->acceptJson()->asJson()
                ->withOptions($requestOptions)->withHeaders($headers);
            $request = $request->withHeaders($authenticationHeaders);
            $options = $query === [] ? [] : ['query' => $query];
            if ($body !== null) {
                $options['json'] = $body;
            }
            $response = $request->send($method, $url, $options);
        } catch (\Throwable $exception) {
            throw $this->transportFailure($exception, $diagnostic, $transferStats, $token, $startedAt);
        }
        $diagnostic = [...$diagnostic, 'status' => $response->status(), 'transfer' => $transferStats,
            'response_bytes' => strlen($response->body()),
            'elapsed_seconds' => round(microtime(true) - $startedAt, 3),
            'elapsed_ms' => (int) round((microtime(true) - $startedAt) * 1000)];
        Log::info('Workflow API HTTP result', $diagnostic);
        $status = $response->status();
        if (! $response->successful()) {
            $message = match ($status) {
                401 => 'Authentication failed', 403 => 'Permission denied', 404 => 'Endpoint not found',
                422 => 'Validation failed', 429 => 'Rate limited',
                default => $status >= 500 ? 'Remote API error' : 'API request failed',
            };
            throw new WorkflowHttpException(['message' => $status.' - '.$message, 'status' => $status, 'diagnostic' => $diagnostic]);
        }
        $result = $response->json() ?? $response->body();
        $result = $this->redact($result, $token);
        $pagination = is_array($result) ? (data_get($result, 'pagination') ?? data_get($result, 'meta.pagination')) : null;
        if (($values['compact_project_summaries'] ?? false) === true
            && $method === 'GET' && parse_url($url, PHP_URL_PATH) === '/api/jarvis/projects/delivery-progress'
            && is_array(data_get($result, 'data.projects'))) {
            $result['data']['projects'] = array_map(fn (array $project): array => Arr::only($project, [
                'project_id', 'project_name', 'ref_no', 'project_status', 'is_active', 'delivery_progress_percent',
                'billing_progress_percent', 'overall_progress', 'last_activity', 'delivery_coverage',
                'total_deliveries_count', 'total_package_allocations_count', 'total_packages_count', 'completed_deliveries_count',
                'pending_deliveries_count', 'billing_groups_count', 'for_billing_groups_count', 'billed_groups_count', 'paid_groups_count',
            ]), $result['data']['projects']);
            $result['meta'] = Arr::only($result['meta'] ?? [], ['generated_at', 'filters', 'pagination', 'supported_filters']);
            $result['data'] = Arr::only($result['data'], ['projects']);
        }
        if ($paths !== []) {
            $selected = [];
            foreach ($paths as $path) {
                if (! is_array($result) || ! Arr::has($result, $path)) {
                    throw new RuntimeException('A requested response path is missing.');
                }
                data_set($selected, $path, data_get($result, $path));
            }
            $result = $selected;
        }

        $output = ['success' => true, 'status' => $status, 'response' => $result, 'result' => $result, 'diagnostic' => $diagnostic,
            'pagination' => $pagination];
        if (strlen(json_encode($output, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) > 50000) {
            return ['success' => false, 'error' => 'response_too_large',
                'message' => 'API response exceeds the 50,000-byte workflow response limit.',
                'status' => $status, 'diagnostic' => $diagnostic,
                'suggestions' => [
                    'Use a smaller per_page value (default 20, recommended maximum 50).',
                    'Add supported query filters.', 'Select only required response fields.',
                    ...($connection?->integration === 'mmc_tracker' ? ['Use delivery-progress for MMC Tracker project summaries.'] : []),
                    'Retry only a safely narrowed request; never repeat the same oversized request.',
                ]];
        }

        return $output;
    }

    private function transportFailure(\Throwable $exception, array $diagnostic, array $transferStats, #[\SensitiveParameter] ?string $token, float $startedAt): WorkflowHttpException
    {
        $context = [];
        for ($cause = $exception; $cause !== null; $cause = $cause->getPrevious()) {
            if (method_exists($cause, 'getHandlerContext')) {
                $context = $cause->getHandlerContext();
                break;
            }
        }
        $errno = $context['errno'] ?? $transferStats['curl_errno'] ?? null;
        if ($errno === null && preg_match('/cURL error (\d+):/', $exception->getMessage(), $match)) {
            $errno = (int) $match[1];
        }
        $errorType = match ($errno) {
            28 => 'timeout', 6 => 'dns', 7 => 'connection',
            35, 51, 58, 60, 77 => 'tls',
            default => $exception instanceof ConnectionException ? 'connection' : 'transport',
        };
        $message = match ($errorType) {
            'timeout' => 'API request timed out after '.$diagnostic['total_timeout'].' seconds.',
            'dns' => 'API host could not be resolved.',
            'tls' => 'API TLS connection or certificate verification failed.',
            'connection' => 'API connection could not be established.',
            default => 'The API request could not be completed.',
        };
        $details = [...$diagnostic, 'success' => false, 'error_type' => $errorType,
            'curl_errno' => $errno, 'message' => $message, 'transfer' => $transferStats,
            'elapsed_seconds' => round(microtime(true) - $startedAt, 3),
            'elapsed_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'exception_class' => $exception::class];
        $safeMessage = $this->redact($exception->getMessage(), $token);
        $safeMessage = preg_replace('~https?://[^\s)]+~i', '[endpoint]', $safeMessage) ?? '[REDACTED]';
        Log::warning('Workflow API transport failure', [...$details,
            'exception_class' => $exception::class,
            'curl_message' => app(SourceSecretRedactor::class)->text($safeMessage),
            'exception_file' => $exception->getFile(), 'exception_line' => $exception->getLine(),
        ]);

        return new WorkflowHttpException($details);
    }

    private function requestDiagnostic(string $method, string $url, array $query, #[\SensitiveParameter] ?string $token, array $options, int $timeout): array
    {
        $parts = parse_url($url);
        $urlQuery = [];
        parse_str($parts['query'] ?? '', $urlQuery);
        $query = $query === [] ? $urlQuery : $query;
        $query = $this->safeQuery($query, $token);
        $path = app(SourceSecretRedactor::class)->text($this->redact($parts['path'] ?? '/', $token));
        $safeUrl = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '').$path;

        return ['method' => $method, 'host' => $parts['host'], 'endpoint' => $path,
            'url' => $safeUrl, 'query' => $query, 'connect_timeout' => 5, 'total_timeout' => $timeout,
            'request_options' => ['allow_redirects' => false, 'proxy' => 'disabled', 'verify' => true,
                'resolve' => $options['curl'][CURLOPT_RESOLVE] ?? []]];
    }

    private function safeQuery(array $query, #[\SensitiveParameter] ?string $token): array
    {
        $safe = [];
        foreach ($query as $key => $value) {
            if (preg_match('/authorization|cookie|password|secret|token|api[-_]?key/i', (string) $key)) {
                continue;
            }
            $safeKey = app(SourceSecretRedactor::class)->text($this->redact((string) $key, $token));
            $safe[$safeKey] = is_array($value) ? $this->safeQuery($value, $token)
                : (is_string($value) ? app(SourceSecretRedactor::class)->text($this->redact($value, $token)) : $value);
        }

        return $safe;
    }

    private function origin(string $url): array
    {
        $parts = parse_url($url) ?: [];

        return [strtolower($parts['scheme'] ?? ''), strtolower($parts['host'] ?? ''), $parts['port'] ?? 443];
    }

    private function pairs(mixed $value, string $separator): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value)) {
            throw new RuntimeException('Query parameters and headers must be key/value pairs.');
        }
        $pairs = [];
        foreach (preg_split('/\r?\n/', $value) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $parts = explode($separator, $line, 2);
            if (count($parts) !== 2 || trim($parts[0]) === '') {
                throw new RuntimeException('Use one key/value pair per line.');
            }
            $pairs[trim($parts[0])] = trim($parts[1]);
        }

        return $pairs;
    }

    private function redact(mixed $value, ?string $token): mixed
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = is_string($key) && preg_match('/authorization|cookie|password|secret|token|api[-_]?key|metabase.session/i', $key)
                    ? '[REDACTED]' : $this->redact($item, $token);
            }
        } elseif (is_string($value) && $token) {
            $value = str_replace([$token, rawurlencode($token), base64_encode($token)], '[REDACTED]', $value);
        }

        return $value;
    }
}
