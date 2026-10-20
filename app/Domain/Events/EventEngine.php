<?php

namespace App\Domain\Events;

use App\Domain\Achievements\AchievementService;
use App\Domain\Crafting\Catalog;
use App\Domain\Gameplay\Formulas;
use App\Domain\Gameplay\Progression;
use App\Domain\Integrations\HomeAssistant\HomeAssistantWebhooks;
use App\Domain\Inventory\Stock;
use App\Domain\Notifications\PushNotifier;
use App\Domain\Quests\QuestService;
use App\Domain\Social\FeedService;
use App\Enums\FamilyRole;
use App\Models\EventParticipation;
use App\Models\EventTriggerLog;
use App\Models\FamilyEvent;
use App\Models\Item;
use App\Models\Quest;
use App\Models\TaskCompletion;
use App\Models\User;
use App\Models\UserRecipe;
use App\Models\UserStats;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Ablauf einer Event-Instanz (§12.2): geplant → aktiv → endet bald → beendet.
 * Höchstens ein Kalender-Event und zwei weitere laufen gleichzeitig; was nicht starten kann,
 * wartet bis zu 3 Tage und verfällt sonst. Event-Punkte entstehen wie Boss-Schaden.
 */
class EventEngine
{
    public const LIMIT_CALENDAR = 1;

    public const LIMIT_OTHER = 2;

    public const QUEUE_DAYS = 3;

    public function __construct(
        private readonly FeedService $feed,
        private readonly PushNotifier $push,
        private readonly HomeAssistantWebhooks $webhooks,
    ) {}

    // ---------- Abfragen ----------

    /** @return Collection<int, FamilyEvent> laufende Events der Familie (zwischengespeichert) */
    public static function running(?int $familyId): Collection
    {
        return $familyId ? app(ActiveEvents::class)->for($familyId) : collect();
    }

    /** @return Collection<int, FamilyEvent> Events, die für diese Person wirken (persönliche nur für die Hauptperson) */
    public static function runningFor(User $user): Collection
    {
        return self::running($user->family_id)
            ->filter(fn (FamilyEvent $e) => ! ($e->definition()['personal'] ?? false) || $e->subject_user_id === $user->id)
            ->values();
    }

    /** @return list<string> */
    public static function activeKeys(?int $familyId): array
    {
        return self::running($familyId)->pluck('event_key')->unique()->values()->all();
    }

    public static function isRunning(?int $familyId, ?string $key): bool
    {
        return $key !== null && in_array($key, self::activeKeys($familyId), true);
    }

    public static function find(?int $familyId, string $key): ?FamilyEvent
    {
        return self::running($familyId)->firstWhere('event_key', $key);
    }

    /**
     * Modifikatoren der laufenden Events für diese Person. Gleichartige Boni (Typ + Kategorie) stapeln nicht.
     *
     * @return list<array{event: string, modifier: array}>
     */
    public static function modifiersFor(User $user): array
    {
        return self::runningFor($user)
            ->flatMap(fn (FamilyEvent $e) => collect($e->definition()['modifiers'] ?? [])->map(fn ($m) => ['event' => $e->event_key, 'modifier' => $m]))
            ->unique(fn ($m) => $m['modifier']['type'].'|'.($m['modifier']['category'] ?? ''))
            ->values()->all();
    }

    /** Wert eines Modifikators (z. B. garden_speed → factor), wenn ein laufendes Event ihn hat. */
    public static function modifier(?int $familyId, string $type, string $field = 'factor', float $default = 1.0): float
    {
        foreach (self::running($familyId) as $event) {
            foreach ($event->definition()['modifiers'] ?? [] as $m) {
                if ($m['type'] === $type) {
                    return (float) ($m[$field] ?? $default);
                }
            }
        }

        return $default;
    }

    /** @return Collection<int, User> wer bei diesem Event mitmacht und belohnt wird (auch im Gasthaus) */
    public static function members(FamilyEvent $event): Collection
    {
        if (($event->definition()['personal'] ?? false) && $event->subject_user_id) {
            return User::whereKey($event->subject_user_id)->get();
        }

        return User::where('family_id', $event->family_id)->where('family_role', '!=', FamilyRole::Guest->value)->get();
    }

    public static function participation(FamilyEvent $event, int $userId): EventParticipation
    {
        return EventParticipation::firstOrCreate(['family_event_id' => $event->id, 'user_id' => $userId], ['points' => 0, 'collected' => []]);
    }

