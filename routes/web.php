
<?php

use App\Http\Controllers\ApiConnectionController;
use App\Http\Controllers\EmailAccountController;
use App\Http\Controllers\JarvisController;
use App\Http\Controllers\JarvisSettingsController;
use App\Http\Controllers\JarvisVrmController;
use App\Http\Controllers\JarvisWorkflowController;
use App\Models\JarvisSetting;
use App\Services\Email\EmailManager;
use App\Services\Email\EmailNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Home
|--------------------------------------------------------------------------
*/

Route::get('/', function () {
    return view('welcome');
});
Route::get('/jarvis/inbox', function () {
    return response()->json([
        'emails' => [],
    ]);
});
Route::get('/jarvis', function () {
    return view('jarvis.dashboard', [
        'emails' => [],
        'aiName' => trim(JarvisSetting::current()->ai_name ?: 'JC') ?: 'JC',
    ]);
});

Route::post('/jarvis/command', [JarvisController::class, 'command']);

/*
|--------------------------------------------------------------------------
| JARVIS EMAIL
|--------------------------------------------------------------------------
|
| Opens a specific email selected from the Jarvis email search results.
|
*/

Route::get('/jarvis/email/{uid}', [JarvisController::class, 'email']);

/*
|--------------------------------------------------------------------------
| JARVIS NOTIFICATION
|--------------------------------------------------------------------------
*/
// routes/web.php
Route::get('/jarvis/settings', [JarvisSettingsController::class, 'show']);
Route::get('/jarvis/settings/connections', [ApiConnectionController::class, 'index'])->name('jarvis.connections.index');
Route::post('/jarvis/settings/connections/test', [ApiConnectionController::class, 'testConnection'])->middleware('throttle:20,1')->name('jarvis.connections.test');
Route::post('/jarvis/settings/connections/{id}/test', [ApiConnectionController::class, 'testConnection'])->whereNumber('id')->middleware('throttle:20,1')->name('jarvis.connections.test-saved');
Route::post('/jarvis/settings/connections', [ApiConnectionController::class, 'store'])->middleware('throttle:20,1')->name('jarvis.connections.store');
Route::delete('/jarvis/settings/connections/{id}', [ApiConnectionController::class, 'destroy'])->whereNumber('id')->name('jarvis.connections.destroy');
Route::put('/jarvis/settings/connections/{id}', [ApiConnectionController::class, 'update'])->whereNumber('id')->middleware('throttle:20,1')->name('jarvis.connections.update');
Route::post('/jarvis/workflows/test-step', [JarvisWorkflowController::class, 'testStep'])->middleware('throttle:10,1')->name('jarvis.workflows.test-step');
Route::post('/jarvis/settings', [JarvisSettingsController::class, 'update']);
Route::get('/jarvis/vrm/active', [JarvisVrmController::class, 'active'])->name('jarvis.vrm.active');
Route::post('/jarvis/vrm', [JarvisVrmController::class, 'store'])
    ->middleware('throttle:5,1')
    ->name('jarvis.vrm.store');
Route::get('/jarvis/workflows', [JarvisWorkflowController::class, 'index']);
Route::get('/jarvis/workflows/data', [JarvisWorkflowController::class, 'data']);
Route::get('/jarvis/workflows/catalog', [JarvisWorkflowController::class, 'catalog']);
Route::get('/jarvis/agents/status', [JarvisWorkflowController::class, 'agentStatus'])->name('jarvis.agents.status');
Route::post('/jarvis/workflows/propose', [JarvisWorkflowController::class, 'propose']);
Route::post('/jarvis/workflows', [JarvisWorkflowController::class, 'store']);
Route::get('/jarvis/workflows/connections', [
    JarvisWorkflowController::class,
    'connections',
])->name('jarvis.workflows.connections');
Route::get('/jarvis/workflows/{id}', [JarvisWorkflowController::class, 'show'])
    ->where('id', '[0-9]+');
Route::post('/jarvis/workflows/{id}/use', [JarvisWorkflowController::class, 'useWorkflow'])
    ->whereNumber('id')
    ->name('jarvis.workflows.use');
Route::put('/jarvis/workflows/{id}', [JarvisWorkflowController::class, 'update'])->whereNumber('id');
Route::delete('/jarvis/workflows/{id}', [JarvisWorkflowController::class, 'destroy'])->whereNumber('id');
Route::post('/jarvis/workflows/{id}/test', [JarvisWorkflowController::class, 'test'])->whereNumber('id');
Route::post('/jarvis/workflows/{id}/activate', [JarvisWorkflowController::class, 'activate'])->whereNumber('id');
Route::post('/jarvis/workflows/{id}/pause', [JarvisWorkflowController::class, 'pause'])->whereNumber('id');
Route::post('/jarvis/workflows/{id}/rotate-webhook', [JarvisWorkflowController::class, 'rotateWebhook'])->whereNumber('id');
Route::get('/jarvis/workflows/{id}/executions', [JarvisWorkflowController::class, 'executions'])->whereNumber('id');
Route::post('/jarvis/workflows/{id}/executions/{execution}/approve', [JarvisWorkflowController::class, 'approveAgentAction'])->whereNumber(['id', 'execution'])->middleware('throttle:10,1');
Route::get('/jarvis/workflow/show', [JarvisWorkflowController::class, 'legacyShow']);
Route::get('/jarvis/notification', function (
    EmailManager $emailManager,
    EmailNotificationService $notificationService,
) {
    try {
        if (! $emailManager->isConfigured()) {
            return response()->json([
                'success' => true,
                'configured' => false,
                'count' => 0,
                'notifications' => [],
            ]);
        }

        $notifications = $notificationService->deliverPending();

        return response()->json([
            'success' => true,
            'configured' => true,
            'count' => count($notifications),
            'notifications' => $notifications,
        ]);

    } catch (Throwable $e) {
        Log::error('JARVIS notification endpoint failed', [
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ]);

        return response()->json([
            'success' => false,
            'configured' => true,
            'count' => 0,
            'notifications' => [],
            'error' => $e->getMessage(),
            'exception' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ], 500);
    }
});
Route::post('/jarvis/notification/ack', function (
    Request $request,
    EmailNotificationService $notificationService,
) {
    $validated = $request->validate([
        'tokens' => ['required', 'array', 'max:10'],
        'tokens.*' => ['required', 'uuid'],
    ]);

    return response()->json([
        'success' => true,
        'acknowledged' => $notificationService->acknowledgeDelivered($validated['tokens']),
    ]);
});
Route::get('/jarvis/email-accounts', [EmailAccountController::class, 'index']);
Route::post('/jarvis/email-accounts', [EmailAccountController::class, 'store']);
Route::post('/jarvis/email-accounts/test', [EmailAccountController::class, 'testConnection']);
Route::delete('/jarvis/email-accounts/{emailAccount}', [EmailAccountController::class, 'destroy']);
Route::get('/jarvis/credits', [JarvisController::class, 'credits']);

Route::get('/jarvis/workflows/{id}', [
    JarvisWorkflowController::class,
    'show',
])->name('jarvis.workflows.show');
