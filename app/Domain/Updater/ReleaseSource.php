<?php

namespace App\Domain\Updater;

interface ReleaseSource
{
    /** Neueste Version im Kanal (stable enthält keine Vorabversionen). */
    public function latest(string $channel): ?ReleaseInfo;

    public function find(string $version): ?ReleaseInfo;

    /** Datei herunterladen und als String zurückgeben (Manifest, Signatur). */
    public function fetch(string $url): string;

    /** Große Datei direkt auf die Platte laden. */
    public function download(string $url, string $targetPath): void;
}