    // ---------- Ablauf ----------

    /** Scheduler (alle 15 Minuten, idempotent): beenden, „endet bald“ markieren, fällige starten oder verfallen lassen. */
    public function tick(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $counts = ['started' => 0, 'finished' => 0, 'skipped' => 0];

        FamilyEvent::running()->where('ends_at', '<=', $now)->get()->each(function (FamilyEvent $e) use (&$counts) {
            $this->finish($e) && $counts['finished']++;
        });

        // Sanfter Hinweis 24 h vor dem Ende – bei eintägigen Events nicht
        FamilyEvent::where('status', 'active')->where('ends_at', '<=', $now->addDay())->get()
            ->filter(fn (FamilyEvent $e) => $e->starts_at->diffInHours($e->ends_at) > 24)
            ->each(fn (FamilyEvent $e) => $e->update(['status' => 'ending']));

        FamilyEvent::where('status', 'scheduled')->where('starts_at', '<=', $now)->orderBy('starts_at')->get()
            ->sortByDesc(fn (FamilyEvent $e) => $e->definition()['priority'] ?? 50)
            ->each(function (FamilyEvent $e) use ($now, &$counts) {
                $result = $this->tryActivate($e, $now);
                if ($result === 'started') {
                    $counts['started']++;
                } elseif ($result === 'skipped') {
                    $counts['skipped']++;
                }
            });

        $this->revertClassTrials($now);
        app(ActiveEvents::class)->forget();

        return $counts;
    }

    /** started | queued | skipped */
    public function tryActivate(FamilyEvent $event, ?CarbonImmutable $now = null): string
    {
        $now ??= CarbonImmutable::now();
        $queueEnd = $event->queued_until ?? CarbonImmutable::instance($event->starts_at)->addDays(self::QUEUE_DAYS)->min($event->ends_at);

        if ($event->ends_at->lte($now) || $queueEnd->lt($now)) {
            $event->update(['status' => 'skipped']);
            self::log($event->family_id, $event->event_key, $event->trigger_source, 'skipped');

            return 'skipped';
        }

        if (! $this->hasRoom($event)) {
            if ($event->queued_until === null) {
                $event->update(['queued_until' => $queueEnd]);
                self::log($event->family_id, $event->event_key, $event->trigger_source, 'queued');
            }

            return 'queued';
        }

        return $this->activate($event, $now) ? 'started' : 'skipped';
    }

    /** Ist im Limit gleichzeitiger Events Platz? Persönliche Events und solche mit counts:false zählen nicht. */
    public function hasRoom(FamilyEvent $event): bool
    {
        $def = $event->definition();
        if (($def['counts'] ?? true) === false || ($def['personal'] ?? false)) {
            return true;
        }

        $running = FamilyEvent::where('family_id', $event->family_id)->running()->whereKeyNot($event->id)->get()
            ->map(fn (FamilyEvent $e) => $e->definition())
            ->reject(fn ($d) => ($d['counts'] ?? true) === false || ($d['personal'] ?? false));

        return $def['category'] === 'calendar'
            ? $running->where('category', 'calendar')->count() < self::LIMIT_CALENDAR
            : $running->where('category', '!=', 'calendar')->count() < self::LIMIT_OTHER;
    }

