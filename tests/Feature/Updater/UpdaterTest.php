<?php

use App\Domain\Updater\DeploymentEnvironment;
use App\Domain\Updater\ManifestVerifier;
use App\Domain\Updater\ReleaseInfo;
use App\Domain\Updater\ReleaseSource;
use App\Domain\Updater\UpdateRunner;
use App\Domain\Updater\UpdaterException;
use App\Domain\Updater\Version;

/** Release-Quelle, die ein signiertes Testpaket liefert. */
class FakeReleaseSource implements ReleaseSource
{
    public string $manifest;

    public string $signature;

    public function __construct(public string $packageContent, string $secretKey, public string $version = '0.2.0', bool $tamper = false)
    {
        $this->manifest = json_encode([
            'version' => $version,
            'sha256' => hash('sha256', $packageContent),
            'min_php' => '8.2',
            'extensions' => ['json'],
            'has_migrations' => true,
        ]);
        $this->signature = ManifestVerifier::sign($this->manifest, $secretKey);

        if ($tamper) {
            $this->packageContent .= 'manipuliert';
        }
    }

    public function latest(string $channel): ?ReleaseInfo
    {
        return new ReleaseInfo($this->version, 'stable', 'pkg', 'manifest', 'sig');
    }

    public function find(string $version): ?ReleaseInfo
    {
        return $this->latest('stable');
    }

    public function fetch(string $url): string
    {
        return $url === 'manifest' ? $this->manifest : $this->signature;
    }

    public function download(string $url, string $targetPath): void
    {
        file_put_contents($targetPath, $this->packageContent);
    }
}

/** Zeichnet alle Operationen auf, statt das Dateisystem anzufassen. */
class FakeDeployment implements DeploymentEnvironment
{
    public array $calls = [];

    public ?string $current = '/srv/releases/0.1.0';

    public ?string $failOn = null;

    public function __construct(public bool $managed = true) {}

    public function isManaged(): bool
    {
        return $this->managed;
    }

    public function root(): string
    {
        return '/srv';
    }

    public function currentReleasePath(): ?string
    {
        return $this->current;
    }

    public function packagePath(string $version): string
    {
        return sys_get_temp_dir()."/heldenhaus-test-{$version}.zip";
    }

    public function extract(string $packagePath, string $version): string
    {
        $this->record('extract');

        return "/srv/releases/{$version}";
    }

    public function backupDatabase(string $label): string
    {
        $this->record('backup');

        return '/srv/backups/test.sql';
    }

    public function restoreDatabase(string $backupPath): void
    {
        $this->record("restore:{$backupPath}");
    }

    public function maintenance(bool $down): void
    {
        $this->record($down ? 'down' : 'up');
    }

    public function artisan(string $releasePath, array $arguments): string
    {
        $this->record('artisan:'.$arguments[0]);

        return 'OK';
    }

    public function switchTo(string $releasePath): void
    {
        $this->record("switch:{$releasePath}");
        $this->current = $releasePath;
    }

    public function prune(int $keep): void
    {
        $this->record('prune');
    }

    public function releases(): array
    {
        return ['/srv/releases/0.2.0', '/srv/releases/0.1.0'];
    }

    private function record(string $call): void
    {
        $this->calls[] = $call;

        if ($this->failOn && str_starts_with($call, $this->failOn)) {
            $this->failOn = null;
            throw new UpdaterException("Simulierter Fehler bei {$call}");
        }
    }
}

beforeEach(function () {
    $this->keys = ManifestVerifier::generateKeyPair();
    config(['updater.version' => '0.1.0', 'updater.public_key' => $this->keys['public']]);
    $this->env = new FakeDeployment;
});

function runner(ReleaseSource $source, DeploymentEnvironment $env, string $publicKey): UpdateRunner
{
    return new UpdateRunner($source, new ManifestVerifier($publicKey), $env);
}

it('vergleicht Versionen und erkennt Patch-Updates', function () {
    expect(Version::isNewer('v1.2.10', '1.2.9'))->toBeTrue()
        ->and(Version::isNewer('1.2.0', '1.2.0'))->toBeFalse()
        ->and(Version::isPatchUpgrade('1.2.5', '1.2.4'))->toBeTrue()
        ->and(Version::isPatchUpgrade('1.3.0', '1.2.4'))->toBeFalse();
});

