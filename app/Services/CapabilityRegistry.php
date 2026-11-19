<?php

namespace App\Services;

use App\Ai\Agents\JcAgent;
use App\Models\ApiConnection;
use App\Models\EmailAccount;
use App\Services\Email\EmailNotificationService;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Ai\Tools\ToolNameResolver;

class CapabilityRegistry
{
    public function __construct(private WorkflowAppExecutorRegistry $executors, private WorkflowNodeCatalog $nodes, private SourceSecretRedactor $redactor) {}

    /** No operational tools, remote requests, or credential decryption occur during discovery. */
    public function discover(JcAgent $agent): array
    {
        $settings = $agent->preferences();
        $email = $this->emailConfiguration();
        $tools = [];
        foreach ($agent->registeredTools() as $tool) {
            $metadata = method_exists($tool, 'capabilityMetadata') ? $tool->capabilityMetadata() : [];
            $configuration = $metadata['configuration'] ?? null;
            $configured = $metadata['configured'] ?? ($configuration === null || ($email[$configuration] ?? false));
            $verified = $metadata['connection_verified'] ?? null;
            $permitted = $metadata !== [] && array_diff($metadata['permissions'] ?? [], $settings->allowed_actions ?? []) === [];
            $requiresConfirmation = $metadata['requires_confirmation'] ?? true;
            $schema = array_map(fn ($type): array => $type->toArray(), $tool->schema(new JsonSchemaTypeFactory));
            $tools[] = [
                'name' => ToolNameResolver::resolve($tool), 'display_name' => Str::headline(ToolNameResolver::resolve($tool)),
                'description' => (string) $tool->description(), 'category' => $metadata['category'] ?? 'unclassified',
                'operations' => $metadata['operations'] ?? [], 'risk' => $metadata['risk'] ?? 'EXTERNAL_SIDE_EFFECT',
                'schema' => $schema, 'requires_confirmation' => $requiresConfirmation, 'configuration_requirement' => $configuration,
                'implemented' => true, 'configured' => $configured, 'available_to_agent' => true,
                'permitted' => $permitted, 'can_attempt' => $configured && $permitted, 'executable_now' => $configured && $permitted && $verified !== false,
                'connection_verified' => $verified, 'connection_ids' => $metadata['connection_ids'] ?? [],
                'status' => ! $configured ? 'Not configured' : (! $permitted ? 'Unavailable (disabled in Settings)' : ($verified === false ? 'Configured; remote connection unverified' : ($requiresConfirmation ? 'Requires confirmation' : 'Available'))),
                'implementation' => str_replace('\\', '/', str_replace('App\\', 'app/', get_class($tool))).'.php',
            ];
        }
        $integrations = [];
        $executors = $this->executors->capabilities();
        foreach ($this->uiIntegrations() as $app => $label) {
            $implemented = isset($executors[$app]);
            $integrations[$app] = ['name' => $label, 'listed_in_workflow_ui' => true,
                'implemented' => $implemented, 'available_to_agent' => false,
                'configured' => $app === 'gmail' ? $email['imap'] : false,
                'executable_now' => $app === 'gmail' ? ($implemented && $email['imap']) : ($app === 'custom_api' ? null : false),
                'status' => $implemented ? 'Implemented workflow executor; configuration is required per step' : 'UI-listed; no registered executable connector',
                'executor' => $executors[$app] ?? null];
        }
        $connections = $agent->ownerKey() === null ? [] : ApiConnection::query()->where('owner_key', $agent->ownerKey())
            ->select(['id', 'name', 'authentication'])->selectRaw("CASE WHEN token IS NOT NULL AND token <> '' THEN 1 ELSE 0 END AS has_credential")
            ->orderBy('name')->get()->map(fn (ApiConnection $connection): array => [
                'id' => $connection->id, 'name' => $connection->name, 'integration' => 'custom_api',
                'implemented' => isset($executors['custom_api']),
                'configured' => $connection->authentication === 'none' || (bool) $connection->getAttribute('has_credential'),
                'available_to_agent' => false, 'executable_now' => null, 'reachability_verified' => false,
                'status' => 'Saved configuration only; remote authentication and endpoint support are unverified',
            ])->all();
        $operationsTool = collect($tools)->firstWhere('name', 'AskOperationsAgent');
        if ($operationsTool && $operationsTool['configured'] && isset($integrations['mmc_tracker'])) {
            $integrations['mmc_tracker'] = array_replace($integrations['mmc_tracker'], [
                'implemented' => true, 'available_to_agent' => true, 'configured' => true,
                'executable_now' => $operationsTool['executable_now'], 'status' => $operationsTool['status'],
                'executor' => $executors['custom_api'] ?? null, 'provided_by' => 'AskOperationsAgent through Operations Agent',
            ]);
        }
        foreach ($connections as &$connection) {
            if ($operationsTool && in_array($connection['id'], $operationsTool['connection_ids'], true)) {
                $connection['available_to_agent'] = true;
                $connection['reachability_verified'] = $operationsTool['connection_verified'];
                $connection['executable_now'] = $operationsTool['executable_now'];
                $connection['status'] = $operationsTool['status'];
            }
        }
        unset($connection);
        $providers = [];
        foreach (config('email.providers', []) as $name => $class) {
            if (is_string($class)) {
                $implemented = class_exists($class);
                $methods = $implemented ? array_map(fn (\ReflectionMethod $method): string => $method->getName(),
                    (new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC)) : [];
                $providers[$name] = ['implementation' => $class, 'implemented' => $implemented, 'public_methods' => $methods];
            }
        }

        return $this->sanitize([
            'tools' => $tools, 'integrations' => $integrations, 'saved_connections' => $connections,
            'application' => [
                'workflow_nodes' => $this->nodes->all(), 'workflow_executors' => $executors,
                'email' => ['configuration' => $email, 'providers' => $providers, 'transport' => 'IMAP / SMTP', 'connection_verified' => false],
                'voice' => ['implemented' => $this->frontendFeatures()['voice'], 'executable_now' => null, 'available_to_agent' => false, 'requirement' => 'Browser speech support and microphone permission'],
                'notifications' => ['implemented' => class_exists(EmailNotificationService::class), 'available_to_agent' => false, 'configured' => $email['imap']],
                'source_inspection' => ['implemented' => true, 'read_only' => true, 'allowed_roots' => SourceCodeInspector::ROOTS],
            ],
            'capability_demonstrations' => $settings->capability_demonstrations ?? 'ask_first',
            'note' => 'Configured means local configuration is present, not that a remote operation has succeeded. Registered-tool availability is separate from per-request execution permission.',
        ]);
    }