    public function activate(FamilyEvent $event, ?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now();
        $def = $event->definition();
        if ($def === []) {
            return false;
        }

        $activated = DB::transaction(function () use ($event, $def, $now) {
            $event = FamilyEvent::lockForUpdate()->find($event->id);
            if ($event === null || $event->status !== 'scheduled') {
                return null;
            }

            $members = self::members($event);
            $starts = $event->starts_at->lt($now) && $event->queued_until !== null ? $now : CarbonImmutable::instance($event->starts_at);
            $days = max(1, (int) ceil($starts->diffInHours($event->ends_at) / 24));

            $progress = ['points' => 0, 'tiers' => []];
            if (! empty($def['goal']['points_per_member_day'])) {
                $progress['goal'] = (float) round($def['goal']['points_per_member_day'] * max(1, $members->count()) * $days);
            }
            if (isset($def['merchant'])) {
                $progress['merchant'] = self::merchantOffers($def['merchant'], $event->seed);
            }

            $event->forceFill(['status' => 'active', 'starts_at' => $starts, 'progress' => $progress])->save();

            foreach ($members as $member) {
                $participation = self::participation($event, $member->id);
                if (isset($def['on_start'])) {
                    $this->reward($member, $def['on_start'], $participation, $event);
                }
                foreach ($def['recipes'] ?? [] as $recipeKey) {
                    if ((Catalog::recipes()[$recipeKey]['unlock'] ?? null) === 'event') {
                        UserRecipe::firstOrCreate(['user_id' => $member->id, 'recipe_key' => $recipeKey], ['source' => 'event']);
                    }
                }
            }

            if (! empty($def['quest_key'])) {
                $quest = app(QuestService::class)->startForEvent($event, $def['quest_key']);
                $quest && $event->forceFill(['quest_id' => $quest->id])->save();
            }

            return [$event, $members];
        });

        if ($activated === null) {
            return false;
        }
        [$event, $members] = $activated;
        app(ActiveEvents::class)->forget($event->family_id);
        self::log($event->family_id, $event->event_key, $event->trigger_source, 'started');

        $subject = $event->subject;
        $text = match ($event->event_key) {
            'birthday' => "{$def['icon']} Heute hat {$subject?->name} Geburtstag! Jede eurer Aufgaben schickt einen kleinen Gruß.",
            'welcome_party' => "{$def['icon']} Willkommen im Heldenhaus, {$subject?->name}! Das Willkommensfest beginnt.",
            'welcome_back' => null, // persönlich und ohne Hinweis auf die Pause (§11)
            default => "{$def['icon']} {$def['name']} hat begonnen!",
        };
        $text && $this->feed->post($event->family_id, null, 'event_started', $def['icon'], $text, ['event_key' => $event->event_key, 'event_id' => $event->id]);
        $this->push->notify($members, 'event_started');
        $this->webhooks->send($event->family_id, 'event_started', self::webhookData($event));

        return true;
    }

    public function finish(FamilyEvent $event): bool
    {
        $done = DB::transaction(function () use ($event) {
            $event = FamilyEvent::lockForUpdate()->find($event->id);
            if ($event === null || ! in_array($event->status, FamilyEvent::RUNNING, true)) {
                return null;
            }
            $event->update(['status' => 'finished']);

            return $event;
        });
        if ($done === null) {
            return false;
        }

        app(ActiveEvents::class)->forget($done->family_id);
        $def = $done->definition();
        $progress = $done->progress ?? [];

        if (isset($def['secret_help'])) {
            $this->revealSecretHelpers($done);
        }
        if (isset($progress['goal']) && ($def['personal'] ?? false) === false) {
            $reached = count($progress['tiers'] ?? []);
            $this->feed->post($done->family_id, null, 'event_ended', $def['icon'],
                "Rückblick {$def['name']}: ".round($progress['points'] ?? 0)." Event-Punkte".($reached ? ", {$reached} Stufe(n) geschafft. Danke an alle!" : '. Schön, dass ihr dabei wart!'),
                ['event_key' => $done->event_key, 'event_id' => $done->id]);
        }
        $this->webhooks->send($done->family_id, 'event_ended', self::webhookData($done));

        User::whereIn('id', EventParticipation::where('family_event_id', $done->id)->where('points', '>', 0)->select('user_id'))->get()
            ->each(fn (User $u) => app(AchievementService::class)->evaluate($u, ['events_joined']));

        return true;
    }

    // ---------- Fortschritt ----------

    /**
     * Ein positiver Abschluss bringt Event-Punkte (wie Boss-Schaden), eventuell einen Event-Fund,
     * Mentoren-Bonus und Geburtstagsgrüße. Wird nach dem Abschluss bzw. nach der Freigabe aufgerufen.
     *
     * @return array{points: float, drop: ?array, tiers: list<array>, effects: list<string>}|null
     */
    public function score(TaskCompletion $completion): ?array
    {
        $completion->loadMissing('task', 'user.stats');
        $user = $completion->user;
        if ($user?->family_id === null || $completion->direction !== 'up' || $completion->event_progress !== null) {
            return null;
        }

        $events = self::runningFor($user);
        // Persönliche Events anderer (Geburtstag) bekommen Grüße
        $others = self::running($user->family_id)->reject(fn (FamilyEvent $e) => $events->contains('id', $e->id));
        if ($events->isEmpty() && $others->isEmpty()) {
            return null;
        }

        $points = Formulas::bossDamage((float) $completion->delta, (float) ($completion->task?->difficulty ?? 1));
        $progress = [];
        $tiers = [];
        $effects = [];

        foreach ($events as $event) {
            if (isset($event->progress['goal']) && $points > 0) {
                $tiers = [...$tiers, ...$this->addPoints($event, $user, $points)];
                $progress[$event->id] = $points;
            }
            foreach ($event->definition()['modifiers'] ?? [] as $m) {
                if ($m['type'] === 'mentor_bonus' && $this->mentor($event, $user, $m)) {
                    $effects[] = 'mentor_bonus';
                }
            }
        }

        foreach ($others as $event) {
            if (($event->definition()['greetings'] ?? false) && $event->subject_user_id !== $user->id) {
                $this->greet($event, $user);
                $effects[] = 'birthday_greeting';
            }
        }

        $drop = $this->rollDrop($completion, $user, $events);
        $completion->forceFill(['event_progress' => $progress ?: (object) []])->save();

        return ['points' => $progress ? $points : 0, 'drop' => $drop?->only(['id', 'key', 'name', 'icon', 'rarity']), 'tiers' => $tiers, 'effects' => $effects];
    }

