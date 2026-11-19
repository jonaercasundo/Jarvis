<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreJarvisVrmRequest;
use App\Models\JarvisSetting;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class JarvisVrmController extends Controller
{
    public function active(): JsonResponse
    {
        $path = JarvisSetting::current()->active_vrm;
        $disk = Storage::disk('public');

        if (! $this->isManagedVrmPath($path) || ! $disk->exists($path)) {
            return response()->json([
                'success' => true,
                'url' => null,
            ]);
        }

        return response()->json([
            'success' => true,
            'url' => '/storage/'.$path,
        ]);
    }

    public function store(StoreJarvisVrmRequest $request): JsonResponse
    {
        $file = $request->file('vrm');
        $filename = 'jarvis-'.now()->format('YmdHis').'-'.Str::uuid().'.vrm';
        $newPath = 'vrm/'.$filename;
        $disk = Storage::disk('public');
        $oldPath = null;

        Log::info('[VRM] Upload started', [
            'size_bytes' => $file->getSize(),
        ]);

        try {
            $lock = Cache::lock('jarvis:active-vrm:'.(auth()->id() ?? 'guest'), 900);
            $url = $lock->block(15, function () use ($file, $filename, $newPath, $disk, &$oldPath): string {
                $settings = JarvisSetting::current();

                DB::transaction(function () use ($settings, $file, $filename, $newPath, $disk, &$oldPath): void {
                    $lockedSettings = JarvisSetting::query()
                        ->whereKey($settings->getKey())
                        ->lockForUpdate()
                        ->firstOrFail();
                    $oldPath = $lockedSettings->active_vrm;

                    $storedPath = $file->storeAs('vrm', $filename, 'public');
                    if ($storedPath !== $newPath || ! $disk->exists($newPath)) {
                        throw new \RuntimeException('The VRM file could not be saved.');
                    }

                    Log::info('[VRM] New VRM saved', [
                        'size_bytes' => $file->getSize(),
                    ]);

                    $lockedSettings->forceFill(['active_vrm' => $newPath])->saveOrFail();
                });

                Log::info('[VRM] Active VRM updated');

                if (
                    $oldPath !== null
                    && $oldPath !== $newPath
                    && $this->isManagedVrmPath($oldPath)
                    && ! JarvisSetting::query()
                        ->where('active_vrm', $oldPath)
                        ->where('id', '!=', $settings->getKey())
                        ->exists()
                    && $disk->exists($oldPath)
                ) {
                    try {
                        if ($disk->delete($oldPath)) {
                            Log::info('[VRM] Previous VRM deleted');
                        } else {
                            Log::warning('[VRM] Previous VRM could not be deleted; cleanup is pending.');
                        }
                    } catch (Throwable $exception) {
                        Log::warning('[VRM] Previous VRM could not be deleted; cleanup is pending.', [
                            'exception' => $exception::class,
                        ]);
                    }
                }

                return '/storage/'.$newPath;
            });

            return response()->json([
                'success' => true,
                'url' => $url,
            ]);
        } catch (LockTimeoutException $exception) {
            $this->removeFailedUpload($disk, $newPath);
            Log::warning('[VRM] Upload could not acquire the replacement lock.');

            return response()->json([
                'success' => false,
                'message' => 'Another avatar change is in progress. Please try again.',
            ], 423);
        } catch (Throwable $exception) {
            $this->removeFailedUpload($disk, $newPath);
            Log::error('[VRM] Upload failed; the previous active VRM was kept.', [
                'exception' => $exception::class,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'VRM upload failed. The previous avatar was kept.',
            ], 500);
        }
    }

    private function removeFailedUpload(Filesystem $disk, string $path): void
    {
        try {
            if ($disk->exists($path)) {
                $disk->delete($path);
            }
        } catch (Throwable $exception) {
            Log::error('[VRM] Failed upload cleanup could not remove its new file.', [
                'exception' => $exception::class,
            ]);
        }
    }

    private function isManagedVrmPath(?string $path): bool
    {
        return $path !== null
            && preg_match('/\Avrm\/jarvis-\d{14}-[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}\.vrm\z/i', $path) === 1;
    }
}
