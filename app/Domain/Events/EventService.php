<?php

namespace App\Domain\Events;

use App\Domain\Classes\ClassCatalog;
use App\Domain\Crafting\Catalog;
use App\Domain\Features\Features;
use App\Domain\Gameplay\GameException;
use App\Domain\Gameplay\Progression;
use App\Domain\Integrations\HomeAssistant\HomeAssistantWebhooks;
use App\Domain\Inventory\Stock;
use App\Domain\Quests\QuestService;
use App\Domain\Tasks\TaskService;
use App\Enums\CompletionStatus;
use App\Enums\FamilyRole;
use App\Enums\TaskType;
use App\Models\BuildProject;
use App\Models\EventParticipation;
use App\Models\FamilyEvent;
use App\Models\FamilyEventSetting;
use App\Models\HolidayPeriod;
use App\Models\Item;
use App\Models\MemberDate;
use App\Models\Quest;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\TaskCompletion;
use App\Models\User;
use App\Models\UserAchievement;
use App\Models\UserStats;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Spieler- und Eltern-Aktionen rund um Events (§12.6 API). */
class EventService
{
    public function __construct(
        private readonly EventEngine $engine,
        private readonly EventTrigger $trigger,
        private readonly EventPlanner $planner,
        private readonly HomeAssistantWebhooks $webhooks,
    ) {}

    // ---------- Anzeigen ----------

    /** Laufende Events und „Demnächst“ (Kalender-Events 7 Tage vorher). */
    public function index(User $user): array
    {
        $running = FamilyEvent::where('family_id', $user->family_id)->running()->where('ends_at', '>', now())->orderBy('starts_at')->get()
            ->filter(fn (FamilyEvent $e) => self::visibleTo($e, $user));

        $upcoming = FamilyEvent::where('family_id', $user->family_id)->where('status', 'scheduled')
            ->where('starts_at', '<=', now()->addDays((int) config('game.events.upcoming_days')))->orderBy('starts_at')->get()
            ->filter(fn (FamilyEvent $e) => $e->definition()['category'] === 'calendar');

        return [
            'running' => $running->map(fn (FamilyEvent $e) => $this->present($e, $user))->values(),
            'upcoming' => $upcoming->map(fn (FamilyEvent $e) => [
                'id' => $e->id, 'key' => $e->event_key, 'name' => $e->definition()['name'], 'icon' => $e->definition()['icon'],
                'description' => self::text($e->definition(), $user), 'starts_at' => $e->starts_at->toIso8601String(),
            ])->values(),
        ];
    }

    /** Kompakt für Board und Abgleich (Deko und Modifikatoren offline anzeigen). */
    public static function summary(User $user): array
    {
        if (! $user->family_id) {
            return [];
        }

        return EventEngine::running($user->family_id)->filter(fn (FamilyEvent $e) => self::visibleTo($e, $user))
            ->map(fn (FamilyEvent $e) => [
                'id' => $e->id,
                'key' => $e->event_key,
                'name' => $e->definition()['name'],
                'icon' => $e->definition()['icon'],
                'status' => $e->status,
                'decor' => $e->definition()['decor'] ?? null,
                'modifiers' => $e->definition()['modifiers'] ?? [],
                'time_progress' => self::timeProgress($e),
                'goal_percent' => isset($e->progress['goal']) ? min(100, (int) floor(100 * ($e->progress['points'] ?? 0) / max(1, $e->progress['goal']))) : null,
                'ends_at' => $e->ends_at->toIso8601String(),
            ])->values()->all();
    }

    public function history(User $user): array
    {
        return FamilyEvent::with(['participations' => fn ($q) => $q->where('user_id', $user->id)])
            ->where('family_id', $user->family_id)->where('status', 'finished')->latest('starts_at')->limit(50)->get()
            ->filter(fn (FamilyEvent $e) => self::visibleTo($e, $user))
            ->map(fn (FamilyEvent $e) => [
                'id' => $e->id, 'key' => $e->event_key, 'name' => $e->definition()['name'] ?? $e->event_key, 'icon' => $e->definition()['icon'] ?? '🎉',
                'starts_at' => $e->starts_at->toIso8601String(), 'ends_at' => $e->ends_at->toIso8601String(),
                'points' => round((float) ($e->progress['points'] ?? 0), 1), 'tiers' => $e->progress['tiers'] ?? [],
                'own_points' => round((float) ($e->participations->first()?->points ?? 0), 1),
                'badges' => $e->participations->first()?->collected['badges'] ?? [],
            ])->values()->all();
    }

