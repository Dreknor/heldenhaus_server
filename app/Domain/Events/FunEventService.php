<?php

namespace App\Domain\Events;

use App\Domain\Classes\ClassService;
use App\Domain\Gameplay\GameException;
use App\Domain\Integrations\HomeAssistant\HomeAssistantWebhooks;
use App\Domain\Social\FeedService;
use App\Domain\Support\FocusService;
use App\Domain\Support\SupportService;
use App\Enums\CompletionStatus;
use App\Models\FamilyEventSetting;
use App\Models\FunEventInstance;
use App\Models\GuestbookEntry;
use App\Models\NotificationSetting;
use App\Models\Quest;
use App\Models\TaskCompletion;
use App\Models\User;
use App\Models\UserPet;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Zufallsereignisse nur zum Spaß (§12.5): kein Ziel, kein Fortschritt, keine Folgen. Der Server entscheidet
 * mit festem Seed (Person, Datum, Zähler), höchstens 2 pro Person und 4 pro Familie am Tag, nie im Fokus-Modus,
 * bei „Animationen aus“ oder in Ruhezeiten, bei „dezent“ nur die ruhigen. Belohnungen sind winzig, nie Sterne,
 * und lösen keinen Push aus.
 */
class FunEventService
{
    public const CONTEXTS = ['home', 'stable', 'campfire'];

    public function __construct(private readonly FeedService $feed, private readonly HomeAssistantWebhooks $webhooks) {}

    // ---------- Auslösen ----------

    /** Nach einem positiven Abschluss: Kontexte bestimmen und höchstens ein Ereignis ziehen. */
    public function onScore(TaskCompletion $completion, bool $levelUp = false): ?array
    {
        $completion->loadMissing('task', 'user');
        $user = $completion->user;
        if (! $user?->family_id || in_array($completion->status, [CompletionStatus::Undone, CompletionStatus::Rejected], true)) {
            return null;
        }

        $since = $user->gameDate()->utc();
        $doneToday = TaskCompletion::where('user_id', $user->id)->where('direction', 'up')->where('created_at', '>=', $since)->count();
        $contexts = ['score'];
        $extra = ['n' => $doneToday];

        if ($completion->task?->category === 'household') {
            $contexts[] = 'score_household';
        }
        if ($doneToday === 1) {
            $contexts[] = 'first_task';
        }
        [$due, $done] = HomeAssistantWebhooks::dailies($user);
        if ($due > 0 && $due === $done) {
            $contexts[] = 'last_task';
        }
        if ($levelUp) {
            $contexts[] = 'level_up';
        }
        foreach (['rain', 'wind'] as $weather) {
            if (Cache::get("fun:ha:{$user->family_id}:{$weather}")) {
                $contexts[] = "ha_{$weather}";
            }
        }
        $partner = TaskCompletion::with('user')->where('direction', 'up')->where('user_id', '!=', $user->id)
            ->whereIn('user_id', User::where('family_id', $user->family_id)->select('id'))
            ->where('created_at', '>=', now()->subMinutes((int) config('game.fun_events.echo_minutes')))->latest('id')->first();
        if ($partner) {
            $contexts[] = 'echo';
            $extra['partner'] = $partner->user;
        }
        if ($this->isJellyHour($user)) {
            $contexts[] = 'monthly_hour';
        }

        return $this->roll($user, $contexts, $extra);
    }

    /** Beim Öffnen von Familienhaus, Stall oder Lagerfeuer (die App fragt nach). */
    public function forContext(User $user, string $context): ?array
    {
        if (! in_array($context, self::CONTEXTS, true)) {
            throw new GameException('Unbekannter Ort.');
        }

        return $this->roll($user, [$context], ['n' => (int) now($user->timezone ?: 'Europe/Berlin')->format('G')]);
    }

    /** Wetter aus Home Assistant: Regenbogen nach Regen, Windstoß an windigen Tagen. */
    public static function haSignal(int $familyId, string $signal): void
    {
        $weather = ['weather_rain' => 'rain', 'weather_wind' => 'wind'][$signal] ?? null;
        if ($weather) {
            Cache::put("fun:ha:{$familyId}:{$weather}", true, now()->addHours(4));
        }
    }

