<?php

namespace App\Domain\Updater;

use App\Models\SystemUpdate;
use App\Models\User;
use Throwable;

/**
 * Zustandsautomat des Server-Updaters. Jeder Schritt ist kurz (eine HTTP-Anfrage
 * auf dem Webhosting) und kann nach einem Abbruch erneut ausgeführt werden.
 */
class UpdateRunner
{
    public const STEPS = ['download', 'verify', 'extract', 'backup', 'maintenance', 'migrate', 'switch', 'health', 'finish'];

    /** Ab diesem Schritt ist die Datenbank oder der Live-Code betroffen → Rollback nötig. */
    private const ROLLBACK_FROM = 'migrate';

    public function __construct(
        private readonly ReleaseSource $source,
        private readonly ManifestVerifier $verifier,
        private readonly DeploymentEnvironment $env,
    ) {}

    public function check(?string $channel = null): ?ReleaseInfo
    {
        $latest = $this->source->latest($channel ?? config('updater.channel'));

        return $latest && Version::isNewer($latest->version, config('updater.version')) ? $latest : null;
    }

    public function start(ReleaseInfo $release, ?User $user = null): SystemUpdate
    {
        if (! $this->env->isManaged()) {
            throw new UpdaterException('Diese Installation liegt nicht in der Release-Struktur – der Online-Updater ist hier nicht verfügbar.');
        }

        if (SystemUpdate::whereNotIn('status', ['done', 'failed', 'rolled_back'])->exists()) {
            throw new UpdaterException('Es läuft bereits ein Update.');
        }

        $update = SystemUpdate::create([
            'from_version' => config('updater.version'),
            'to_version' => $release->version,
            'channel' => $release->channel,
            'status' => 'running',
            'step' => self::STEPS[0],
            'manifest' => ['release' => $release->toArray()],
            'previous_release_path' => $this->env->currentReleasePath(),
            'started_by' => $user?->id,
        ]);
        $update->addLog("Update von {$update->from_version} auf {$release->version} gestartet.");
        $update->save();

        return $update;
    }

    /** Nächsten Schritt ausführen. */
    public function step(SystemUpdate $update): SystemUpdate
    {
        if ($update->isFinished()) {
            return $update;
        }

        $step = $update->step;

        try {
            $this->{'run'.ucfirst($step)}($update);
            $update->addLog("Schritt „{$step}“ erledigt.");
            $next = $this->nextStep($step);
            $update->step = $next;
            if ($next === null) {
                $update->status = 'done';
                $update->finished_at = now();
            }
        } catch (Throwable $e) {
            $update->addLog("Fehler in „{$step}“: {$e->getMessage()}", 'error');
            $this->fail($update, $step);
        }

        $update->save();

        return $update;
    }

    public function runToEnd(SystemUpdate $update): SystemUpdate
    {
        while (! $update->isFinished()) {
            $update = $this->step($update);
        }

        return $update;
    }

    /** Manuelles Zurückschalten auf ein aufbewahrtes Release (ohne DB-Wiederherstellung). */
    public function rollbackTo(string $releasePath): void
    {
        if (! in_array($releasePath, $this->env->releases(), true)) {
            throw new UpdaterException('Dieses Release ist nicht vorhanden.');
        }

        $this->env->switchTo($releasePath);
        $this->env->artisan($releasePath, ['optimize']);
    }

    private function runDownload(SystemUpdate $u): void
    {
        $release = $u->manifest['release'];
        $manifestJson = $this->source->fetch($release['manifestUrl']);
        $signature = $this->source->fetch($release['signatureUrl']);

        $u->manifest = [...$u->manifest, 'raw' => $manifestJson, 'signature' => $signature];
        $u->package_path = $this->env->packagePath($u->to_version);
        $this->source->download($release['packageUrl'], $u->package_path);
    }

    private function runVerify(SystemUpdate $u): void
    {
        $manifest = $this->verifier->verifyManifest($u->manifest['raw'], $u->manifest['signature']);

        if (Version::normalize($manifest['version']) !== Version::normalize($u->to_version)) {
            throw new UpdaterException('Manifest gehört zu einer anderen Version.');
        }

        $this->verifier->verifyPackage($u->package_path, $manifest['sha256']);

        if ($problems = $this->verifier->checkRequirements($manifest)) {
            throw new UpdaterException(implode(' ', $problems));
        }

        $u->manifest = [...$u->manifest, 'verified' => $manifest];
    }

    private function runExtract(SystemUpdate $u): void
    {
        $u->release_path = $this->env->extract($u->package_path, $u->to_version);
    }

    private function runBackup(SystemUpdate $u): void
    {
        $u->backup_path = $this->env->backupDatabase("vor-{$u->to_version}");
    }

    private function runMaintenance(SystemUpdate $u): void
    {
        $this->env->maintenance(true);
    }

    private function runMigrate(SystemUpdate $u): void
    {
        $this->env->artisan($u->release_path, ['migrate', '--force']);
        $this->env->artisan($u->release_path, ['optimize']);
    }

    private function runSwitch(SystemUpdate $u): void
    {
        $this->env->switchTo($u->release_path);
    }

    private function runHealth(SystemUpdate $u): void
    {
        $this->env->artisan($u->release_path, ['heldenhaus:smoke']);
    }

    private function runFinish(SystemUpdate $u): void
    {
        $this->env->maintenance(false);
        $this->env->prune(config('updater.keep_releases'));
        if ($u->package_path) {
            @unlink($u->package_path);
        }
    }

    private function fail(SystemUpdate $u, string $failedStep): void
    {
        $needsRollback = array_search($failedStep, self::STEPS, true) >= array_search(self::ROLLBACK_FROM, self::STEPS, true);

        try {
            if ($needsRollback) {
                if ($u->previous_release_path) {
                    $this->env->switchTo($u->previous_release_path);
                }
                if ($u->backup_path) {
                    $this->env->restoreDatabase($u->backup_path);
                }
                $u->addLog('Rollback auf '.$u->from_version.' durchgeführt.', 'warning');
            }
        } catch (Throwable $e) {
            $u->addLog('Rollback fehlgeschlagen: '.$e->getMessage().' – bitte manuell prüfen!', 'error');
        } finally {
            if (array_search($failedStep, self::STEPS, true) >= array_search('maintenance', self::STEPS, true)) {
                try {
                    $this->env->maintenance(false);
                } catch (Throwable) {
                    // Wartungsmodus kann manuell mit "php artisan up" beendet werden
                }
            }
        }

        $u->status = $needsRollback ? 'rolled_back' : 'failed';
        $u->finished_at = now();
    }

    private function nextStep(string $step): ?string
    {
        $index = array_search($step, self::STEPS, true);

        return self::STEPS[$index + 1] ?? null;
    }
}