    /** Laternenfest: Geschenke an Familienmitglieder bringen Event-Punkte (mit Tageslimit). */
    public function gift(User $from): void
    {
        foreach (self::runningFor($from) as $event) {
            $points = collect($event->definition()['modifiers'] ?? [])->firstWhere('type', 'gift_points')['points'] ?? null;
            if ($points === null || ! isset($event->progress['goal'])) {
                continue;
            }
            $participation = self::participation($event, $from->id);
            $collected = $participation->collected ?? [];
            $day = $from->gameDate()->toDateString();
            if (($collected['gifts'][$day] ?? 0) >= (int) config('game.events.gift_points_daily')) {
                continue;
            }
            $collected['gifts'][$day] = ($collected['gifts'][$day] ?? 0) + 1;
            $participation->forceFill(['collected' => $collected])->save();
            $this->addPoints($event, $from, (float) $points);
        }
    }

    /** Hauseinweihung: Wer ins Gästebuch der feiernden Familie schreibt, bekommt einmal am Tag ein paar Münzen. */
    public function guestbook(User $writer, int $familyId): void
    {
        $event = self::running($familyId)->first(fn (FamilyEvent $e) => isset($e->definition()['guestbook_points']));
        if ($event === null) {
            return;
        }
        $participation = self::participation($event, $writer->id);
        $collected = $participation->collected ?? [];
        $day = $writer->gameDate()->toDateString();
        if (in_array($day, $collected['guestbook'] ?? [], true)) {
            return;
        }
        $collected['guestbook'][] = $day;
        $participation->forceFill(['collected' => $collected])->save();
        $this->reward($writer, ['gold' => $event->definition()['guestbook_points']]);
    }

    /** Rückgängig / Ablehnung: Punkte und Event-Fund zurücknehmen (erreichte Stufen bleiben). */
    public static function revert(TaskCompletion $completion): void
    {
        foreach ((array) ($completion->event_progress ?? []) as $eventId => $points) {
            DB::transaction(function () use ($eventId, $points, $completion) {
                $event = FamilyEvent::lockForUpdate()->find($eventId);
                if ($event === null) {
                    return;
                }
                $progress = $event->progress ?? [];
                $progress['points'] = max(0, round(($progress['points'] ?? 0) - $points, 2));
                $event->forceFill(['progress' => $progress])->save();
                EventParticipation::where('family_event_id', $eventId)->where('user_id', $completion->user_id)
                    ->update(['points' => DB::raw('CASE WHEN points > '.(float) $points.' THEN points - '.(float) $points.' ELSE 0 END')]);
            });
        }

        if ($completion->event_drop_item_id) {
            \App\Models\UserItem::where('user_id', $completion->user_id)->where('item_id', $completion->event_drop_item_id)
                ->where('quantity', '>', 0)->decrement('quantity');
        }

        $completion->forceFill(['event_progress' => null, 'event_drop_item_id' => null])->save();
    }