    public function present(FamilyEvent $e, User $viewer, bool $detail = false): array
    {
        $def = $e->definition();
        $participation = EventParticipation::where('family_event_id', $e->id)->where('user_id', $viewer->id)->first();
        $collected = $participation?->collected ?? [];
        $progress = $e->progress ?? [];
        $crafting = Features::has($viewer, 'crafting');

        $data = [
            'id' => $e->id,
            'key' => $e->event_key,
            'name' => $def['name'],
            'icon' => $def['icon'],
            'category' => $def['category'],
            'status' => $e->status,
            'personal' => (bool) ($def['personal'] ?? false),
            'subject' => $e->subject ? ['id' => $e->subject->id, 'name' => $e->subject->name] : null,
            'description' => self::text($def, $viewer),
            'starts_at' => $e->starts_at->toIso8601String(),
            'ends_at' => $e->ends_at->toIso8601String(),
            'ending_soon' => $e->status === 'ending',
            // Zeit als Balken, nicht als Countdown (§11)
            'time_progress' => self::timeProgress($e),
            'decor' => $def['decor'] ?? null,
            'modifiers' => $def['modifiers'] ?? [],
            'goal' => isset($progress['goal']) ? [
                'goal' => $progress['goal'],
                'points' => round((float) ($progress['points'] ?? 0), 1),
                'percent' => min(100, (int) floor(100 * ($progress['points'] ?? 0) / max(1, $progress['goal']))),
                'tiers' => collect($def['goal']['tiers'] ?? [])->map(fn ($t) => [
                    'percent' => $t['percent'], 'reached' => in_array($t['percent'], $progress['tiers'] ?? [], true), 'rewards' => self::rewardsView($t['rewards'] ?? []),
                ])->values(),
            ] : null,
            'quest' => $e->quest_id ? QuestService::present(Quest::find($e->quest_id)) : null,
            // Nur Freigeschaltetes zeigen (§12.1/§13): Rezepte erst mit der Werkstatt
            'recipes' => $crafting ? collect($def['recipes'] ?? [])->map(fn ($k) => ['key' => $k, 'output' => Catalog::recipes()[$k]['output'] ?? $k])->values() : [],
            'own' => [
                'points' => round((float) ($participation?->points ?? 0), 1),
                'found' => $collected['found'] ?? (object) [],
                'badges' => $collected['badges'] ?? [],
            ],
            'features' => array_values(array_filter([
                isset($def['advent']) ? 'advent' : null,
                isset($def['secret_help']) ? 'secret_help' : null,
                isset($def['merchant']) ? 'merchant' : null,
                ($def['joker'] ?? false) && $e->subject_user_id === $viewer->id ? 'joker' : null,
                $e->event_key === 'role_swap' && ! $viewer->isParent() ? 'assign_parent' : null,
                ($def['class_trial'] ?? false) && Features::has($viewer, 'classes') ? 'class_trial' : null,
                ($def['year_review'] ?? false) ? 'year_review' : null,
            ])),
        ];

        if (! $detail) {
            return $data;
        }

        if (isset($def['advent'])) {
            $data['advent'] = $this->adventDoors($e, $viewer, $collected);
        }
        if (isset($def['secret_help'])) {
            $data['secret_help'] = $this->secretHelpView($e, $viewer, $collected);
        }
        if (isset($def['merchant'])) {
            $bought = $collected['bought'] ?? [];
            $items = Item::whereIn('key', collect($progress['merchant'] ?? [])->pluck('item'))->get()->keyBy('key');
            $data['merchant'] = collect($progress['merchant'] ?? [])->map(fn ($o) => [
                'item' => $o['item'], 'name' => $items[$o['item']]->name ?? $o['item'], 'icon' => $items[$o['item']]->icon ?? '📦',
                'price' => $o['price'], 'bought' => in_array($o['item'], $bought, true),
            ])->values();
        }
        if ($def['greetings'] ?? false) {
            $data['greetings'] = $e->subject_user_id === $viewer->id ? ($collected['greetings'] ?? []) : null;
            $data['joker_used'] = isset($collected['joker']);
        }
        if (isset($def['chapters'])) {
            $index = min(count($def['chapters']) - 1, intdiv((int) $e->starts_at->diffInDays(now()), 7));
            $data['chapter'] = ['index' => $index + 1, 'of' => count($def['chapters']), 'key' => $def['chapters'][$index]];
        }
        if ($def['year_review'] ?? false) {
            $data['year_review'] = $this->yearReview($e->family_id, $e->starts_at);
        }
        $data['suggestions'] = array_filter([
            'habit' => $def['suggest_habit'] ?? null,
            'steps' => $def['step_templates'] ?? null,
            'inn' => ($def['suggest_inn'] ?? false) ?: null,
        ]);
        if ($def['class_trial'] ?? false) {
            $data['class_trial'] = $collected['class_trial'] ?? null;
        }

        return $data;
    }

