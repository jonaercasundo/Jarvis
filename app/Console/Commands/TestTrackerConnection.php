<?php

namespace App\Console\Commands;

use App\Exceptions\WorkflowHttpException;
use App\Models\ApiConnection;
use App\Services\Workflow\CustomApiWorkflowExecutor;
use App\Services\WorkflowHttpService;
use Illuminate\Console\Command;
use Throwable;

class TestTrackerConnection extends Command
{
    protected $signature = 'jarvis:test-connection {connection=1 : Saved MMC Tracker connection ID} {--timeout=12 : Diagnostic request timeout, up to 30 seconds} {--deliveries : Also test deliveries} {--compare-curl : Compare with the system curl client}';

    protected $description = 'Diagnose real read-only Tracker requests without printing credentials or records';

    public function handle(CustomApiWorkflowExecutor $executor, WorkflowHttpService $http): int
    {
        $connection = ApiConnection::query()->find($this->argument('connection'));
        $timeout = filter_var($this->option('timeout'), FILTER_VALIDATE_INT);
        if (! $connection || rtrim($connection->base_url, '/') !== 'https://tracker.metro-mobilia.com'
            || $connection->authentication !== 'bearer' || $timeout === false || $timeout < 1 || $timeout > 30) {
            $this->error('Choose a saved MMC Tracker Bearer connection and a timeout from 1 to 30 seconds.');

            return self::FAILURE;
        }
        $paths = ['/api/jarvis/test', '/api/jarvis/projects'];
        if ($this->option('deliveries')) {
            $paths[] = '/api/jarvis/deliveries';
        }
        $failed = false;
        foreach ($paths as $path) {
            try {
                $result = $executor->execute(['_owner_key' => $connection->owner_key,
                    'config' => ['app' => 'custom_api', 'action' => 'request', 'values' => [
                        'connection_id' => $connection->id, 'method' => 'GET', 'url' => $path,
                    ]]], timeout: $timeout);
                $this->line(json_encode(['client' => 'Laravel', ...$result['diagnostic']], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            } catch (WorkflowHttpException $exception) {
                $failed = true;
                $this->line(json_encode(['client' => 'Laravel', ...$exception->details], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            } catch (Throwable $exception) {
                $failed = true;
                $this->line(json_encode(['client' => 'Laravel', 'endpoint' => $path, 'success' => false,
                    'exception_class' => $exception::class, 'message' => 'The diagnostic request failed.'], JSON_THROW_ON_ERROR));
            }
            if ($this->option('compare-curl')) {
                try {
                    $result = $this->curlCheck($connection, $path, $timeout, $http);
                    $failed = $failed || ! $result['success'];
                    $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
                } catch (Throwable $exception) {
                    $failed = true;
                    $this->line(json_encode(['client' => 'curl', 'endpoint' => $path, 'success' => false,
                        'exception_class' => $exception::class, 'message' => 'The system curl diagnostic could not run.'], JSON_THROW_ON_ERROR));
                }
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function curlCheck(#[\SensitiveParameter] ApiConnection $connection, string $path, int $timeout, WorkflowHttpService $http): array
    {
        $url = rtrim($connection->base_url, '/').$path;
        $options = $http->requestOptions($url);
        $process = proc_open([PHP_OS_FAMILY === 'Windows' ? 'curl.exe' : 'curl', '--config', '-',
            '--silent', '--noproxy', '*', '--output', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null',
            '--connect-timeout', '5', '--max-time', (string) $timeout,
            '--write-out', '%{http_code} %{time_total} %{time_connect} %{time_appconnect} %{time_starttransfer} %{remote_ip}'],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        if (! is_resource($process)) {
            throw new \RuntimeException('System curl is unavailable.');
        }
        $config = 'url = '.json_encode($url, JSON_UNESCAPED_SLASHES).PHP_EOL
            .'header = '.json_encode('Accept: application/json').PHP_EOL
            .'header = '.json_encode('Authorization: Bearer '.$connection->token, JSON_UNESCAPED_SLASHES).PHP_EOL;
        foreach ($options['curl'][CURLOPT_RESOLVE] ?? [] as $resolve) {
            $config .= 'resolve = '.json_encode($resolve, JSON_UNESCAPED_SLASHES).PHP_EOL;
        }
        fwrite($pipes[0], $config);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        $fields = explode(' ', trim($output));

        return ['client' => 'curl', 'method' => 'GET', 'url' => $url, 'query' => [],
            'success' => $exitCode === 0 && (int) ($fields[0] ?? 0) >= 200 && (int) ($fields[0] ?? 0) < 300,
            'status' => (int) ($fields[0] ?? 0) ?: null, 'curl_errno' => $exitCode,
            'connect_timeout' => 5, 'total_timeout' => $timeout,
            'elapsed_ms' => (int) round((float) ($fields[1] ?? 0) * 1000),
            'transfer' => ['connect_time' => (float) ($fields[2] ?? 0), 'appconnect_time' => (float) ($fields[3] ?? 0),
                'starttransfer_time' => (float) ($fields[4] ?? 0), 'primary_ip' => $fields[5] ?? null]];
    }
}
