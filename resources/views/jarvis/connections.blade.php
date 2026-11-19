<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>JARVIS Settings · Connections</title>
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-slate-950 text-slate-100">
<main class="mx-auto max-w-2xl space-y-6 px-6 py-12">
    <a href="{{ url('/jarvis') }}" class="text-sm text-cyan-300">Back to JARVIS</a>
    <h1 class="text-2xl font-semibold">Settings / Connections</h1>
    <p class="text-sm text-slate-400">Save API authentication here, then select the connection in a Custom API workflow step.</p>
    @if (session('status')) <p role="status" class="text-emerald-300">{{ session('status') }}</p> @endif
    @if ($errors->any())
        <ul role="alert" class="text-red-300">
            @foreach ($errors->all() as $error) <li>{{ $error }}</li> @endforeach
        </ul>
    @endif
    <div class="space-y-3">
        @forelse ($connections as $connection)
            <div class="flex items-center justify-between gap-4 rounded-xl border border-slate-800 p-4">
                <div>
                    <h2 class="font-medium">{{ $connection->name }}</h2>
                    <p class="break-all text-sm text-slate-400">{{ $connection->base_url }}</p>
                    <p class="text-xs text-slate-500">{{ $connection->authentication === 'none' ? 'No authentication' : 'Authentication saved securely · '.$connection->authentication }}</p>
                </div>
                <form method="POST" action="{{ route('jarvis.connections.test-saved', $connection->id) }}" data-connection-test class="space-y-2">
                    @csrf
                    <label class="block text-xs text-slate-400">Test path
                        <input name="test_path" value="/api/jarvis/test" required class="mt-1 w-full rounded-lg border border-slate-700 bg-slate-900 p-2">
                    </label>
                    <button type="button" data-test-connection class="text-sm text-cyan-300">Test connection</button>
                    <p data-test-result role="status" aria-live="polite" class="text-sm"></p>
                </form>
                <form method="POST" action="{{ route('jarvis.connections.destroy', $connection->id) }}">
                    @csrf @method('DELETE')
                    <button class="text-sm text-red-300">Remove</button>
                </form>
            </div>
            <details class="rounded-xl border border-slate-800 p-4">
                <summary class="cursor-pointer text-sm text-cyan-300">Edit {{ $connection->name }}</summary>
                <form method="POST" action="{{ route('jarvis.connections.update', $connection->id) }}" class="mt-4 space-y-3" autocomplete="off">
                    @csrf @method('PUT')
                    <label class="block">Name <input name="name" required maxlength="120" value="{{ $connection->name }}" class="mt-1 w-full rounded-lg border border-slate-700 bg-slate-900 p-3"></label>
                    <label class="block">Base URL <input name="base_url" type="url" required value="{{ $connection->base_url }}" class="mt-1 w-full rounded-lg border border-slate-700 bg-slate-900 p-3"></label>
                    <label class="block">Authentication
                        <select name="authentication" class="mt-1 w-full rounded-lg border border-slate-700 bg-slate-900 p-3">
                            <option value="bearer" @selected($connection->authentication === 'bearer')>Bearer Token</option>
                            <option value="none" @selected($connection->authentication === 'none')>No authentication</option>
                            <option value="metabase_api_key" @selected($connection->authentication === 'metabase_api_key')>Metabase API key</option>
                            <option value="metabase_session" @selected($connection->authentication === 'metabase_session')>Metabase session token</option>
                        </select>
                    </label>
                    <label class="block">Replace token (leave blank to keep saved token)
                        <input name="token" type="password" autocomplete="new-password" maxlength="4096" class="mt-1 w-full rounded-lg border border-slate-700 bg-slate-900 p-3">
                    </label>
                    <button class="rounded-lg bg-cyan-300 px-4 py-2 font-medium text-slate-950">Update connection</button>
                </form>
            </details>
        @empty <p class="text-sm text-slate-400">No saved API connections yet.</p> @endforelse
    </div>
    <form method="POST" action="{{ route('jarvis.connections.store') }}" data-connection-test data-test-url="{{ route('jarvis.connections.test') }}" class="space-y-4 rounded-xl border border-slate-800 p-6" autocomplete="off">
        @csrf
        <h2 class="text-lg font-medium">Add saved connection</h2>
        <label class="block">Name
            <input name="name" required maxlength="120" value="{{ old('name') }}" placeholder="MMC Tracker" class="mt-1 w-full rounded-lg border border-slate-700 bg-slate-900 p-3">
        </label>
        <label class="block">Base URL
            <input name="base_url" type="url" required value="{{ old('base_url') }}" placeholder="https://api.example.com" class="mt-1 w-full rounded-lg border border-slate-700 bg-slate-900 p-3">
        </label>
        <label class="block">Authentication
            <select name="authentication" class="mt-1 w-full rounded-lg border border-slate-700 bg-slate-900 p-3">
                <option value="bearer">Bearer Token</option><option value="none">No authentication</option>
                <option value="metabase_api_key">Metabase API key</option><option value="metabase_session">Metabase session token</option>
            </select>
        </label>
        <label class="block">Token
            <input name="token" type="password" autocomplete="new-password" maxlength="4096" class="mt-1 w-full rounded-lg border border-slate-700 bg-slate-900 p-3">
        </label>
        <p class="text-xs text-slate-400">Stored encrypted. Saved tokens are never sent back to the browser.</p>
        <label class="block">Test path
            <input name="test_path" value="/api/jarvis/test" required maxlength="1024" class="mt-1 w-full rounded-lg border border-slate-700 bg-slate-900 p-3">
            <span class="text-xs text-slate-400">MMC Tracker uses /api/jarvis/test. Change this for other APIs. Testing does not save the connection.</span>
        </label>
        <button type="button" data-test-connection class="rounded-lg border border-cyan-300 px-4 py-2 text-cyan-300">Test connection</button>
        <button class="rounded-lg bg-cyan-300 px-4 py-2 font-medium text-slate-950">Save connection</button>
        <p data-test-result role="status" aria-live="polite" class="text-sm"></p>
    </form>
</main>
<script>
    document.querySelectorAll('[data-connection-test]').forEach((form) => {
        const button = form.querySelector('[data-test-connection]');
        const result = form.querySelector('[data-test-result]');
        if (!form.dataset.testUrl) {
            form.addEventListener('submit', (event) => {
                event.preventDefault();
                if (!button.disabled) button.click();
            });
        }
        button.addEventListener('click', async (event) => {
            event.preventDefault();
            if (!form.reportValidity()) return;
            button.disabled = true;
            button.textContent = 'Testing...';
            result.textContent = '';
            try {
                const response = await fetch(form.dataset.testUrl || form.action, {
                    method: 'POST',
                    credentials: 'same-origin',
                    redirect: 'error',
                    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': form.querySelector('[name="_token"]').value },
                    body: new FormData(form),
                });
                const data = await response.json();
                const success = response.ok && data.success === true;
                result.className = success ? 'text-sm text-emerald-300' : 'text-sm text-red-300';
                result.textContent = (success ? '\u2713 ' : '\u2715 ') + (data.message || 'Unable to test connection. Try again.');
            } catch {
                result.className = 'text-sm text-red-300';
                result.textContent = '\u2715 Unable to test connection. Refresh the page and try again.';
            } finally {
                button.disabled = false;
                button.textContent = 'Test connection';
            }
        });
    });
</script>
</body>
</html>