    // ---------- Aktionen ----------

    /** Wichtelwerkstatt: ein Türchen je Dezembertag, erst nach der ersten erledigten Aufgabe des Tages; verpasste lassen sich nachholen. */
    public function openAdvent(User $user, FamilyEvent $e, int $day): array
    {
        $def = $this->runningDefinition($user, $e, 'advent');
        $today = $user->gameDate();
        if ($day < 1 || $day > 24) {
            throw new GameException('Dieses Türchen gibt es nicht.');
        }
        if (! $this->doorAvailable($e, $today, $day)) {
            throw new GameException('Dieses Türchen ist noch zu. Geduld!');
        }
        if (! TaskCompletion::where('user_id', $user->id)->where('direction', 'up')
            ->whereNotIn('status', [CompletionStatus::Undone->value, CompletionStatus::Rejected->value])
            ->where('created_at', '>=', $today->setTimezone('UTC'))->exists()) {
            throw new GameException('Erledige zuerst eine Aufgabe, dann darfst du ein Türchen öffnen.');
        }

        $result = DB::transaction(function () use ($user, $e, $day, $def) {
            $participation = EventEngine::participation($e, $user->id);
            $participation = EventParticipation::lockForUpdate()->find($participation->id);
            $collected = $participation->collected ?? [];
            if (isset($collected['advent'][$day])) {
                throw new GameException('Dieses Türchen hast du schon geöffnet.');
            }

            $pool = $def['advent']['pool'];
            $roll = crc32("{$e->seed}|{$user->id}|{$day}") % max(1, array_sum($pool));
            $key = array_key_last($pool);
            foreach ($pool as $k => $w) {
                if (($roll -= $w) < 0) {
                    $key = $k;
                    break;
                }
            }
            $this->engine->reward($user, ['gold' => $def['advent']['gold'] ?? 0, 'items' => [$key => 1]]);
            $collected['advent'][$day] = $key;
            $participation->forceFill(['collected' => $collected])->save();

            return $key;
        });

        $this->webhooks->send($user->family_id, 'event_advent_door', EventEngine::webhookData($e) + ['day' => $day, 'action' => $def['ha_actions']['on_advent'] ?? null] + HomeAssistantWebhooks::member($user));
        $item = Item::where('key', $result)->first();

        return ['day' => $day, 'item' => $item?->only(['key', 'name', 'icon', 'rarity']), 'gold' => $def['advent']['gold'] ?? 0];
    }

