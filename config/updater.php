<?php

// Online-Updater – siehe docs/konzept/10-online-updater.md
return [
    // Installierte Version (wird beim Release-Build in die Datei VERSION geschrieben)
    'version' => trim((string) @file_get_contents(base_path('VERSION'))) ?: '0.0.0',

    'channel' => env('UPDATER_CHANNEL', 'stable'), // stable|beta

    // Quelle: GitHub-Releases. Für private Repos ein Token mit Lesezugriff auf Releases.
    'github' => [
        'repository' => env('UPDATER_GITHUB_REPOSITORY'), // z. B. "familie/heldenhaus"
        'token' => env('UPDATER_GITHUB_TOKEN'),
    ],

    // Öffentlicher Ed25519-Schlüssel (base64). Erzeugen mit: php artisan heldenhaus:updater-keygen
    'public_key' => env('UPDATER_PUBLIC_KEY'),

    // Wurzel der Release-Struktur (releases/, shared/, current, backups/).
    // Leer = automatisch: zwei Ebenen über dem laufenden Release.
    'deploy_root' => env('UPDATER_DEPLOY_ROOT'),

    // PHP-CLI für Artisan-Aufrufe im neuen Release (Web-PHP ist oft php-fpm/cgi)
    'php_binary' => env('UPDATER_PHP_BINARY', 'php'),

    'keep_releases' => 3,

    // Patch-Versionen ohne Migrationen nachts automatisch installieren
    'auto_patch' => (bool) env('UPDATER_AUTO_PATCH', false),
];