    /**
     * Punkte für ein Event gutschreiben und neu erreichte Stufen auszahlen (genau einmal).
     *
     * @return list<array> neu erreichte Stufen
     */
    public function addPoints(FamilyEvent $event, User $user, float $points): array
    {
        $reached = DB::transaction(function () use ($event, $user, $points) {
            $event = FamilyEvent::lockForUpdate()->find($event->id);
            if ($event === null || ! in_array($event->status, FamilyEvent::RUNNING, true)) {
                return [];
            }
            $progress = $event->progress ?? [];
            $progress['points'] = round(($progress['points'] ?? 0) + $points, 2);

            $participation = self::participation($event, $user->id);
            $participation->increment('points', $points);

            $new = [];
            foreach ($event->definition()['goal']['tiers'] ?? [] as $tier) {
                $needed = ($progress['goal'] ?? INF) * $tier['percent'] / 100;
                if ($progress['points'] >= $needed && ! in_array($tier['percent'], $progress['tiers'] ?? [], true)) {
                    $progress['tiers'][] = $tier['percent'];
                    $new[] = $tier;
                }
            }
            $event->forceFill(['progress' => $progress])->save();

            foreach ($new as $tier) {
                foreach (self::members($event) as $member) {
                    $this->reward($member, $tier['rewards'] ?? [], self::participation($event, $member->id), $event);
                }
            }

            return array_map(fn ($t) => ['event' => $event->event_key, 'percent' => $t['percent'], 'rewards' => $t['rewards'] ?? []], $new);
        });

        if ($reached) {
            app(ActiveEvents::class)->forget($event->family_id);
            $def = $event->definition();
            foreach ($reached as $tier) {
                $this->feed->post($event->family_id, null, 'event_tier', $def['icon'], "{$def['name']}: {$tier['percent']} % geschafft! Belohnung für alle.", ['event_id' => $event->id]);
                $this->webhooks->send($event->family_id, 'event_tier_reached', self::webhookData($event) + ['percent' => $tier['percent']]);
            }
            $this->push->notify(self::members($event), 'event_tier');
        }

        return $reached;
    }

    /** Gold, XP, Gegenstände und Abzeichen (Abzeichen landen in der Teilnahme, Sterne gibt es nie). */
    public function reward(User $user, array $rewards, ?EventParticipation $participation = null, ?FamilyEvent $event = null): void
    {
        if (($rewards['gold'] ?? 0) || ($rewards['xp'] ?? 0)) {
            $stats = UserStats::lockForUpdate()->find($user->id);
            if ($stats) {
                Progression::changeGold($stats, (float) ($rewards['gold'] ?? 0));
                Progression::gainXp($stats, (int) ($rewards['xp'] ?? 0));
                $stats->save();
            }
        }
        $items = collect($rewards['items'] ?? [])->filter(fn ($q, $k) => Item::where('key', $k)->exists())->all();
        if ($items) {
            Stock::addKeys($user->id, $items);
        }
        if (isset($rewards['badge']) && $participation) {
            $collected = $participation->collected ?? [];
            $badge = $rewards['badge'].' '.($event?->starts_at->year ?? now()->year);
            if (! in_array($badge, $collected['badges'] ?? [], true)) {
                $collected['badges'][] = $badge;
                $participation->forceFill(['collected' => $collected])->save();
            }
        }
    }

    // ---------- intern ----------

    /** Event-Fund aus der Tabelle des Events, höchstens ein paar pro Tag (§2.3). */
    private function rollDrop(TaskCompletion $completion, User $user, Collection $events): ?Item
    {
        $tables = $events->filter(fn (FamilyEvent $e) => ! empty($e->definition()['drops']));
        if ($tables->isEmpty()) {
            return null;
        }

        $today = TaskCompletion::where('user_id', $user->id)->whereNotNull('event_drop_item_id')->where('created_at', '>=', now()->startOfDay())->count();
        if ($today >= (int) config('game.events.drop_daily_limit')) {
            return null;
        }

        $rng = new \Random\Randomizer(new \Random\Engine\Mt19937(crc32('event-drop|'.$completion->id)));
        if ($rng->nextFloat() >= (float) config('game.events.drop_chance')) {
            return null;
        }

        $event = $tables->values()[$rng->getInt(0, $tables->count() - 1)];
        $table = $event->definition()['drops'];
        $roll = $rng->getInt(1, max(1, array_sum($table)));
        $key = array_key_last($table);
        foreach ($table as $k => $weight) {
            $roll -= $weight;
            if ($roll <= 0) {
                $key = $k;
                break;
            }
        }

        $item = Item::where('key', $key)->first();
        if ($item === null) {
            return null;
        }
        Stock::add($user->id, $item->id, 1);
        $completion->forceFill(['event_drop_item_id' => $item->id])->save();

        $participation = self::participation($event, $user->id);
        $collected = $participation->collected ?? [];
        $collected['found'][$key] = ($collected['found'][$key] ?? 0) + 1;
        $participation->forceFill(['collected' => $collected])->save();

        return $item;
    }

