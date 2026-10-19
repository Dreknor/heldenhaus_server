<?php

namespace App\Domain\Updater;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Releases aus GitHub. Erwartete Assets je Release:
 * heldenhaus-<version>.zip, manifest.json, manifest.sig
 */
class GithubReleaseSource implements ReleaseSource
{
    public function __construct(
        private readonly ?string $repository,
        private readonly ?string $token,
    ) {}

    public function latest(string $channel): ?ReleaseInfo
    {
        $releases = collect($this->releases())
            ->reject(fn (array $r) => $r['draft'] ?? false)
            ->filter(fn (array $r) => $channel === 'beta' || ! ($r['prerelease'] ?? false))
            ->map(fn (array $r) => $this->toInfo($r))
            ->filter()
            ->sort(fn (ReleaseInfo $a, ReleaseInfo $b) => version_compare(Version::normalize($b->version), Version::normalize($a->version)));

        return $releases->first();
    }

    public function find(string $version): ?ReleaseInfo
    {
        return collect($this->releases())
            ->map(fn (array $r) => $this->toInfo($r))
            ->filter()
            ->first(fn (ReleaseInfo $r) => Version::normalize($r->version) === Version::normalize($version));
    }

    public function fetch(string $url): string
    {
        return $this->client()->accept('application/octet-stream')->get($url)->throw()->body();
    }

    public function download(string $url, string $targetPath): void
    {
        $this->client()->accept('application/octet-stream')->timeout(300)->sink($targetPath)->get($url)->throw();
    }

    private function releases(): array
    {
        if (! $this->repository) {
            throw new UpdaterException('Keine Release-Quelle konfiguriert (UPDATER_GITHUB_REPOSITORY).');
        }

        return $this->client()
            ->get("https://api.github.com/repos/{$this->repository}/releases", ['per_page' => 20])
            ->throw()
            ->json();
    }

    private function toInfo(array $release): ?ReleaseInfo
    {
        $assets = collect($release['assets'] ?? [])->keyBy('name');
        $version = Version::normalize($release['tag_name'] ?? '');
        $package = $assets->first(fn ($a) => str_starts_with($a['name'], 'heldenhaus-') && str_ends_with($a['name'], '.zip'));

        if (! $version || ! $package || ! $assets->has('manifest.json') || ! $assets->has('manifest.sig')) {
            return null;
        }

        // Für private Repos die API-URL (mit Token) statt der Browser-URL nutzen
        $url = fn (array $asset) => $this->token ? $asset['url'] : $asset['browser_download_url'];

        return new ReleaseInfo(
            version: $version,
            channel: ($release['prerelease'] ?? false) ? 'beta' : 'stable',
            packageUrl: $url($package),
            manifestUrl: $url($assets['manifest.json']),
            signatureUrl: $url($assets['manifest.sig']),
            changelog: $release['body'] ?? null,
            publishedAt: $release['published_at'] ?? null,
        );
    }

    private function client(): PendingRequest
    {
        return Http::withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
            ->when($this->token, fn (PendingRequest $http) => $http->withToken($this->token))
            ->timeout(30);
    }
}