    /** Heimliche Helfer: eine eigene erledigte Aufgabe einem anderen Mitglied widmen. Aufgelöst wird am 24.12. */
    public function secretHelp(User $user, FamilyEvent $e, int $completionId, int $forUserId): array
    {
        $def = $this->runningDefinition($user, $e, 'secret_help');
        $for = User::find($forUserId);
        if ($for === null || ! $for->sameFamilyAs($user) || $for->id === $user->id) {
            throw new GameException('Heimlich helfen kannst du anderen aus deiner Familie.');
        }
        $completion = TaskCompletion::where('user_id', $user->id)->where('direction', 'up')->find($completionId);
        if ($completion === null || $completion->created_at->lt($e->starts_at) || in_array($completion->status, [CompletionStatus::Undone, CompletionStatus::Rejected], true)) {
            throw new GameException('Diese Aufgabe kannst du nicht widmen.');
        }

        return DB::transaction(function () use ($user, $e, $def, $for, $completion) {
            $participation = EventParticipation::lockForUpdate()->find(EventEngine::participation($e, $user->id)->id);
            $collected = $participation->collected ?? [];
            $day = $user->gameDate()->toDateString();
            $helps = collect($collected['secret_help'] ?? []);
            if ($helps->contains('completion_id', $completion->id)) {
                throw new GameException('Diese Aufgabe hast du schon verschenkt.');
            }
            if ($helps->where('date', $day)->count() >= ($def['secret_help']['per_day'] ?? 1)) {
                throw new GameException('Für heute hast du schon heimlich geholfen. Morgen wieder!');
            }
            $collected['secret_help'][] = ['for' => $for->id, 'for_name' => $for->name, 'completion_id' => $completion->id, 'date' => $day];
            $participation->forceFill(['collected' => $collected])->save();
            $this->engine->addPoints($e, $user, (float) ($def['secret_help']['points'] ?? 5));

            return ['ok' => true, 'message' => "Psst! {$for->name} erfährt es erst an Heiligabend."];
        });
    }

    /** Wandernder Händler: nur für Gold, jedes Angebot einmal pro Person. */
    public function merchantBuy(User $user, FamilyEvent $e, string $itemKey): array
    {
        $this->runningDefinition($user, $e, 'merchant');
        $offer = collect($e->progress['merchant'] ?? [])->firstWhere('item', $itemKey) ?? throw new GameException('Das hat der Händler nicht dabei.');
        $item = Item::where('key', $itemKey)->firstOrFail();

        return DB::transaction(function () use ($user, $e, $offer, $item) {
            $participation = EventParticipation::lockForUpdate()->find(EventEngine::participation($e, $user->id)->id);
            $collected = $participation->collected ?? [];
            if (in_array($item->key, $collected['bought'] ?? [], true)) {
                throw new GameException('Davon hast du schon eins gekauft.');
            }
            $stats = UserStats::lockForUpdate()->findOrFail($user->id);
            if ($stats->gold < $offer['price']) {
                throw new GameException("Dafür brauchst du {$offer['price']} Gold.");
            }
            Stock::ensureRoom($user->id, [$item->id]);
            Progression::changeGold($stats, -$offer['price']);
            $stats->save();
            Stock::add($user->id, $item->id, 1);
            $collected['bought'][] = $item->key;
            $participation->forceFill(['collected' => $collected])->save();

            return ['item' => $item->only(['key', 'name', 'icon']), 'gold' => $stats->gold];
        });
    }

    /** Geburtstag: Wunsch-Joker setzt eine eigene Daily heute aus (zählt als erledigt, ohne Belohnung). */
    public function joker(User $user, FamilyEvent $e, int $assignmentId): array
    {
        $def = $this->runningDefinition($user, $e, 'joker');
        if (! ($def['joker'] ?? false) || $e->subject_user_id !== $user->id) {
            throw new GameException('Den Wunsch-Joker hat das Geburtstagskind.');
        }
        $assignment = TaskAssignment::with('task')->where('user_id', $user->id)->find($assignmentId);
        if ($assignment === null || $assignment->task->type !== TaskType::Daily || $assignment->completed) {
            throw new GameException('Den Joker kannst du für eine offene Daily nehmen.');
        }

        return DB::transaction(function () use ($user, $e, $assignment) {
            $participation = EventParticipation::lockForUpdate()->find(EventEngine::participation($e, $user->id)->id);
            $collected = $participation->collected ?? [];
            if (isset($collected['joker'])) {
                throw new GameException('Der Wunsch-Joker ist schon eingelöst.');
            }
            $assignment->forceFill(['completed' => true, 'completed_at' => now()])->save();
            $collected['joker'] = $assignment->id;
            $participation->forceFill(['collected' => $collected])->save();

            return ['ok' => true, 'message' => "„{$assignment->task->title}“ hat heute Geburtstagspause."];
        });
    }

