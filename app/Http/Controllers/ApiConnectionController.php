<?php

namespace App\Http\Controllers;

use App\Models\ApiConnection;
use App\Services\WorkflowHttpService;
use App\Services\WorkflowOwner;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;

class ApiConnectionController extends Controller
{
    public function testConnection(Request $request, WorkflowOwner $owner, WorkflowHttpService $http, ?int $id = null): JsonResponse
    {
        $connection = $id === null ? null : ApiConnection::query()
            ->where('owner_key', $owner->key($request))->findOrFail($id);

        try {
            $data = $connection ? $connection->only(['base_url', 'authentication', 'token']) : $this->attributes($request, $http);
            $path = $request->validate([
                'test_path' => ['nullable', 'string', 'max:1024', 'regex:~^/(?!/)[A-Za-z0-9/_\-.]*$~D'],
            ])['test_path'] ?? '/api/jarvis/test';
            $url = rtrim($data['base_url'], '/').$path;
            $options = $http->requestOptions($url);
        } catch (ValidationException|RuntimeException) {
            return response()->json(['success' => false, 'status' => 422, 'message' => 'Invalid connection configuration. Use a public HTTPS base URL, a relative test path, and a Bearer token when required.'], 422);
        }

        try {
            $client = Http::acceptJson()->connectTimeout(5)->timeout(12)->withOptions($options);
            $client->withHeaders((new ApiConnection($data))->authenticationHeaders());
            $response = $client->get($url);
        } catch (ConnectionException) {
            return response()->json(['success' => false, 'status' => null, 'message' => 'Remote server did not respond (timeout or connection failure).'], 502);
        }

        $success = $response->successful() && $response->json('success') !== false;
        $message = match (true) {
            $success => 'Connection successful.',
            $response->status() === 401 => 'Authentication failed. Check the Bearer token.',
            $response->status() === 403 => 'Connected but permission denied.',
            $response->status() === 404 => 'Test endpoint not found.',
            $response->status() === 422 => 'Invalid connection configuration.',
            $response->status() === 429 => 'Remote API rate limited. Try again later.',
            $response->serverError() => 'Remote API error.',
            $response->redirect() => 'Remote API redirected the request. Redirects are blocked.',
            default => 'The remote API reported an unsuccessful connection test.',
        };
        if ($success && $response->json('message') === 'JARVIS connected to MMC Tracker'
            && (! $data['token'] || ! str_contains('JARVIS connected to MMC Tracker', $data['token']))) {
            $message = 'JARVIS connected to MMC Tracker';
        }

        return response()->json(['success' => $success, 'status' => $response->status(), 'message' => $message],
            $success ? 200 : ($response->failed() ? $response->status() : 502));
    }

    public function index(Request $request, WorkflowOwner $owner): JsonResponse|View
    {
        $connections = ApiConnection::query()->where('owner_key', $owner->key($request))->orderBy('name')->get();

        return $request->expectsJson()
            ? response()->json(['connections' => $connections])
            : view('jarvis.connections', compact('connections'));
    }

    public function store(Request $request, WorkflowOwner $owner, WorkflowHttpService $http): JsonResponse|RedirectResponse
    {
        $data = $this->attributes($request, $http);
        $connection = ApiConnection::query()->create([...$data, 'owner_key' => $owner->key($request)]);

        return $request->expectsJson()
            ? response()->json(['success' => true, 'connection' => $connection], 201)
            : redirect()->route('jarvis.connections.index')->with('status', 'Connection saved.');
    }

    public function update(Request $request, int $id, WorkflowOwner $owner, WorkflowHttpService $http): JsonResponse|RedirectResponse
    {
        $connection = ApiConnection::query()->where('owner_key', $owner->key($request))->findOrFail($id);
        $connection->update($this->attributes($request, $http, $connection));

        return $request->expectsJson()
            ? response()->json(['success' => true, 'connection' => $connection])
            : redirect()->route('jarvis.connections.index')->with('status', 'Connection updated.');
    }

    private function attributes(Request $request, WorkflowHttpService $http, ?ApiConnection $connection = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'base_url' => ['required', 'string', 'max:2048'],
            'authentication' => ['required', 'in:none,bearer,metabase_api_key,metabase_session'],
            'token' => ['nullable', 'string', 'max:4096', 'regex:/^[^\r\n]+$/D'],
        ]);
        try {
            $http->validateUrl($data['base_url']);
        } catch (RuntimeException) {
            throw ValidationException::withMessages(['base_url' => 'Use a public HTTPS base URL.']);
        }
        $parts = parse_url($data['base_url']);
        if (isset($parts['query']) || isset($parts['fragment'])) {
            throw ValidationException::withMessages(['base_url' => 'Base URLs cannot contain query strings or fragments.']);
        }
        $data['token'] = $data['authentication'] !== 'none' ? ($data['token'] ?? $connection?->token) : null;
        if ($data['authentication'] !== 'none' && ! $data['token']) {
            throw ValidationException::withMessages(['token' => 'Enter an authentication token for this connection.']);
        }

        return $data;
    }

    public function destroy(Request $request, int $id, WorkflowOwner $owner): JsonResponse|RedirectResponse
    {
        ApiConnection::query()->where('owner_key', $owner->key($request))->findOrFail($id)->delete();

        return $request->expectsJson()
            ? response()->json(['success' => true])
            : redirect()->route('jarvis.connections.index')->with('status', 'Connection removed.');
    }
}
