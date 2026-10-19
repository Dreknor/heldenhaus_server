<?php

namespace App\Domain\Updater;

/** Dateisystem- und Prozess-Operationen des Updaters (im Test durch eine Attrappe ersetzt). */
interface DeploymentEnvironment
{
    /** Liegt die Installation in der Release-Struktur (releases/, shared/, current)? */
    public function isManaged(): bool;

    public function root(): string;

    public function currentReleasePath(): ?string;

    public function packagePath(string $version): string;

    /** Paket nach releases/<version> entpacken und .env/storage aus shared/ einbinden. */
    public function extract(string $packagePath, string $version): string;

    /** @return string Pfad der Sicherung */
    public function backupDatabase(string $label): string;

    public function restoreDatabase(string $backupPath): void;

    public function maintenance(bool $down): void;

    /** Artisan im angegebenen Release ausführen; wirft bei Fehler. */
    public function artisan(string $releasePath, array $arguments): string;

    /** `current` atomar auf das Release zeigen lassen. */
    public function switchTo(string $releasePath): void;

    /** Alte Releases über dem Limit löschen. */
    public function prune(int $keep): void;

    /** @return list<string> Pfade der vorhandenen Releases, neueste zuerst */
    public function releases(): array;
}