    public function explain(array $capabilities): string
    {
        $lines = ['My registered tools are '.implode(', ', array_column($capabilities['tools'], 'name')).'.'];
        foreach ($capabilities['tools'] as $tool) {
            $lines[] = $tool['name'].': '.$tool['status'].'. Operations: '.implode(', ', $tool['operations']).'. Risk: '.$tool['risk'].'.';
        }
        $lines[] = 'Workflows have a builder and execution engine. Registered live app executors: '.implode(', ', array_keys($capabilities['application']['workflow_executors'])).'. Other UI-listed integrations are not proof of executable connectors. I can report workflow Agent status, but no tool for creating workflows or making arbitrary API calls is registered with me.';
        foreach ($capabilities['saved_connections'] as $connection) {
            $lines[] = $connection['available_to_agent']
                ? $connection['name'].': connected through Operations Agent via AskOperationsAgent. '.($connection['reachability_verified'] ? 'A read request succeeded recently; only the requested endpoint and returned fields are verified.' : 'Configured for read-only requests; authentication and reachability remain unverified until a request succeeds.')
                : $connection['name'].': '.($connection['configured'] ? 'configured' : 'not configured').' for generic Custom API workflows. Authentication, reachability and available remote endpoints are unverified. I have no direct operational tool for this connection.';
        }
        $unimplemented = array_column(array_filter($capabilities['integrations'], fn (array $integration): bool => ! $integration['implemented']), 'name');
        $lines[] = 'UI-listed integrations without registered executors: '.implode(', ', $unimplemented).'.';
        $lines[] = 'Source inspection is read-only: I can locate and explain implementation, but cannot modify files or run shell commands. Browser voice controls require browser support and microphone permission. Email notifications require a configured mailbox.';

        return implode("\n\n", $lines);
    }

    private function emailConfiguration(): array
    {
        $account = EmailAccount::query()->where('is_active', true)->where('is_default', true)
            ->select(['email', 'imap_host', 'imap_port', 'imap_username', 'smtp_host', 'smtp_port', 'smtp_username'])
            ->selectRaw("CASE WHEN imap_password IS NOT NULL AND imap_password <> '' THEN 1 ELSE 0 END AS has_imap_credential")
            ->selectRaw("CASE WHEN smtp_password IS NOT NULL AND smtp_password <> '' THEN 1 ELSE 0 END AS has_smtp_credential")->first();
        $addressValid = $account && filter_var($account->email, FILTER_VALIDATE_EMAIL)
            && ! in_array(strtolower($account->email), ['you@yourdomain.com', 'your@email.com', 'you@example.com'], true);

        return ['imap' => (bool) ($addressValid && $account->imap_host && $account->imap_username && $account->imap_port > 0 && $account->getAttribute('has_imap_credential')),
            'smtp' => (bool) ($addressValid && $account->smtp_host && $account->smtp_username && $account->smtp_port > 0 && $account->getAttribute('has_smtp_credential'))];
    }

    private function uiIntegrations(): array
    {
        $path = resource_path('js/jarvis-workflows.js');
        $fingerprint = hash_file('sha256', $path);

        return Cache::remember('jarvis:capabilities:ui:'.$fingerprint, 3600, function () use ($path): array {
            $source = file_get_contents($path);
            $start = strpos($source, 'const APPS = {');
            $end = strpos($source, "\n    };", $start);
            $source = substr($source, $start, $end - $start);
            preg_match_all('/^ {8}([a-z][a-z0-9_]*): \{[^{}]*?label: [\x27"]([^\x27"]+)[\x27"]/m', $source, $matches, PREG_SET_ORDER);
            $apps = [];
            foreach ($matches as $match) {
                $apps[$match[1]] = $this->redactor->text($match[2]);
            }

            return $apps;
        });
    }

    private function frontendFeatures(): array
    {
        $path = resource_path('views/jarvis/dashboard.blade.php');
        $fingerprint = hash_file('sha256', $path);

        return Cache::remember('jarvis:capabilities:frontend:'.$fingerprint, 3600, function () use ($path): array {
            $source = file_get_contents($path);

            return ['voice' => str_contains($source, 'SpeechRecognition') && str_contains($source, 'speechSynthesis')];
        });
    }

    private function sanitize(mixed $value): mixed
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->sanitize($item);
            }
        } elseif (is_string($value)) {
            $value = $this->redactor->text($value);
        }

        return $value;
    }
}