    /** Rollentausch-Tag: Jedes Kind weist jedem Elternteil eine Aufgabe zu. */
    public function assignToParent(User $user, FamilyEvent $e, int $parentId, string $title): Task
    {
        $this->runningDefinition($user, $e, 'role_swap');
        if ($user->isParent()) {
            throw new GameException('Heute verteilen die Kinder die Aufgaben.');
        }
        $parent = User::where('family_id', $user->family_id)->where('family_role', FamilyRole::Parent->value)->find($parentId)
            ?? throw new GameException('Aufgaben verteilen kannst du heute an deine Eltern.');

        return DB::transaction(function () use ($user, $e, $parent, $title) {
            $participation = EventParticipation::lockForUpdate()->find(EventEngine::participation($e, $user->id)->id);
            $collected = $participation->collected ?? [];
            if (in_array($parent->id, $collected['assigned'] ?? [], true)) {
                throw new GameException("{$parent->name} hat von dir schon eine Aufgabe bekommen.");
            }
            $task = app(TaskService::class)->create($user, [
                'scope' => 'family', 'type' => TaskType::Todo->value, 'title' => $title, 'notes' => "Rollentausch-Tag: von {$user->name}",
                'category' => 'others', 'difficulty' => 1.0, 'assignment_mode' => 'assigned', 'assignees' => [$parent->id],
                'approval_mode' => 'none', 'star_reward' => 0, 'due_date' => $user->gameDate()->toDateString(),
            ]);
            $collected['assigned'][] = $parent->id;
            $participation->forceFill(['collected' => $collected])->save();

            return $task;
        });
    }

    /** Fasching: einen Tag lang eine andere Klasse ausprobieren, ohne den Monatswechsel zu verbrauchen. */
    public function classTrial(User $user, FamilyEvent $e, string $classKey): array
    {
        $this->runningDefinition($user, $e, 'class_trial');
        if (ClassCatalog::get($classKey) === null) {
            throw new GameException('Diese Klasse gibt es nicht.');
        }

        return DB::transaction(function () use ($user, $e, $classKey) {
            $participation = EventParticipation::lockForUpdate()->find(EventEngine::participation($e, $user->id)->id);
            $collected = $participation->collected ?? [];
            if (isset($collected['class_trial'])) {
                throw new GameException('Den Probetag hast du schon genutzt.');
            }
            $stats = UserStats::lockForUpdate()->findOrFail($user->id);
            $until = now()->addDay();
            $collected['class_trial'] = ['original' => $stats->class_key, 'class' => $classKey, 'until' => $until->toIso8601String()];
            $stats->forceFill(['class_key' => $classKey])->save();
            $participation->forceFill(['collected' => $collected])->save();

            return ['class' => $classKey, 'until' => $until->toIso8601String()];
        });
    }

    /** Eltern starten ein Event von Hand (auch zum Ausprobieren auf Staging). */
    public function startManually(User $parent, string $key, ?int $subjectId = null): FamilyEvent
    {
        $this->ensureParent($parent);
        $def = EventCatalog::get($key) ?? throw new GameException('Dieses Event gibt es nicht.');
        if (($def['personal'] ?? false) || $key === 'welcome_party') {
            $subject = User::where('family_id', $parent->family_id)->find($subjectId) ?? throw new GameException('Für wen soll das Event sein?');
            $subjectId = $subject->id;
        }

        return $this->trigger->consider($parent->family_id, $key, ['manual' => true, 'subject_user_id' => $subjectId, 'source' => 'manual'])
            ?? throw new GameException('Das Event läuft schon oder kann gerade nicht starten.');
    }

