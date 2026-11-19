<?php

namespace App\Services;

use App\Models\ApiConnection;
use Illuminate\Support\Arr;
use RuntimeException;

class WorkflowAgentTools
{
    public function __construct(private WorkflowAppExecutorRegistry $registry, private SourceSecretRedactor $redactor) {}

    /** Server-owned action definitions. A model cannot change an action's method, origin or risk. */
    public function describe(array $node): array
    {
        $config = $node['config'] ?? [];
        $app = $config['app'] ?? '';
        $connection = $this->connection($node);
        $integration = $connection?->integration ?? $app;
        $actions = [];
        if ($app === 'custom_api' && $integration === 'mmc_tracker') {
            foreach (['projects' => 'Search projects', 'projects/delivery-progress' => 'Delivery progress', 'lots' => 'Lots', 'operation-masterlist' => 'Items', 'packages' => 'Packages', 'warehouses' => 'Warehouse information', 'inventory' => 'Inventory'] as $path => $label) {
                $actions[$path] = $this->action($label, 'GET', '/api/jarvis/'.$path, 'read', ['query' => 'object: supported filters (search, project_id, year, active_only, page, per_page)']);
            }
            $description = 'Project and delivery operations';
        } elseif ($app === 'custom_api' && $integration === 'metabase') {
            $actions = [
                'search' => $this->action('Search reports, questions and dashboards', 'GET', '/api/search', 'read', ['query' => 'object: q, models']),
                'dashboards' => $this->action('List dashboards', 'GET', '/api/dashboard', 'read'),
                'dashboard' => $this->action('Inspect dashboard cards', 'GET', '/api/dashboard/{dashboard_id}', 'read', ['dashboard_id' => 'positive integer']),
                'card' => $this->action('Inspect a saved question', 'GET', '/api/card/{card_id}', 'read', ['card_id' => 'positive integer']),
                'query_card' => $this->action('Query a saved report/question', 'POST', '/api/card/{card_id}/query', 'read', ['card_id' => 'positive integer', 'body' => 'object: parameters array']),
            ];
            $description = 'Analytics and business intelligence';
        } elseif ($app === 'outlook') {
            foreach (['read' => ['Search and read email', 'read'], 'draft' => ['Prepare an email draft locally for review', 'draft'], 'save_draft' => ['Save a draft in Outlook', 'draft'], 'send' => ['Send an email', 'send']] as $name => [$label, $permission]) {
                $actions[$name] = $this->action($label, '', '', $permission, $name === 'read' ? ['query' => 'search text'] : ['to' => 'email address', 'subject' => 'text', 'body' => 'text']);
                if ($name === 'save_draft') {
                    $actions[$name]['external_effect'] = true;
                }
            }
            $description = 'Email and communication';
        } else {
            $description = $node['label'] ?? $app;
        }
        $capabilities = $this->registry->capabilities()[$app] ?? null;
        foreach ($capabilities['action_definitions'] ?? [] as $name => $definition) {
            if (! in_array($name, $capabilities['actions'] ?? [], true)) {
                continue;
            }
            $permission = $definition['permission'] ?? (($definition['risk'] ?? $capabilities['risk'] ?? '') === 'READ_ONLY' ? 'read' : 'write');
            $actions[$name] = $this->action($definition['description'] ?? $name, '', '', $permission, $definition['input_schema'] ?? []);
        }
        if ($capabilities && in_array($config['action'] ?? '', $capabilities['actions'] ?? [], true)) {
            $values = $config['values'] ?? [];
            $method = $values['method'] ?? 'GET';
            $permission = $app === 'custom_api' ? ($method === 'GET' ? 'read' : ($method === 'DELETE' ? 'delete' : 'write')) : ($app === 'outlook' ? ($config['action'] ?? 'read') : (($capabilities['risk'] ?? '') === 'READ_ONLY' ? 'read' : 'write'));
            $path = parse_url((string) ($values['url'] ?? ''), PHP_URL_PATH) ?: '';
            if ($integration === 'outlook' && $app === 'custom_api' && $method === 'POST') {
                $permission = str_ends_with($path, '/sendMail') || str_ends_with($path, '/send') ? 'send' : (str_ends_with($path, '/messages') ? 'draft' : 'write');
            }
            if ($integration === 'metabase' && $method === 'POST' && preg_match('~^/api/(?:card/\d+/query|dashboard/\d+/dashcard/\d+/card/\d+/query)$~D', $path)) {
                $permission = 'read';
            }
            $actions['configured'] = $this->action($node['label'] ?? 'Configured action', $method, (string) ($values['url'] ?? ''), $permission,
                $app === 'custom_api' ? ['query' => 'object of query parameters', 'body' => 'JSON object'] : []);
            if (isset($actions[$config['action']])) {
                $actions['configured'] = $actions[$config['action']];
            }
            if ($app === 'outlook' && ($config['action'] ?? '') === 'save_draft') {
                $actions['configured']['permission'] = 'draft';
                $actions['configured']['external_effect'] = true;
            }
            if ($integration === 'outlook' && $method !== 'GET') {
                $actions['configured']['external_effect'] = true;
            }
        }
        if (! $capabilities) {
            $actions = [];
        }

        return $this->sanitize(['tool_id' => $node['id'], 'name' => $connection?->name ?? ($node['label'] ?? $app),
            'description' => $description, 'available' => $capabilities !== null, 'actions' => $actions], [$connection?->token]);
    }