    /** Post vom Monster: ein paar Tage nach einem besiegten Boss kommt eine Urlaubskarte. */
    public function monsterPost(Quest $quest): ?FunEventInstance
    {
        $def = EventCatalog::fun()['monster_post'] ?? null;
        if ($def === null || ! FamilyEventSetting::for($quest->family_id)->funEnabled('monster_post', $def['default_enabled'] ?? true)) {
            return null;
        }
        $seed = sha1("monster_post|quest|{$quest->id}");
        $delay = $def['delay_days'] ?? [1, 3];
        $days = $delay[0] + crc32($seed) % max(1, $delay[1] - $delay[0] + 1);
        $visible = now()->addDays($days);
        $postcards = $def['postcards'] ?? ['Viele Grüße! Euer {boss}'];

        return FunEventInstance::firstOrCreate(['seed' => $seed], [
            'family_id' => $quest->family_id,
            'user_id' => $quest->scope === 'solo' ? $quest->user_id : null,
            'key' => 'monster_post',
            'status' => 'open',
            'data' => ['boss' => $quest->name, 'boss_icon' => $quest->icon, 'postcard' => str_replace('{boss}', $quest->name, $postcards[crc32($seed.'card') % count($postcards)])],
            'visible_from' => $visible,
            'expires_at' => $visible->copy()->addMinutes($def['minutes'] ?? 2880),
        ]);
    }

    /**
     * Zieht höchstens ein Ereignis aus den passenden Kontexten (fester Seed) und legt es an.
     */
    public function roll(User $user, array $contexts, array $extra = []): ?array
    {
        if (! $user->family_id || $this->blocked($user)) {
            return null;
        }
        $settings = FamilyEventSetting::for($user->family_id);
        if (! $settings->fun_events) {
            return null;
        }

        $tz = $user->timezone ?: 'Europe/Berlin';
        $local = CarbonImmutable::now($tz);
        $dayStart = $user->gameDate()->utc();
        $calmOnly = SupportService::settings($user)->feedback_level === 'subtle';

        $userToday = FunEventInstance::where('user_id', $user->id)->where('created_at', '>=', $dayStart)->count();
        $familyToday = FunEventInstance::where('family_id', $user->family_id)->where('created_at', '>=', $dayStart)->count();
        if ($familyToday >= (int) config('game.fun_events.per_family_daily')) {
            return null;
        }

        $base = "{$user->id}|{$local->toDateString()}|".implode(',', $contexts).'|'.($extra['n'] ?? 0);
        $candidates = collect(EventCatalog::fun())
            ->filter(fn ($d) => array_intersect($d['contexts'], $contexts) !== [])
            ->filter(fn ($d) => $settings->funEnabled($d['key'], $d['default_enabled'] ?? true))
            ->filter(fn ($d) => ! $calmOnly || ($d['calm'] ?? false))
            ->filter(fn ($d) => ! isset($d['hours']) || ((int) $local->format('G') >= $d['hours'][0] && (int) $local->format('G') < $d['hours'][1]))
            ->filter(fn ($d) => ($d['scope'] ?? 'user') === 'family' || $userToday < (int) config('game.fun_events.per_user_daily'))
            ->reject(fn ($d) => in_array('boss_defeated', $d['contexts'], true))
            ->sortBy(fn ($d) => crc32($base.$d['key']));

        foreach ($candidates as $def) {
            $chance = in_array('level_up', $contexts, true) && isset($def['level_up_chance']) ? $def['level_up_chance'] : (float) $def['chance'];
            if (self::fraction($base.'|'.$def['key']) >= $chance) {
                continue;
            }
            if (! $this->allowed($user, $def, $extra)) {
                continue;
            }
            $seed = sha1($def['key'] === 'jelly_hour' ? "jelly|{$user->family_id}|".$local->format('Y-m') : $base.'|'.$def['key']);
            if (FunEventInstance::where('seed', $seed)->exists()) {
                return null; // dieselbe Gelegenheit wurde schon ausgewertet
            }

            $instance = $this->create($user, $def, $seed, $extra);

            return $instance ? $this->present($instance, $user) : null;
        }

        return null;
    }

    // ---------- Anzeigen & Mitmachen ----------

