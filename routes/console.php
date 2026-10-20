<?php

use App\Domain\Achievements\AchievementService;
use App\Domain\Classes\ClassService;
use App\Domain\Family\FamilyService;
use App\Domain\Family\WeeklyReport;
use App\Domain\Gameplay\Formulas;
use App\Domain\Gameplay\RunDayCron;
use App\Domain\Notifications\PushNotifier;
use App\Domain\Tasks\ReviewCompletion;
use App\Domain\Updater\UpdaterException;
use App\Domain\Updater\UpdateService;
use App\Mail\WeeklyReportMail;
use App\Models\Family;
use App\Models\Item;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
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

Artisan::command('heldenhaus:community-rhythm', function (ClassService $classes) {
    $this->info($classes->evaluateCommunityRhythm().' Familien haben den Gemeinschafts-Rhythmus geschafft.');
})->purpose('Barden-Passive: Familien-Tag auswerten');

Artisan::command('heldenhaus:weekly-reports', function (PushNotifier $push) {
    foreach (Family::all() as $family) {
        $report = WeeklyReport::for($family);
        foreach ($family->parents()->whereNotNull('email')->get() as $parent) {
            Mail::to($parent->email)->queue(new WeeklyReportMail($report));
        }
        $push->notify($family->parents()->get(), 'weekly_report');
    }
    $this->info('Wochenberichte verschickt.');
})->purpose('Wochenbericht an Eltern senden');

Artisan::command('heldenhaus:auto-approve', function (ReviewCompletion $review) {
    $this->info($review->autoApproveExpired().' Freigaben automatisch bestätigt.');
})->purpose('Offene Freigaben nach Ablauf der Frist bestätigen');

/*
 * Spielinhalte aus database/content/*.json übernehmen. Bestehende Items bleiben unverändert
 * (Admins pflegen sie im Backend), außer mit --force.
 */
Artisan::command('game:content-sync {--force : Bestehende Items mit den Dateiwerten überschreiben}', function () {
    $items = json_decode(file_get_contents(database_path('content/items.json')), true, flags: JSON_THROW_ON_ERROR);
    $created = $updated = 0;

    foreach ($items as $data) {
        $item = Item::firstWhere('key', $data['key']);
        if ($item === null) {
            Item::create($data);
            $created++;
        } elseif ($this->option('force')) {
            $item->update($data);
            $updated++;
        }
    }

    $this->info("{$created} Items angelegt, {$updated} aktualisiert.");
})->purpose('Spielinhalte (Items) aus JSON übernehmen');

/*
 * Erfolge nachträglich vergeben (z. B. nach dem Update, das Erfolge einführt, oder neuen Stufen im Katalog).
 * Ohne Feier und Feed-Eintrag, damit niemand mit Altlasten überschüttet wird – das Gold gibt es trotzdem.
 */
Artisan::command('heldenhaus:achievements-sync', function (AchievementService $achievements) {
    $count = 0;
    User::whereNotNull('family_id')->whereHas('stats')->chunkById(200, function ($users) use ($achievements, &$count) {
        foreach ($users as $user) {
            $count += $achievements->evaluate($user, announce: false)->count();
        }
    });
    $this->info("{$count} Erfolgsstufen vergeben.");
})->purpose('Erfolge für alle nachträglich berechnen');

/*
 * Online-Updater
 */
Artisan::command('heldenhaus:smoke', function () {
    DB::select('select 1');
    Cache::put('heldenhaus:smoke', true, 10);
    abort_unless(Cache::get('heldenhaus:smoke') === true, 500, 'Cache defekt');
    abort_unless(Formulas::xpToNextLevel(1) === 150, 500, 'Spielformeln defekt');
    $this->info('OK');
})->purpose('Gesundheitsprüfung nach einem Update');

Artisan::command('updater:check {--no-fetch : Remote nicht abfragen, nur mit lokalem Stand vergleichen}', function (UpdateService $updater) {
    $result = $updater->check(! $this->option('no-fetch'));

    if ($result['error']) {
        $this->error('Prüfung fehlgeschlagen: '.$result['error']);

        return 1;
    }
    if ($result['fetch_error']) {
        $this->warn('Remote konnte nicht abgefragt werden: '.$result['fetch_error']);
    }

    $this->line("Branch: {$result['remote']}/{$result['branch']}");
    if (! $result['branch_matches']) {
        $this->warn("Ausgecheckt ist „{$result['current_branch']}“ – Updates sind nur auf „{$result['branch']}“ möglich (UPDATER_BRANCH).");
    }
    $this->line("Installiert: {$result['current']['short']} ({$result['current']['date']}) {$result['current']['subject']}");

    if ($result['behind'] === 0) {
        $this->info('Heldenhaus ist aktuell.');

        return 0;
    }

    $this->info("{$result['behind']} neue(r) Commit(s) verfügbar:");
    foreach ($result['commits'] as $commit) {
        $this->line("  {$commit['short']}  {$commit['subject']}");
    }
    if ($result['migrations']) {
        $this->line("Enthält {$result['migrations']} Datenbank-Migration(en).");
    }
    if ($result['local_changes']) {
        $this->warn('Lokal geänderte Dateien: '.implode(', ', $result['local_changes']));
    }

    return 0;
})->purpose('Prüft, ob im Git-Repository eine neue Version verfügbar ist');

Artisan::command('updater:run
    {--full : Alle Schritte ausführen, auch ohne neue Commits (z. B. nach einem fehlgeschlagenen Update)}
    {--if-requested : Nur ausführen, wenn über die Oberfläche ein Update angefordert wurde (Scheduler)}', function (UpdateService $updater) {
    if ($this->option('if-requested') && ! $updater->isRequested()) {
        return 0;
    }

    try {
        $success = $updater->run(
            full: (bool) $this->option('full'),
            output: fn (string $text) => $this->output->write($text),
        );
    } catch (UpdaterException $e) {
        $this->error($e->getMessage());

        return 1;
    }

    return $success ? 0 : 1;
})->purpose('Aktualisiert Heldenhaus aus dem Git-Repository (Online-Updater)');

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
Schedule::command('heldenhaus:community-rhythm')->hourly()->withoutOverlapping();
Schedule::command('heldenhaus:weekly-reports')->weeklyOn(0, '18:00')->timezone('Europe/Berlin');
Schedule::command('model:prune')->daily();
Schedule::command('queue:prune-failed --hours=168')->daily();

// Online-Updater: über die Oberfläche angeforderte Updates ausführen (auch im
// Wartungsmodus, damit ein fehlgeschlagenes Update erneut gestartet werden kann)
if (config('updater.enabled')) {
    Schedule::command('updater:run --if-requested')
        ->everyMinute()
        ->when(fn () => app(UpdateService::class)->isRequested())
        ->evenInMaintenanceMode()
        ->withoutOverlapping(60)
        ->runInBackground();

    if (config('updater.check_cron')) {
        Schedule::command('updater:check')->cron(config('updater.check_cron'));
    }
}