    /** @return array<string, mixed> */
    public function prepare(array $node, string $action, array $arguments): array
    {
        $definition = $this->describe($node)['actions'][$action] ?? null;
        if (! $definition) {
            throw new RuntimeException('The requested action is not available on this connected tool.');
        }
        if (array_diff(array_keys($arguments), array_keys($definition['input_schema'])) !== []) {
            throw new RuntimeException('The tool arguments contain unsupported fields.');
        }
        if (strlen(json_encode($arguments, JSON_THROW_ON_ERROR)) > 20000) {
            throw new RuntimeException('Tool arguments are too large.');
        }
        foreach ($definition['input_schema'] as $key => $type) {
            if (array_key_exists($key, $arguments) && $type === 'positive integer' && (! is_int($arguments[$key]) || $arguments[$key] < 1)) {
                throw new RuntimeException('A verified positive record ID is required.');
            }
        }
        $node['config']['values'] ??= [];
        if (($node['config']['app'] ?? '') !== 'custom_api') {
            $node['config']['action'] = $action === 'configured' ? $node['config']['action'] : $action;
            $node['config']['values'] = [...$node['config']['values'], ...$arguments];

            return $node;
        }
        if ($action !== 'configured') {
            $path = $definition['path'];
            foreach ($definition['input_schema'] as $key => $type) {
                if ($type !== 'positive integer') {
                    continue;
                }
                if (! is_int($arguments[$key] ?? null) || $arguments[$key] < 1) {
                    throw new RuntimeException('A verified positive record ID is required.');
                }
                $path = str_replace('{'.$key.'}', (string) $arguments[$key], $path);
            }
            $node['config']['values'] = Arr::only($node['config']['values'], ['connection_id', 'connection']);
            $node['config']['values'] += ['url' => $path, 'method' => $definition['method'], 'response_paths' => []];
            $node['config']['action'] = 'request';
        }
        foreach (['query', 'body'] as $key) {
            if (isset($arguments[$key])) {
                if (! is_array($arguments[$key])) {
                    throw new RuntimeException('Tool query and body arguments must be objects.');
                }
                $node['config']['values'][$key] = $arguments[$key];
            }
        }
        $values = &$node['config']['values'];
        if ($action !== 'configured' && str_contains($values['url'], '/api/jarvis/')) {
            $values['query'] = [...($values['query'] ?? []), 'per_page' => min(50, max(1, (int) ($values['query']['per_page'] ?? 20))), 'page' => max(1, (int) ($values['query']['page'] ?? 1))];
            if ($action === 'projects/delivery-progress') {
                $values['query']['compact'] = 1;
            }
        }
        if ($action === 'query_card') {
            $values['body'] ??= ['parameters' => []];
            if (array_diff(array_keys($values['body']), ['parameters']) !== [] || ! is_array($values['body']['parameters'] ?? null)) {
                throw new RuntimeException('Saved report queries accept only a parameters array.');
            }
        }

        return $node;
    }

    public function permission(array $node, string $action): string
    {
        return $this->describe($node)['actions'][$action]['permission'] ?? 'unavailable';
    }

    public function changesRecords(array $node, string $action): bool
    {
        return $this->describe($node)['actions'][$action]['external_effect'] ?? true;
    }

    public function connectionRevision(ApiConnection $connection): string
    {
        return hash('sha256', json_encode([$connection->name, $connection->base_url, $connection->authentication, $connection->getRawOriginal('token')], JSON_THROW_ON_ERROR));
    }

    /** Strip secret fields and redact all owned connection credentials, including unlabeled echoes. */
    public function sanitize(mixed $value, array $secrets = []): mixed
    {
        if (is_array($value)) {
            $clean = [];
            foreach ($value as $key => $item) {
                $clean[$key] = is_string($key) && preg_match('/password|secret|token|authorization|cookie|api[_-]?key|credential/i', $key)
                    ? '[REDACTED]' : $this->sanitize($item, $secrets);
            }

            return $clean;
        }
        if (is_string($value)) {
            foreach (array_filter($secrets) as $secret) {
                $value = str_replace($secret, '[REDACTED]', $value);
            }

            return $this->redactor->text(mb_substr($value, 0, 30000));
        }

        return $value;
    }

    private function connection(array $node): ?ApiConnection
    {
        $values = $node['config']['values'] ?? [];

        return ApiConnection::query()->where('owner_key', $node['_owner_key'] ?? '')
            ->whereKey($values['connection_id'] ?? $values['connection'] ?? 0)->first();
    }

    private function action(string $description, string $method, string $path, string $permission, array $schema = []): array
    {
        return ['description' => $description, 'method' => $method, 'path' => $path, 'permission' => $permission, 'input_schema' => $schema,
            'external_effect' => in_array($permission, ['write', 'send', 'delete'], true)];
    }
}