    /** @return list<array> sichtbare, offene Ereignisse für Board und Abgleich */
    public function visible(User $user): array
    {
        if (! $user->family_id) {
            return [];
        }

        return FunEventInstance::where('family_id', $user->family_id)
            ->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', $user->id))
            ->where('status', 'open')->where('expires_at', '>', now())
            ->where(fn ($q) => $q->whereNull('visible_from')->orWhere('visible_from', '<=', now()))
            ->latest('id')->limit(5)->get()
            ->map(fn (FunEventInstance $i) => $this->present($i, $user))->values()->all();
    }

    /** Ein erfüllter Sternschnuppen-Wunsch erscheint am nächsten Morgen als Gruß. */
    public static function wishGreeting(User $user): ?string
    {
        $wish = FunEventInstance::where('user_id', $user->id)->where('key', 'shooting_star')->where('status', 'claimed')
            ->where('claimed_at', '>=', now()->subDays(2))->latest('id')->first();
        $greetFrom = isset($wish?->data['greet_from']) ? CarbonImmutable::parse($wish->data['greet_from']) : null;

        return $greetFrom && $greetFrom->lte(now()) && $greetFrom->addDay()->gt(now())
            ? "Guten Morgen! Gestern hast du dir gewünscht: „{$wish->data['wish']}“. Vielleicht klappt’s ja!"
            : null;
    }

    /** Antippen, Streicheln, Wünschen … „Wer zuerst“ gewinnt genau eine Person (Zeilensperre). */
    public function interact(User $user, FunEventInstance $instance, array $input = []): array
    {
        if ($instance->family_id !== $user->family_id || ($instance->user_id !== null && $instance->user_id !== $user->id)) {
            throw new GameException('Das ist nicht für dich.');
        }

        return DB::transaction(function () use ($user, $instance, $input) {
            $i = FunEventInstance::lockForUpdate()->findOrFail($instance->id);
            $def = $i->definition();

            if ($i->status === 'claimed') {
                if ($def['first_wins'] ?? false) {
                    $name = User::whereKey($i->claimed_by)->value('name');

                    return ['won' => false, 'message' => "{$name} war diesmal schneller. Beim nächsten Mal bestimmt!"];
                }
                throw new GameException('Schon erledigt.');
            }
            if ($i->expires_at->lte(now()) || ($i->visible_from && $i->visible_from->gt(now()))) {
                throw new GameException('Schon wieder weg!');
            }

            $data = $i->data ?? [];
            $message = null;
            switch ($def['action'] ?? 'tap') {
                case 'wish':
                    $wishes = $def['wishes'] ?? [];
                    $index = (int) ($input['wish'] ?? -1);
                    if (! isset($wishes[$index])) {
                        throw new GameException('Such dir einen Wunsch aus.');
                    }
                    $data['wish'] = $wishes[$index];
                    $data['greet_from'] = CarbonImmutable::now($user->timezone ?: 'Europe/Berlin')->addDay()->setTime(6, 0)->utc()->toIso8601String();
                    $message = 'Gewünscht! Morgen früh gibt es einen Gruß.';
                    break;
                case 'pet':
                    $data['taps'] = ($data['taps'] ?? 0) + 1;
                    if ($data['taps'] < ($def['taps'] ?? 3)) {
                        $i->forceFill(['data' => $data])->save();

                        return ['won' => false, 'taps' => $data['taps'], 'message' => 'Hicks!'];
                    }
                    $message = 'Der Schluckauf ist weg!';
                    break;
                case 'find':
                    if (($input['room'] ?? null) !== ($data['room'] ?? null)) {
                        throw new GameException('Hier liegt der Hut nicht. Such weiter!');
                    }
                    $message = 'Da ist ja dein Hut!';
                    break;
                case 'save_sticker':
                    GuestbookEntry::create(['family_id' => $user->family_id, 'user_id' => $user->id, 'kind' => 'sticker', 'body' => $def['reward']['sticker'] ?? '🌈']);
                    $message = 'Der Sticker klebt jetzt im Gästebuch.';
                    break;
                case 'save_postcard':
                    GuestbookEntry::create(['family_id' => $user->family_id, 'user_id' => $user->id, 'kind' => 'postcard', 'body' => mb_substr($data['postcard'] ?? '', 0, 300)]);
                    $message = 'Die Karte hängt jetzt im Gästebuch.';
                    break;
                case 'high_five':
                    $partner = isset($data['partner_instance_id']) ? FunEventInstance::find($data['partner_instance_id']) : null;
                    $data['double'] = $partner?->status === 'claimed';
                    $message = $data['double'] ? 'Doppel-Konfetti! 🎉🎉' : 'Abgeklatscht!';
                    break;
            }

            $reward = $this->reward($user, $i, $def);
            if ($reward) {
                $data['rewarded'] = $reward;
            }
            $i->forceFill(['status' => 'claimed', 'claimed_by' => $user->id, 'claimed_at' => now(), 'data' => $data])->save();

            if (($def['first_wins'] ?? false) && isset($def['feed'])) {
                $this->feed->post($user->family_id, $user, 'fun_event', $def['icon'], str_replace('{name}', $user->name, $def['feed']), ['key' => $i->key]);
            }

            return ['won' => true, 'reward' => $reward, 'message' => $message, 'double' => $data['double'] ?? null];
        });
    }

