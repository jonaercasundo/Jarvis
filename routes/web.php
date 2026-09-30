
<?php

use App\Http\Controllers\EmailAccountController;
use App\Http\Controllers\JarvisController;
use App\Http\Controllers\JarvisSettingsController;
use App\Http\Controllers\JarvisWorkflowController;
use App\Services\GoDaddyEmailService;
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
Route::post('/jarvis/settings', [JarvisSettingsController::class, 'update']);
Route::get('/jarvis/workflows', [JarvisWorkflowController::class, 'index']);
Route::get('/jarvis/workflows/data', [JarvisWorkflowController::class, 'data']);
Route::get('/jarvis/workflows/catalog', [JarvisWorkflowController::class, 'catalog']);
Route::post('/jarvis/workflows/propose', [JarvisWorkflowController::class, 'propose']);
Route::post('/jarvis/workflows', [JarvisWorkflowController::class, 'store']);
Route::get('/jarvis/workflows/{id}', [JarvisWorkflowController::class, 'show'])->whereNumber('id');
Route::put('/jarvis/workflows/{id}', [JarvisWorkflowController::class, 'update'])->whereNumber('id');
Route::delete('/jarvis/workflows/{id}', [JarvisWorkflowController::class, 'destroy'])->whereNumber('id');
Route::post('/jarvis/workflows/{id}/test', [JarvisWorkflowController::class, 'test'])->whereNumber('id');
Route::post('/jarvis/workflows/{id}/activate', [JarvisWorkflowController::class, 'activate'])->whereNumber('id');
Route::post('/jarvis/workflows/{id}/pause', [JarvisWorkflowController::class, 'pause'])->whereNumber('id');
Route::post('/jarvis/workflows/{id}/rotate-webhook', [JarvisWorkflowController::class, 'rotateWebhook'])->whereNumber('id');
Route::get('/jarvis/workflows/{id}/executions', [JarvisWorkflowController::class, 'executions'])->whereNumber('id');
Route::get('/jarvis/workflow/show', [JarvisWorkflowController::class, 'legacyShow']);
Route::get('/jarvis/notification', function (
    GoDaddyEmailService $emailService
) {
    if (! $emailService->isConfigured()) {
        return response()->json([
            'success' => true,
            'configured' => false,
            'count' => 0,
            'emails' => [],
        ]);
    }

    try {
        $result = $emailService->unreadEmails(10);

        return response()->json([
            'success' => true,
            'configured' => true,
            'count' => $result['count'] ?? 0,
            'emails' => $result['emails'] ?? [],
        ]);

    } catch (Throwable $e) {
        return response()->json([
            'success' => false,
            'configured' => true,
            'count' => 0,
            'emails' => [],
            'error' => $e->getMessage(),
        ], 500);
    }
});
Route::get('/jarvis/email-accounts', [EmailAccountController::class, 'index']);
Route::post('/jarvis/email-accounts', [EmailAccountController::class, 'store']);
Route::delete('/jarvis/email-accounts/{emailAccount}', [EmailAccountController::class, 'destroy']);
Route::get('/jarvis/credits', [JarvisController::class, 'credits']);
