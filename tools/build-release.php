<?php

/**
 * Baut ein signiertes Release-Paket für den Online-Updater.
 *
 * Voraussetzung: composer install --no-dev und npm run build sind gelaufen.
 * Aufruf:  UPDATER_SECRET_KEY=... php tools/build-release.php 1.2.3 [ausgabeordner] [--migrations=auto|yes|no] [--previous=v1.2.2]
 * Ergebnis: heldenhaus-1.2.3.zip, manifest.json, manifest.sig
 */
$version = ltrim($argv[1] ?? '', 'v');
$out = rtrim($argv[2] ?? 'dist', '/');
$options = getopt('', ['migrations::', 'previous::']);
$secret = getenv('UPDATER_SECRET_KEY');

if (! preg_match('/^\d+\.\d+\.\d+(-[\w.]+)?$/', $version) || ! $secret) {
    fwrite(STDERR, "Aufruf: UPDATER_SECRET_KEY=... php tools/build-release.php <version> [ausgabeordner]\n");
    exit(1);
}

$root = dirname(__DIR__);
@mkdir($out, 0755, true);
file_put_contents($root.'/VERSION', $version."\n");

// Nicht ins Paket: lokale Daten, Entwicklungsdateien, Geheimnisse
$exclude = [
    '.env', '.env.example', '.git', '.github', '.claude', 'node_modules', 'tests', 'storage', 'dist', 'tools',
    'docs', 'database/database.sqlite', 'phpunit.xml', '.phpunit.cache', 'public/hot', 'public/storage',
];

$zipPath = "{$out}/heldenhaus-{$version}.zip";
@unlink($zipPath);
$zip = new ZipArchive;
$zip->open($zipPath, ZipArchive::CREATE);

$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));

    foreach ($exclude as $prefix) {
        if ($relative === $prefix || str_starts_with($relative, $prefix.'/')) {
            continue 2;
        }
    }

    $zip->addFile($file->getPathname(), $relative);
}
$zip->close();

// Enthält das Release neue Migrationen? (für Auto-Updates relevant)
$hasMigrations = match ($options['migrations'] ?? 'auto') {
    'yes' => true,
    'no' => false,
    default => isset($options['previous'])
        ? trim((string) shell_exec('git diff --name-only '.escapeshellarg($options['previous']).' HEAD -- database/migrations')) !== ''
        : true,
};

$manifest = json_encode([
    'version' => $version,
    'sha256' => hash_file('sha256', $zipPath),
    'size' => filesize($zipPath),
    'min_php' => '8.3',
    'extensions' => ['pdo_mysql', 'sodium', 'zip', 'mbstring', 'intl'],
    'has_migrations' => $hasMigrations,
    'min_app_build' => (int) (getenv('MIN_APP_BUILD') ?: 1),
    'built_at' => gmdate('c'),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

file_put_contents("{$out}/manifest.json", $manifest);
file_put_contents("{$out}/manifest.sig", base64_encode(sodium_crypto_sign_detached($manifest, base64_decode($secret))));

echo "Paket: {$zipPath}\n{$manifest}\n";
