<?php

namespace App\Domain\Updater;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use ZipArchive;

/**
 * Echte Release-Struktur auf dem Webhosting:
 *   <root>/releases/<version>, <root>/shared/{.env,storage}, <root>/current -> releases/<version>, <root>/backups
 */
class LocalDeploymentEnvironment implements DeploymentEnvironment
{
    private readonly string $root;

    public function __construct(?string $root, private readonly string $phpBinary)
    {
        $this->root = rtrim($root ?: dirname(base_path(), 2), '/\\');
    }

    public function isManaged(): bool
    {
        return is_dir($this->root.'/releases') && is_dir($this->root.'/shared') && is_link($this->root.'/current');
    }

    public function root(): string
    {
        return $this->root;
    }

    public function currentReleasePath(): ?string
    {
        $link = $this->root.'/current';

        return is_link($link) ? realpath($link) ?: null : null;
    }

    public function packagePath(string $version): string
    {
        File::ensureDirectoryExists($this->root.'/packages');

        return $this->root."/packages/heldenhaus-{$version}.zip";
    }

    public function extract(string $packagePath, string $version): string
    {
        $target = $this->root.'/releases/'.Version::normalize($version);

        if (is_dir($target)) {
            File::deleteDirectory($target);
        }

        $zip = new ZipArchive;
        if ($zip->open($packagePath) !== true) {
            throw new UpdaterException('Paket lässt sich nicht öffnen.');
        }

        // Schutz vor Pfaden außerhalb des Zielordners („Zip Slip“)
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (str_contains($name, '..') || str_starts_with($name, '/')) {
                $zip->close();
                throw new UpdaterException("Unzulässiger Pfad im Paket: {$name}");
            }
        }

        $zip->extractTo($target);
        $zip->close();

        // Gemeinsame Daten einbinden
        File::deleteDirectory($target.'/storage');
        $this->symlink($this->root.'/shared/storage', $target.'/storage');
        @unlink($target.'/.env');
        $this->symlink($this->root.'/shared/.env', $target.'/.env');

        return $target;
    }

    public function backupDatabase(string $label): string
    {
        File::ensureDirectoryExists($this->root.'/backups');
        $connection = DB::connection();
        $path = $this->root.'/backups/'.now()->format('Ymd-His')."-{$label}";

        if ($connection->getDriverName() === 'sqlite') {
            copy($connection->getDatabaseName(), $path.'.sqlite');

            return $path.'.sqlite';
        }

        // PHP-basierter Dump (mysqldump ist auf dem Webhosting nicht immer verfügbar)
        $handle = fopen($path.'.sql', 'w');
        fwrite($handle, "SET FOREIGN_KEY_CHECKS=0;\n");

        foreach ($connection->select('SHOW TABLES') as $row) {
            $table = array_values((array) $row)[0];
            $create = (array) $connection->selectOne("SHOW CREATE TABLE `{$table}`");
            fwrite($handle, "DROP TABLE IF EXISTS `{$table}`;\n".$create['Create Table'].";\n");

            $connection->table($table)->orderByRaw('1')->chunk(500, function ($rows) use ($handle, $table, $connection) {
                foreach ($rows as $r) {
                    $values = array_map(fn ($v) => $v === null ? 'NULL' : $connection->getPdo()->quote((string) $v), (array) $r);
                    fwrite($handle, "INSERT INTO `{$table}` VALUES (".implode(',', $values).");\n");
                }
            });
        }

        fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
        fclose($handle);

        return $path.'.sql';
    }

    public function restoreDatabase(string $backupPath): void
    {
        $connection = DB::connection();

        if (str_ends_with($backupPath, '.sqlite')) {
            copy($backupPath, $connection->getDatabaseName());

            return;
        }

        $connection->unprepared(file_get_contents($backupPath));
    }

    public function maintenance(bool $down): void
    {
        // storage/framework liegt in shared/ – der Zustand gilt für alle Releases
        Artisan::call($down ? 'down' : 'up', $down ? ['--render' => 'errors::503', '--retry' => 60] : []);
    }

    public function artisan(string $releasePath, array $arguments): string
    {
        $process = new Process([$this->phpBinary, $releasePath.'/artisan', ...$arguments, '--no-interaction'], $releasePath);
        $process->setTimeout(240);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new UpdaterException('artisan '.implode(' ', $arguments).' fehlgeschlagen: '.trim($process->getErrorOutput() ?: $process->getOutput()));
        }

        return trim($process->getOutput());
    }

    public function switchTo(string $releasePath): void
    {
        $link = $this->root.'/current';
        $tmp = $link.'-'.bin2hex(random_bytes(4));

        $this->symlink($releasePath, $tmp);

        // rename() ersetzt den Symlink atomar
        if (! @rename($tmp, $link)) {
            @unlink($tmp);
            throw new UpdaterException('Umschalten auf das neue Release fehlgeschlagen.');
        }
    }

    public function prune(int $keep): void
    {
        $current = $this->currentReleasePath();

        foreach (array_slice($this->releases(), $keep) as $release) {
            if ($release !== $current) {
                File::deleteDirectory($release);
            }
        }
    }

    public function releases(): array
    {
        $dirs = glob($this->root.'/releases/*', GLOB_ONLYDIR) ?: [];
        usort($dirs, fn ($a, $b) => version_compare(basename($b), basename($a)));

        return array_map(fn ($d) => realpath($d) ?: $d, $dirs);
    }

    private function symlink(string $target, string $link): void
    {
        if (! @symlink($target, $link)) {
            throw new UpdaterException("Symlink konnte nicht angelegt werden: {$link}");
        }
    }
}