    // ---------- Einstellungen ----------

    public function settings(User $parent): array
    {
        $s = FamilyEventSetting::for($parent->family_id);
        $tz = $parent->family?->timezone ?? 'Europe/Berlin';
        $year = CarbonImmutable::now($tz)->year;

        return [
            'country' => $s->country,
            'subdivision' => $s->subdivision,
            'fun_events' => $s->fun_events,
            'weather_source' => $s->weather_source,
            'weather_lat' => $s->weather_lat,
            'weather_lon' => $s->weather_lon,
            'events' => collect(EventCatalog::all())->map(fn ($e) => [
                'key' => $e['key'], 'name' => $e['name'], 'icon' => $e['icon'], 'category' => $e['category'],
                'description' => $e['description'], 'enabled' => $s->enabled($e['key']), 'default_enabled' => $e['default_enabled'],
            ])->values(),
            'fun' => collect(EventCatalog::fun())->map(fn ($f) => [
                'key' => $f['key'], 'name' => $f['name'], 'icon' => $f['icon'],
                'enabled' => $s->funEnabled($f['key'], $f['default_enabled'] ?? true), 'default_enabled' => $f['default_enabled'] ?? true,
            ])->values(),
            // Vorschau des Jahreskalenders für die Region
            'calendar' => collect(EventCatalog::calendar())->flatMap(fn ($e) => collect([$year, $year + 1])->map(function ($y) use ($e, $s, $parent) {
                $w = EventCalendar::window($e, $y, $s->country, $s->subdivision, $parent->family_id);

                return $w ? ['key' => $e['key'], 'name' => $e['name'], 'icon' => $e['icon'], 'from' => $w[0]->toDateString(), 'to' => $w[1]->toDateString()] : null;
            }))->filter()->sortBy('from')->values(),
            'school_holidays' => HolidayPeriod::where('family_id', $parent->family_id)->where('type', 'school')->orderBy('starts_on')->get()
                ->map(fn (HolidayPeriod $h) => ['name' => $h->name, 'starts_on' => $h->starts_on->toDateString(), 'ends_on' => $h->ends_on->toDateString()]),
            'member_dates' => MemberDate::whereIn('user_id', User::where('family_id', $parent->family_id)->select('id'))->get()
                ->map(fn (MemberDate $d) => ['user_id' => $d->user_id, 'type' => $d->type, 'month' => $d->month, 'day' => $d->day]),
        ];
    }