    public function present(FunEventInstance $i, User $viewer): array
    {
        $def = $i->definition();
        $age = $viewer->ageGroup()->value;
        $data = $i->data ?? [];
        $text = strtr($def['text'][$age] ?? $def['text']['child'] ?? '', [
            '{pet}' => $data['pet'] ?? 'Dein Haustier', '{boss}' => $data['boss'] ?? 'Das Monster', '{partner}' => $data['partner'] ?? 'Jemand',
        ]);

        return [
            'id' => $i->id,
            'key' => $i->key,
            'name' => $def['name'] ?? $i->key,
            'icon' => $def['icon'] ?? '✨',
            'action' => $def['action'] ?? 'none',
            'text' => $text,
            'line' => $data['line'] ?? null,               // Witz, Rätsel oder Glückskeks-Spruch
            'postcard' => $data['postcard'] ?? null,
            'wishes' => ($def['action'] ?? null) === 'wish' ? $def['wishes'] : null,
            'rooms' => ($def['action'] ?? null) === 'find' ? $def['rooms'] : null,
            'taps' => ($def['action'] ?? null) === 'pet' ? ['done' => $data['taps'] ?? 0, 'needed' => $def['taps'] ?? 3] : null,
            'family' => $i->user_id === null,
            'first_wins' => (bool) ($def['first_wins'] ?? false),
            'calm' => (bool) ($def['calm'] ?? false),
            'status' => $i->status,
            'expires_at' => $i->expires_at->toIso8601String(),
        ];
    }

    // ---------- intern ----------

    /** Fokus-Modus, „Animationen aus“ und Ruhezeiten: keine Zufallsereignisse. */
    public function blocked(User $user): bool
    {
        if (FocusService::running($user) !== null || SupportService::settings($user)->reduce_motion) {
            return true;
        }
        $notify = NotificationSetting::where('user_id', $user->id)->first() ?? new NotificationSetting;

        return $notify->isQuiet(CarbonImmutable::now($user->timezone ?: 'Europe/Berlin'));
    }

    private function allowed(User $user, array $def, array $extra): bool
    {
        if (($def['requires_ha'] ?? false) && ! ClassService::familyHasHomeAssistant($user->family_id)) {
            return false;
        }
        if (($def['requires'] ?? null) === 'pet' && ! UserPet::where('user_id', $user->id)->exists()) {
            return false;
        }
        if (in_array('echo', $def['contexts'], true) && ! isset($extra['partner'])) {
            return false;
        }
        $cooldown = (int) ($def['cooldown_hours'] ?? 0);
        if ($cooldown > 0) {
            $recent = FunEventInstance::where('family_id', $user->family_id)->where('key', $def['key'])
                ->when(($def['scope'] ?? 'user') === 'user', fn ($q) => $q->where('user_id', $user->id))
                ->where('created_at', '>=', now()->subHours($cooldown))->exists();
            if ($recent) {
                return false;
            }
        }

        return true;
    }