    /** Willkommensfest: Wer dem Neuling hilft (solange er unter Level 3 ist), bekommt bis zu 3× am Tag einen Mentoren-Bonus. */
    private function mentor(FamilyEvent $event, User $user, array $m): bool
    {
        if (! $event->subject_user_id || $event->subject_user_id === $user->id) {
            return false;
        }
        if ((int) UserStats::whereKey($event->subject_user_id)->value('level') >= ($m['until_level'] ?? 3)) {
            return false;
        }

        $participation = self::participation($event, $user->id);
        $collected = $participation->collected ?? [];
        $day = $user->gameDate()->toDateString();
        if (($collected['mentor'][$day] ?? 0) >= 3) {
            return false;
        }
        $collected['mentor'][$day] = ($collected['mentor'][$day] ?? 0) + 1;
        $participation->forceFill(['collected' => $collected])->save();
        $this->reward($user, ['gold' => $m['gold'] ?? 2]);

        return true;
    }

    /** Geburtstag: jede Aufgabe der anderen schickt einen Gruß (einer pro Person und Tag). */
    private function greet(FamilyEvent $event, User $from): void
    {
        $participation = self::participation($event, $event->subject_user_id);
        $collected = $participation->collected ?? [];
        if (! in_array($from->name, $collected['greetings'] ?? [], true)) {
            $collected['greetings'][] = $from->name;
            $participation->forceFill(['collected' => $collected])->save();
        }
    }

    private function revealSecretHelpers(FamilyEvent $event): void
    {
        $helps = EventParticipation::with('user')->where('family_event_id', $event->id)->get()
            ->flatMap(fn (EventParticipation $p) => collect($p->collected['secret_help'] ?? [])->map(fn ($h) => [$p->user?->name, $h['for_name'] ?? '']));
        if ($helps->isEmpty()) {
            return;
        }
        $list = $helps->map(fn ($h) => "{$h[0]} → {$h[1]}")->unique()->take(12)->implode(', ');
        $this->feed->post($event->family_id, null, 'secret_helpers', '🎁', "Die heimlichen Helfer sind verraten: {$list}", ['event_id' => $event->id]);
    }

    /** Fasching: Klassen-Probetag nach 24 h zurücksetzen. */
    private function revertClassTrials(CarbonImmutable $now): void
    {
        EventParticipation::whereHas('familyEvent', fn ($q) => $q->where('event_key', 'carnival'))
            ->whereNotNull('collected')->get()
            ->filter(fn (EventParticipation $p) => isset($p->collected['class_trial']) && empty($p->collected['class_trial']['reverted'])
                && CarbonImmutable::parse($p->collected['class_trial']['until'])->lte($now))
            ->each(function (EventParticipation $p) {
                UserStats::whereKey($p->user_id)->update(['class_key' => $p->collected['class_trial']['original']]);
                $collected = $p->collected;
                $collected['class_trial']['reverted'] = true;
                $p->forceFill(['collected' => $collected])->save();
            });
    }

    /** Angebot des wandernden Händlers: je Familie und Besuch gleich (fester Seed). */
    public static function merchantOffers(array $merchant, string $seed): array
    {
        $pool = $merchant['pool'];
        $keys = array_keys($pool);
        usort($keys, fn ($a, $b) => crc32($seed.$a) <=> crc32($seed.$b));

        return collect(array_slice($keys, 0, $merchant['offers'] ?? 4))->map(fn ($k) => ['item' => $k, 'price' => (int) $pool[$k]])->all();
    }

    public static function webhookData(FamilyEvent $event): array
    {
        $def = $event->definition();

        return [
            'event_key' => $event->event_key,
            'name' => $def['name'] ?? $event->event_key,
            'icon' => $def['icon'] ?? null,
            'color' => $def['ha_actions']['color'] ?? ($def['decor']['colors'][0] ?? null),
            'colors' => $def['decor']['colors'] ?? [],
            'ends_at' => $event->ends_at->toIso8601String(),
        ];
    }

    public static function log(int $familyId, string $key, string $trigger, string $result, array $context = []): void
    {
        EventTriggerLog::create(['family_id' => $familyId, 'event_key' => $key, 'trigger' => $trigger, 'result' => $result, 'context' => $context ?: null]);
    }
}