it('akzeptiert nur korrekt signierte Manifeste', function () {
    $verifier = new ManifestVerifier($this->keys['public']);
    $manifest = json_encode(['version' => '1.0.0', 'sha256' => 'abc', 'min_php' => '8.2']);

    expect($verifier->verifyManifest($manifest, ManifestVerifier::sign($manifest, $this->keys['secret'])))->toHaveKey('version');

    $other = ManifestVerifier::generateKeyPair();
    expect(fn () => $verifier->verifyManifest($manifest, ManifestVerifier::sign($manifest, $other['secret'])))
        ->toThrow(UpdaterException::class, 'Signatur');
    expect(fn () => $verifier->verifyManifest(str_replace('1.0.0', '9.9.9', $manifest), ManifestVerifier::sign($manifest, $this->keys['secret'])))
        ->toThrow(UpdaterException::class);
});

it('installiert ein Update Schritt für Schritt', function () {
    $runner = runner(new FakeReleaseSource('ZIPDATA', $this->keys['secret']), $this->env, $this->keys['public']);

    $update = $runner->runToEnd($runner->start($runner->check()));

    expect($update->status)->toBe('done')
        ->and($this->env->calls)->toBe([
            'extract', 'backup', 'down', 'artisan:migrate', 'artisan:optimize',
            'switch:/srv/releases/0.2.0', 'artisan:heldenhaus:smoke', 'up', 'prune',
        ]);
});

it('bricht bei manipuliertem Paket ab, ohne etwas zu verändern', function () {
    $runner = runner(new FakeReleaseSource('ZIPDATA', $this->keys['secret'], tamper: true), $this->env, $this->keys['public']);

    $update = $runner->runToEnd($runner->start($runner->check()));

    expect($update->status)->toBe('failed')
        ->and($this->env->calls)->toBe([])
        ->and(collect($update->log)->last()['message'])->toContain('Prüfsumme');
});

it('rollt bei Fehler in der Migration zurück', function () {
    $this->env->failOn = 'artisan:migrate';
    $runner = runner(new FakeReleaseSource('ZIPDATA', $this->keys['secret']), $this->env, $this->keys['public']);

    $update = $runner->runToEnd($runner->start($runner->check()));

    expect($update->status)->toBe('rolled_back')
        ->and($this->env->calls)->toContain('switch:/srv/releases/0.1.0', 'restore:/srv/backups/test.sql')
        ->and(end($this->env->calls))->toBe('up')
        ->and($this->env->current)->toBe('/srv/releases/0.1.0');
});

it('rollt zurück, wenn die Gesundheitsprüfung scheitert', function () {
    $this->env->failOn = 'artisan:heldenhaus:smoke';
    $runner = runner(new FakeReleaseSource('ZIPDATA', $this->keys['secret']), $this->env, $this->keys['public']);

    $update = $runner->runToEnd($runner->start($runner->check()));

    expect($update->status)->toBe('rolled_back')->and($this->env->current)->toBe('/srv/releases/0.1.0');
});

it('läuft nur in der Release-Struktur und nur einmal gleichzeitig', function () {
    $source = new FakeReleaseSource('ZIPDATA', $this->keys['secret']);

    expect(fn () => runner($source, new FakeDeployment(managed: false), $this->keys['public'])->start($source->latest('stable')))
        ->toThrow(UpdaterException::class, 'Release-Struktur');

    $runner = runner($source, $this->env, $this->keys['public']);
    $runner->start($source->latest('stable'));
    expect(fn () => $runner->start($source->latest('stable')))->toThrow(UpdaterException::class, 'bereits');
});

it('meldet keine Updates, wenn die Version aktuell ist', function () {
    config(['updater.version' => '0.2.0']);
    $runner = runner(new FakeReleaseSource('ZIPDATA', $this->keys['secret']), $this->env, $this->keys['public']);

    expect($runner->check())->toBeNull();
});

it('lässt nur Admins an die Update-Seite', function () {
    [, $parent] = makeFamily();

    $this->actingAs($parent)->get('/admin/updates')->assertForbidden();

    $parent->forceFill(['is_admin' => true])->save();
    $this->get('/admin/updates')->assertOk();
});
