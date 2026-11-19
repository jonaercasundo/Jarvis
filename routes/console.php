<?php

use App\Services\WorkflowSchedulerService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
Schedule::command('jarvis:check-important-emails')->everyFiveMinutes()->withoutOverlapping();
Schedule::call(fn () => app(WorkflowSchedulerService::class)->runDue())
    ->name('jarvis-workflow-scheduler')
    ->everyMinute()
    ->withoutOverlapping();
