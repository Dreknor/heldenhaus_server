<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AppRelease;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** App-Updater: Version prüfen, APK laden, neue APK hochladen (Admin/CI). */
class AppReleaseController extends Controller
{
    public function version(Request $request): JsonResponse
    {
        $data = $request->validate(['channel' => ['nullable', 'in:stable,beta'], 'build' => ['nullable', 'integer']]);
        $latest = AppRelease::latestFor($data['channel'] ?? 'stable');
        $min = AppRelease::minSupportedCode();

        return response()->json([
            'latest' => $latest ? [
                'version_name' => $latest->version_name,
                'version_code' => $latest->version_code,
                'channel' => $latest->channel,
                'changelog' => $latest->changelog,
                'size' => $latest->size,
                'sha256' => $latest->sha256,
                'download_url' => URL::temporarySignedRoute('api.v1.app.download', now()->addMinutes(30), ['release' => $latest->id]),
            ] : null,
            'min_supported' => $min,
            'update_available' => $latest && isset($data['build']) && $latest->version_code > (int) $data['build'],
            'update_required' => isset($data['build']) && (int) $data['build'] < $min,
        ]);
    }

    public function download(AppRelease $release): StreamedResponse
    {
        return Storage::disk('local')->download($release->apk_path, "heldenhaus-{$release->version_name}.apk", [
            'Content-Type' => 'application/vnd.android.package-archive',
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->is_admin, 403);

        $data = $request->validate([
            'apk' => ['required', 'file', 'max:204800'],
            'version_name' => ['required', 'string', 'max:40'],
            'version_code' => ['required', 'integer', 'min:1', 'unique:app_releases,version_code'],
            'channel' => ['required', 'in:stable,beta'],
            'changelog' => ['nullable', 'string', 'max:5000'],
            'min_supported_code' => ['nullable', 'integer', 'min:1'],
            'publish' => ['boolean'],
        ]);

        $file = $data['apk'];
        $path = $file->storeAs('app-releases', "heldenhaus-{$data['version_code']}.apk", 'local');

        $release = AppRelease::create([
            'version_name' => $data['version_name'],
            'version_code' => $data['version_code'],
            'channel' => $data['channel'],
            'apk_path' => $path,
            'sha256' => hash_file('sha256', $file->getRealPath()),
            'size' => $file->getSize(),
            'changelog' => $data['changelog'] ?? null,
            'min_supported_code' => $data['min_supported_code'] ?? 1,
            'published_at' => ($data['publish'] ?? true) ? now() : null,
        ]);

        Cache::forget('app:min_supported');

        return response()->json($release, 201);
    }
}
