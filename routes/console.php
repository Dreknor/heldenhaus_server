<?php

use App\Domain\Family\FamilyService;
use App\Domain\Gameplay\RunDayCron;
use App\Domain\Tasks\ReviewCompletion;
use App\Enums\FamilyRole;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
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
 * Scheduler – auf dem Hetzner-Webhosting per konsoleH-Cron jede Minute:
 *   php artisan schedule:run
 * Es gibt keine Dauerprozesse: die Queue wird hier kurz abgearbeitet.
 */
Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=3')
    ->everyMinute()
    ->withoutOverlapping(5);

Schedule::command('heldenhaus:day-cron')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('heldenhaus:auto-approve')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('model:prune')->daily();
Schedule::command('queue:prune-failed --hours=168')->daily();
