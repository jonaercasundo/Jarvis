<?php

use App\Http\Controllers\JarvisWorkflowWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/jarvis/workflows/{workflowId}/webhook/{token}', JarvisWorkflowWebhookController::class)
    ->whereNumber('workflowId')
    ->where('token', '[A-Za-z0-9]{48}')
    ->middleware('throttle:30,1');
