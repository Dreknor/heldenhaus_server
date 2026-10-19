<?php

use App\Domain\Family\FamilyService;
use App\Domain\Gameplay\Formulas;
use App\Domain\Gameplay\RunDayCron;
use App\Domain\Tasks\ReviewCompletion;
use App\Domain\Updater\ManifestVerifier;
use App\Domain\Updater\UpdateRunner;
use App\Domain\Updater\Version;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

/*
 * Erste Familie und Admin anlegen (öffentliche Registrierung ist aus).
 */
Artisan::command('heldenhaus:create-family {family} {name} {email}', function (FamilyService $families) {
    $password = $this->secret('Passwort für '.$this->argument('email'));

    $user = User::create([
        'name' => $this->argument('name'),
        'email' => $this->argument('email'),
        'password' => $password,
        'email_verified_at' => now(),
    ]);
    $user->forceFill(['is_admin' => true])->save();

    $family = $families->createFamily($user, $this->argument('family'));

    $this->info("Familie „{$family->name}“ angelegt. Familiencode für den Kinder-Login: {$family->login_code}");
})->purpose('Erste Familie mit Admin-Elternteil anlegen');

/*
 * Tageswechsel für alle, die die App heute noch nicht geöffnet haben
 * (der Dialog „Gestern noch erledigt?“ bekommt so einen halben Tag Zeit).
 */
Artisan::command('heldenhaus:day-cron', function (RunDayCron $cron) {
    $count = 0;

    User::with('stats', 'family')->whereHas('stats')->whereNotNull('family_id')->chunkById(200, function ($users) use ($cron, &$count) {
        foreach ($users as $user) {
            if (! RunDayCron::isPending($user)) {
                continue;
            }

            $hoursIntoDay = $user->gameDate()->diffInHours(
                CarbonImmutable::now($user->timezone)->subHours($user->day_start ?? 0)->setTimezone($user->timezone)
            );

            if ($hoursIntoDay >= config('game.batch_cron_after_hours')) {
                $cron($user);
                $count++;
            }
        }
    });

    $this->info("Tageswechsel für {$count} Personen ausgeführt.");
})->purpose('Ausstehende Tageswechsel nachholen');

Artisan::command('heldenhaus:auto-approve', function (ReviewCompletion $review) {
    $this->info($review->autoApproveExpired().' Freigaben automatisch bestätigt.');
})->purpose('Offene Freigaben nach Ablauf der Frist bestätigen');

/*
 * Online-Updater
 */
Artisan::command('heldenhaus:smoke', function () {
    DB::select('select 1');
    Cache::put('heldenhaus:smoke', true, 10);
    abort_unless(Cache::get('heldenhaus:smoke') === true, 500, 'Cache defekt');
    abort_unless(Formulas::xpToNextLevel(1) === 150, 500, 'Spielformeln defekt');
    $this->info('OK '.config('updater.version'));
})->purpose('Gesundheitsprüfung nach einem Update');

Artisan::command('heldenhaus:updater-keygen', function () {
    $pair = ManifestVerifier::generateKeyPair();
    $this->line('Öffentlicher Schlüssel → .env auf dem Server:');
    $this->info("UPDATER_PUBLIC_KEY={$pair['public']}");
    $this->line('Geheimer Schlüssel → nur als GitHub-Secret UPDATER_SECRET_KEY speichern, niemals ins Repo:');
    $this->warn($pair['secret']);
})->purpose('Schlüsselpaar für signierte Release-Pakete erzeugen');

Artisan::command('heldenhaus:update {--check : Nur prüfen} {--version= : Bestimmte Version} {--channel=} {--auto : Nur Patch-Versionen ohne Migrationen}', function (UpdateRunner $runner, \App\Domain\Updater\ReleaseSource $source) {
    if ($this->option('auto') && ! config('updater.auto_patch')) {
        return;
    }

    $release = $this->option('version')
        ? $source->find($this->option('version'))
        : $runner->check($this->option('channel'));

    if ($release === null) {
        $this->info('Heldenhaus ist aktuell ('.config('updater.version').').');

        return;
    }

    $this->info("Neue Version verfügbar: {$release->version} ({$release->channel})");
    if ($this->option('check')) {
        return;
    }

    if ($this->option('auto') && ! Version::isPatchUpgrade($release->version, config('updater.version'))) {
        $this->warn('Keine Patch-Version – bitte manuell im Admin-Bereich installieren.');

        return;
    }

    if ($this->option('auto') && (json_decode($source->fetch($release->manifestUrl), true)['has_migrations'] ?? true)) {
        $this->warn('Update enthält Migrationen – bitte manuell im Admin-Bereich installieren.');

        return;
    }

    $update = $runner->start($release);
    while (! $update->isFinished()) {
        $this->line("→ {$update->step}");
        $update = $runner->step($update);
    }

    foreach ($update->log as $entry) {
        $this->line("[{$entry['level']}] {$entry['message']}");
    }

    $update->status === 'done' ? $this->info('Update erfolgreich.') : $this->error("Update {$update->status}.");
})->purpose('Online-Update prüfen und installieren');

/*
 * Scheduler – auf dem Hetzner-Webhosting per konsoleH-Cron jede Minute:
 *   php artisan schedule:run
 * Es gibt keine Dauerprozesse: die Queue wird hier kurz abgearbeitet.
 */
Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=3')
    ->everyMinute()
    ->withoutOverlapping(5);

Schedule::command('heldenhaus:day-cron')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('heldenhaus:auto-approve')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('heldenhaus:update --auto')->dailyAt('03:00')->withoutOverlapping();
Schedule::command('model:prune')->daily();
Schedule::command('queue:prune-failed --hours=168')->daily();
