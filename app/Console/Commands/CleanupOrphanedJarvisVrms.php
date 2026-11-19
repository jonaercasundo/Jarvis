<?php

namespace App\Console\Commands;

use App\Models\JarvisSetting;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

#[Signature('jarvis:vrm-cleanup {--dry-run : List orphaned VRMs without deleting them}')]
#[Description('Delete unreferenced VRM files managed by JARVIS')]
class CleanupOrphanedJarvisVrms extends Command
{
    public function handle(): int
    {
        $disk = Storage::disk('public');
        $activePaths = JarvisSetting::query()
            ->whereNotNull('active_vrm')
            ->pluck('active_vrm')
            ->all();
        $activePaths = array_fill_keys($activePaths, true);
        $deletedCount = 0;

        foreach ($disk->files('vrm') as $path) {
            if (! preg_match('/\Avrm\/jarvis-\d{14}-[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}\.vrm\z/i', $path)) {
                continue;
            }

            if (isset($activePaths[$path])) {
                continue;
            }

            if ($this->option('dry-run')) {
                $this->line("Would delete {$path}");

                continue;
            }

            try {
                if ($disk->delete($path)) {
                    $deletedCount++;
                    Log::info('[VRM] Orphaned VRM deleted');
                    $this->line("Deleted {$path}");
                }
            } catch (Throwable $exception) {
                Log::warning('[VRM] Orphaned VRM cleanup failed.', [
                    'exception' => $exception::class,
                ]);
                $this->warn("Could not delete {$path}");
            }
        }

        if (! $this->option('dry-run')) {
            $this->info("Deleted {$deletedCount} orphaned VRM file(s).");
        }

        return self::SUCCESS;
    }
}