    public function updateSettings(User $parent, array $data): array
    {
        $this->ensureParent($parent);
        $s = FamilyEventSetting::for($parent->family_id);
        $regionChanged = (isset($data['country']) && $data['country'] !== $s->country) || (array_key_exists('subdivision', $data) && $data['subdivision'] !== $s->subdivision);

        if (isset($data['events'])) {
            $disabled = $s->disabled_events ?? [];
            $enabled = $s->enabled_events ?? [];
            foreach ($data['events'] as $key => $on) {
                $def = EventCatalog::get($key) ?? throw new GameException("Unbekanntes Event „{$key}“.");
                if ($def['default_enabled']) {
                    $disabled = $on ? array_diff($disabled, [$key]) : array_unique([...$disabled, $key]);
                } else {
                    $enabled = $on ? array_unique([...$enabled, $key]) : array_diff($enabled, [$key]);
                }
            }
            $s->disabled_events = array_values($disabled);
            $s->enabled_events = array_values($enabled);
        }
        if (isset($data['fun'])) {
            $disabled = $s->disabled_fun_events ?? [];
            $enabled = $s->enabled_fun_events ?? [];
            foreach ($data['fun'] as $key => $on) {
                $def = EventCatalog::fun()[$key] ?? throw new GameException("Unbekanntes Zufallsereignis „{$key}“.");
                if ($def['default_enabled'] ?? true) {
                    $disabled = $on ? array_diff($disabled, [$key]) : array_unique([...$disabled, $key]);
                } else {
                    $enabled = $on ? array_unique([...$enabled, $key]) : array_diff($enabled, [$key]);
                }
            }
            $s->disabled_fun_events = array_values($disabled);
            $s->enabled_fun_events = array_values($enabled);
        }

        $s->fill(collect($data)->only(['country', 'subdivision', 'fun_events', 'weather_source'])->all());
        foreach (['weather_lat', 'weather_lon'] as $field) {
            if (array_key_exists($field, $data)) {
                $s->{$field} = $data[$field] === null ? null : round((float) $data[$field], 1); // §7: grob gerundet
            }
        }
        $s->family_id = $parent->family_id;
        $s->save();

        if (array_key_exists('school_holidays', $data)) {
            HolidayPeriod::where('family_id', $parent->family_id)->delete();
            foreach ($data['school_holidays'] ?? [] as $h) {
                HolidayPeriod::create([
                    'country' => $s->country, 'subdivision' => $s->subdivision, 'type' => 'school', 'name' => $h['name'],
                    'starts_on' => $h['starts_on'], 'ends_on' => $h['ends_on'], 'source' => 'manual', 'family_id' => $parent->family_id,
                ]);
            }
        }

        // Abgeschaltete Events verschwinden aus der Planung, eine neue Region plant neu
        FamilyEvent::where('family_id', $parent->family_id)->where('status', 'scheduled')->get()
            ->reject(fn (FamilyEvent $e) => $s->enabled($e->event_key))->each->delete();
        if ($regionChanged || array_key_exists('school_holidays', $data)) {
            $this->planner->replan($parent->family);
        } else {
            $this->planner->planCalendar($parent->family, CarbonImmutable::now($parent->family->timezone ?: 'Europe/Berlin')->startOfDay());
        }

        return $this->settings($parent);
    }

    /** Geburtstag nur auf Wunsch der Eltern, ohne Jahr (§7). */
    public function updateDates(User $parent, User $member, ?array $birthday): ?MemberDate
    {
        $this->ensureParent($parent);
        if (! $member->sameFamilyAs($parent)) {
            throw new GameException('Das ist nicht deine Familie.');
        }
        if ($birthday === null) {
            MemberDate::where('user_id', $member->id)->where('type', 'birthday')->delete();

            return null;
        }
        if (! checkdate((int) $birthday['month'], (int) $birthday['day'], 2024)) {
            throw new GameException('Dieses Datum gibt es nicht.');
        }

        return MemberDate::updateOrCreate(['user_id' => $member->id, 'type' => 'birthday'], ['month' => $birthday['month'], 'day' => $birthday['day']]);
    }

    // ---------- intern ----------

    public static function visibleTo(FamilyEvent $e, User $user): bool
    {
        $def = $e->definition();
        if ($def === [] || $e->family_id !== $user->family_id) {
            return false;
        }

        // Geburtstag sieht die ganze Familie, „Willkommen zurück“ nur die Person selbst
        return ! ($def['personal'] ?? false) || $e->subject_user_id === $user->id || ($def['greetings'] ?? false);
    }

    public static function text(array $def, User $user): string
    {
        $variant = $user->ageGroup()->value === 'child' ? 'child' : 'teen';

        return $def['variants'][$variant] ?? $def['description'];
    }

    public static function timeProgress(FamilyEvent $e): float
    {
        $total = max(1, $e->starts_at->diffInSeconds($e->ends_at));

        return round(min(1, max(0, $e->starts_at->diffInSeconds(now(), false) / $total)), 3);
    }

    private static function rewardsView(array $rewards): array
    {
        $items = Item::whereIn('key', array_keys($rewards['items'] ?? []))->get()->keyBy('key');

        return [
            'gold' => $rewards['gold'] ?? 0,
            'xp' => $rewards['xp'] ?? 0,
            'badge' => $rewards['badge'] ?? null,
            'items' => collect($rewards['items'] ?? [])->map(fn ($q, $k) => ['key' => $k, 'name' => $items[$k]->name ?? $k, 'icon' => $items[$k]->icon ?? '🎁', 'quantity' => $q])->values(),
        ];
    }

