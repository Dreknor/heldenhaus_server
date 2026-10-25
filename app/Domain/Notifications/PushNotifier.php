<?php

namespace App\Domain\Notifications;

use App\Jobs\SendPushNotification;
use App\Models\NotificationSetting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Push über FCM (Konzept §5.1, §8): Benachrichtigungen enthalten keine Inhalte, nur die Art
 * („Neue Freigabe“). Die App holt die Details selbst. In der Ruhezeit oder bei abgeschalteter Art
 * geht nur eine stille Datennachricht raus, die einen Abgleich auslöst.
 */
class PushNotifier
{
    /** Art → [Titel, Text]. Bewusst allgemein gehalten. */
    public const TYPES = [
        'approval_pending' => ['Neue Freigabe', 'Im Heldenhaus wartet etwas auf deine Bestätigung.'],
        'redemption_pending' => ['Neue Einlösung', 'Eine Belohnung wartet auf deine Bestätigung.'],
        'approval_reviewed' => ['Freigabe bearbeitet', 'Deine Eltern haben eine Aufgabe angeschaut.'],
        'redemption_reviewed' => ['Belohnung bearbeitet', 'Deine Eltern haben eine Einlösung angeschaut.'],
        'level_up' => ['Level-up!', 'Du bist eine Stufe aufgestiegen.'],
        'quest_completed' => ['Quest geschafft!', 'Schau nach, was ihr gewonnen habt.'],
        'stars_granted' => ['Lob!', 'Du hast Sterne bekommen.'],
        'challenge' => ['Neue Challenge', 'Es gibt eine neue Challenge.'],
        'message' => ['Neue Nachricht', 'Im Heldenhaus gibt es eine neue Nachricht.'],
        'weekly_report' => ['Wochenbericht', 'Der Wochenbericht ist da.'],
        'craft_ready' => ['Fertig!', 'In der Werkstatt ist etwas fertig geworden.'],
        'build_done' => ['Einweihung!', 'Ein Bauprojekt im Familienhaus ist fertig.'],
        'trade' => ['Tauschangebot', 'Es gibt Neuigkeiten zu einem Tausch.'],
        'gift' => ['Geschenk', 'Du hast ein Geschenk bekommen.'],
        'dungeon' => ['Dungeon', 'Im Familien-Dungeon wartet der Endboss auf euch!'],
        'event_started' => ['Ein Event beginnt', 'Im Heldenhaus ist etwas Besonderes los.'],
        'event_tier' => ['Event-Stufe geschafft!', 'Ihr habt gemeinsam eine Event-Stufe erreicht.'],
    ];

    /**
     * Diese Arten kommen als reine Datennachricht (data.show = 1, Titel und Text in data), damit die App
     * die Benachrichtigung selbst baut, z. B. mit einem Bestätigen-Knopf bei Freigaben.
     */
    public const DATA_ONLY = ['approval_pending'];

    /** @param  User|iterable<User>  $users */
    public function notify(User|iterable $users, string $type, array $data = []): int
    {
        $users = new EloquentCollection($users instanceof User ? [$users] : collect($users)->all());
        $count = 0;

        foreach ($users->loadMissing('deviceTokens', 'notificationSetting') as $user) {
            if ($user->deviceTokens->isEmpty()) {
                continue;
            }

            $setting = $user->notificationSetting ?? new NotificationSetting;
            $visible = isset(self::TYPES[$type]) && $setting->allows($type) && ! $setting->isQuiet(CarbonImmutable::now($user->timezone ?? 'Europe/Berlin'));

            $dataOnly = in_array($type, self::DATA_ONLY, true);
            $payload = self::data($type, $data + ($dataOnly && $visible ? ['show' => '1', 'title' => self::TYPES[$type][0], 'body' => self::TYPES[$type][1]] : []));

            foreach ($user->deviceTokens as $device) {
                SendPushNotification::dispatch($device->id, $visible && ! $dataOnly ? $type : null, $payload)->afterCommit();
                $count++;
            }
        }

        return $count;
    }

    public static function parentsOf(?int $familyId): Collection
    {
        return $familyId ? User::where('family_id', $familyId)->where('family_role', 'parent')->get() : collect();
    }

    /** @return array<string, string> */
    private static function data(string $type, array $data): array
    {
        return collect(['type' => $type, 'sync' => '1'] + $data)
            ->map(fn ($v) => is_scalar($v) ? (string) $v : json_encode($v))
            ->all();
    }
}
