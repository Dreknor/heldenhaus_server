<?php

// Online-Updater – siehe docs/konzept/10-online-updater.md
//
// Aktualisiert die Installation aus dem Git-Repository. Ein über die
// Oberfläche angefordertes Update führt der Scheduler aus (Cron:
// "php artisan schedule:run") – also als CLI-Prozess mit dem Benutzer, dem
// die Dateien gehören. Alternativ direkt: "php artisan updater:run".
return [
    // Online-Updater im Admin-Bereich anbieten
    'enabled' => (bool) env('UPDATER_ENABLED', true),

    // Git-Remote und Branch, von dem aktualisiert wird.
    // Leer = Upstream des ausgecheckten Branches (bzw. gleichnamiger Branch).
    'remote' => env('UPDATER_REMOTE', 'origin'),
    'branch' => env('UPDATER_BRANCH'),

    // Ausführbare Programme (leer = automatisch suchen). Auf dem Webhosting gibt
    // es oft mehrere PHP-Versionen, z. B. UPDATER_PHP_BINARY=php83.
    'binaries' => [
        'php' => env('UPDATER_PHP_BINARY'),
        'git' => env('UPDATER_GIT_BINARY', 'git'),
        'composer' => env('UPDATER_COMPOSER_BINARY', 'composer'),
        'npm' => env('UPDATER_NPM_BINARY', 'npm'),
        'mysqldump' => env('UPDATER_MYSQLDUMP_BINARY', 'mysqldump'),
    ],

    // Maximale Laufzeit eines einzelnen Schritts in Sekunden
    'step_timeout' => (int) env('UPDATER_STEP_TIMEOUT', 900),

    // composer/npm nur ausführen, wenn sich die zugehörigen Dateien geändert haben
    'smart_steps' => (bool) env('UPDATER_SMART_STEPS', true),

    'composer_args' => ['install', '--no-dev', '--no-interaction', '--prefer-dist', '--optimize-autoloader'],

    // Frontend-Assets bauen (public/build ist nicht versioniert)
    'build_assets' => (bool) env('UPDATER_BUILD_ASSETS', true),

    // Datenbanksicherung vor dem Update
    'backup' => [
        'enabled' => (bool) env('UPDATER_BACKUP', true),
        // Update abbrechen, wenn die Sicherung nicht erstellt werden kann
        'required' => (bool) env('UPDATER_BACKUP_REQUIRED', true),
        // Anzahl aufzubewahrender Sicherungen
        'keep' => (int) env('UPDATER_BACKUP_KEEP', 5),
    ],

    // Bei Fehlern im Wartungsmodus bleiben, damit kein halb migrierter Stand online geht
    'stay_down_on_failure' => (bool) env('UPDATER_STAY_DOWN_ON_FAILURE', true),

    // Zusätzliche Artisan-Befehle nach den Migrationen (idempotent!)
    'post_update_commands' => [
        ['game:content-sync'],
    ],

    // Automatische Suche nach Updates per Scheduler (Cron-Ausdruck, leer = aus)
    'check_cron' => env('UPDATER_CHECK_CRON', '17 */6 * * *'),

    // Ablageort für Status, Protokolle und Sicherungen
    'storage_path' => storage_path('app/updater'),
];