    private function runningDefinition(User $user, FamilyEvent $e, string $feature): array
    {
        if ($e->family_id !== $user->family_id || ! in_array($e->status, FamilyEvent::RUNNING, true) || $e->ends_at->lte(now())) {
            throw new GameException('Dieses Event läuft gerade nicht.');
        }
        $def = $e->definition();
        $has = match ($feature) {
            'role_swap' => $e->event_key === 'role_swap',
            'joker', 'class_trial' => ($def[$feature] ?? false) === true,
            default => isset($def[$feature]),
        };
        if (! $has) {
            throw new GameException('Das gibt es bei diesem Event nicht.');
        }

        return $def;
    }

    private function ensureParent(User $user): void
    {
        if (! $user->isParent()) {
            throw new GameException('Das stellen die Eltern ein.');
        }
    }

    private function doorAvailable(FamilyEvent $e, CarbonImmutable $today, int $day): bool
    {
        $door = CarbonImmutable::create($e->ends_at->year, 12, $day, 0, 0, 0, $today->timezone);
        if ($e->ends_at->month === 1) {
            $door = $door->subYear();
        }

        return $door->lte($today);
    }

    private function adventDoors(FamilyEvent $e, User $user, array $collected): array
    {
        $today = $user->gameDate();
        $items = Item::whereIn('key', array_values($collected['advent'] ?? []))->get()->keyBy('key');

        return collect(range(1, 24))->map(fn ($d) => [
            'day' => $d,
            'available' => $this->doorAvailable($e, $today, $d),
            'opened' => isset($collected['advent'][$d]) ? ($items[$collected['advent'][$d]] ?? null)?->only(['key', 'name', 'icon']) : null,
        ])->all();
    }

    private function secretHelpView(FamilyEvent $e, User $viewer, array $collected): array
    {
        $def = $e->definition();
        $revealOn = CarbonImmutable::createFromFormat('!Y-m-d', $e->ends_at->subDay()->year.'-'.$def['secret_help']['reveal']);
        $revealed = $e->status === 'finished' || $viewer->gameDate()->gte($revealOn);

        $received = EventParticipation::with('user:id,name')->where('family_event_id', $e->id)->where('user_id', '!=', $viewer->id)->get()
            ->flatMap(fn (EventParticipation $p) => collect($p->collected['secret_help'] ?? [])->where('for', $viewer->id)->map(fn () => $p->user?->name));

        return [
            'mine' => collect($collected['secret_help'] ?? [])->map(fn ($h) => ['for' => $h['for_name'], 'date' => $h['date']])->values(),
            'received_count' => $received->count(),
            // Geheim bis Heiligabend (§12.8)
            'received_from' => $revealed ? $received->unique()->values() : null,
            'reveal_on' => $revealOn->toDateString(),
        ];
    }

    /** Jahresrückblick der Familie (Raunächte, Jubiläum). */
    private function yearReview(int $familyId, $until): array
    {
        $from = CarbonImmutable::instance($until)->subYear();
        $members = User::where('family_id', $familyId)->pluck('id');

        return [
            'from' => $from->toDateString(),
            'tasks_done' => TaskCompletion::whereIn('user_id', $members)->where('direction', 'up')->where('created_at', '>=', $from)
                ->whereNotIn('status', [CompletionStatus::Undone->value, CompletionStatus::Rejected->value])->count(),
            'bosses_defeated' => Quest::where('family_id', $familyId)->where('type', 'boss')->where('status', 'completed')->where('completed_at', '>=', $from)->count(),
            'quests_completed' => Quest::where('family_id', $familyId)->where('status', 'completed')->where('completed_at', '>=', $from)->count(),
            'build_projects' => BuildProject::where('family_id', $familyId)->where('status', 'done')->where('completed_at', '>=', $from)->count(),
            'achievements' => UserAchievement::whereIn('user_id', $members)->where('created_at', '>=', $from)->count(),
            'events' => FamilyEvent::where('family_id', $familyId)->where('status', 'finished')->where('starts_at', '>=', $from)->count(),
        ];
    }
}