    private function create(User $user, array $def, string $seed, array $extra): ?FunEventInstance
    {
        $family = ($def['scope'] ?? 'user') === 'family';
        $data = [];
        $age = $user->ageGroup()->value;

        if (isset($def['jokes'])) {
            $lines = $def['jokes'][$age] ?? $def['jokes']['child'];
            $data['line'] = $lines[crc32($seed) % count($lines)];
        }
        if (isset($def['fortunes'])) {
            $lines = $def['fortunes'][$age] ?? $def['fortunes']['child'];
            $data['line'] = $lines[crc32($seed) % count($lines)];
        }
        if (isset($def['rooms'])) {
            $data['room'] = $def['rooms'][crc32($seed.'room') % count($def['rooms'])];
        }
        if (($def['requires'] ?? null) === 'pet') {
            $data['pet'] = UserPet::where('user_id', $user->id)->orderBy('id')->value('name');
        }
        if (isset($extra['partner'])) {
            $data['partner'] = $extra['partner']->name;
        }

        $minutes = (int) ($def['minutes'] ?? 10);
        $instance = FunEventInstance::create([
            'family_id' => $user->family_id,
            'user_id' => $family ? null : $user->id,
            'key' => $def['key'],
            'seed' => $seed,
            'status' => 'open',
            'data' => $data,
            'visible_from' => now(),
            'expires_at' => $def['key'] === 'jelly_hour' ? now()->addMinutes(60 - (int) now()->format('i')) : now()->addMinutes($minutes),
        ]);

        // Familien-Echo: Beide können abklatschen
        if (isset($extra['partner']) && in_array('echo', $def['contexts'], true)) {
            $mirror = FunEventInstance::firstOrCreate(['seed' => sha1($seed.'|partner')], [
                'family_id' => $user->family_id, 'user_id' => $extra['partner']->id, 'key' => $def['key'], 'status' => 'open',
                'data' => ['partner' => $user->name, 'partner_instance_id' => $instance->id],
                'visible_from' => now(), 'expires_at' => now()->addMinutes($minutes),
            ]);
            $instance->forceFill(['data' => [...$data, 'partner_instance_id' => $mirror->id]])->save();
        }

        if (isset($def['ha'])) {
            $this->webhooks->send($user->family_id, 'fun_haunt', ['action' => $def['ha'], 'key' => $def['key']]);
        }

        return $instance;
    }

    /** Winzige Belohnung mit eigenem Tageslimit: ein paar Münzen, ein Ei oder ein Material. Nie Sterne. */
    private function reward(User $user, FunEventInstance $i, array $def): ?array
    {
        $reward = $def['reward'] ?? null;
        if (! $reward || isset($reward['sticker']) || isset($reward['postcard'])) {
            return null;
        }
        $today = FunEventInstance::where('claimed_by', $user->id)->where('claimed_at', '>=', $user->gameDate()->utc())
            ->whereNotNull('data->rewarded')->count();
        if ($today >= (int) config('game.fun_events.reward_daily_limit')) {
            return null;
        }

        if (isset($reward['pool'])) {
            $pool = $reward['pool'];
            $roll = crc32($i->seed.'reward') % max(1, array_sum($pool));
            foreach ($pool as $k => $w) {
                if (($roll -= $w) < 0) {
                    $reward = ['items' => [$k => 1]];
                    break;
                }
            }
        }
        $rewards = array_intersect_key($reward, ['gold' => 1, 'items' => 1]);
        app(EventEngine::class)->reward($user, $rewards);

        return $rewards;
    }

    /** Wackelpudding-Stunde: eine feste Stunde pro Monat und Familie. */
    private function isJellyHour(User $user): bool
    {
        $def = EventCatalog::fun()['jelly_hour'] ?? null;
        if ($def === null) {
            return false;
        }
        $local = CarbonImmutable::now($user->family?->timezone ?: 'Europe/Berlin');
        $seed = "jelly|{$user->family_id}|".$local->format('Y-m');
        $day = 1 + crc32($seed) % $local->daysInMonth;
        [$from, $to] = $def['hours'] ?? [15, 19];
        $hour = $from + crc32($seed.'h') % max(1, $to - $from);

        return $local->day === $day && (int) $local->format('G') === $hour;
    }

    /** Gleichverteilte Zahl in [0, 1) aus einem Seed. */
    public static function fraction(string $seed): float
    {
        return hexdec(substr(hash('sha256', $seed), 0, 12)) / 0x1000000000000;
    }
}
