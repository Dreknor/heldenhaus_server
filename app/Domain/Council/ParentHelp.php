<?php

namespace App\Domain\Council;

use App\Domain\Gameplay\GameException;
use App\Domain\Tasks\TaskService;
use App\Enums\CompletionStatus;
use App\Enums\FamilyRole;
use App\Models\Task;
use App\Models\TaskCompletion;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Hilfen für Eltern (§14.9): Startassistent mit Aufgaben-Vorlagen, Hinweis „höchstens eine neue Aufgabe pro Woche“,
 * Preis-Hilfe für Belohnungen, Sterne-Polster und die Eltern-Anleitung.
 */
class ParentHelp
{
    public const STARTER_MIN = 3;

    public const STARTER_MAX = 5;

    /** Zeitraum für den Sterne-Schnitt der Preis-Hilfe. */
    public const RATE_DAYS = 14;

    /** Sterne-Polster: mehr Sterne, als ein Kind in so vielen Tagen verdient. */
    public const CUSHION_DAYS = 28;

    public const CUSHION_MIN = 10;

    /** Die sechs Karten der Eltern-Anleitung. */
    public const GUIDE = [
        ['icon' => '🌱', 'title' => 'Wenige Aufgaben, altersgerecht', 'text' => 'Startet mit 3 bis 5 Aufgaben, die zum Alter passen. Danach höchstens eine neue pro Woche. Weniger ist am Anfang mehr.'],
        ['icon' => '💬', 'title' => 'Den Einsatz loben, nicht die Person', 'text' => '„Du bist drangeblieben“ wirkt stärker als „Du bist toll“. Lob für Mühe macht Mut für das nächste Mal.'],
        ['icon' => '⏱️', 'title' => 'Freigaben zügig erledigen', 'text' => 'Bestätigt erledigte Aufgaben möglichst innerhalb eines Tages. So spürt euer Kind, dass sich der Einsatz lohnt.'],
        ['icon' => '⭐', 'title' => 'Sterne nie als Strafe wegnehmen', 'text' => 'Verdiente Sterne bleiben. Wer Sterne wegnimmt, macht aus einer Belohnung eine Drohung.'],
        ['icon' => '🗳️', 'title' => 'Kinder mitentscheiden lassen', 'text' => 'Kinder schlagen Belohnungen und Aufgaben vor und stimmen über die nächste Familien-Quest ab. Im Familienrat entscheidet ihr gemeinsam.'],
        ['icon' => '🛡️', 'title' => 'Selbst mitspielen und Vorbild sein', 'text' => 'Legt eigene Aufgaben an und hakt sie sichtbar ab. Kinder machen nach, was sie bei euch sehen.'],
    ];

    public function __construct(private readonly TaskService $tasks) {}

    // ---------- Startassistent ----------

    /**
     * 3–5 Vorlagen für ein neues Kind anlegen. Vorlagen mit Sternen werden Familienaufgaben (Sterne gibt es nur
     * für bestätigte Familienaufgaben, §3.4), die übrigen persönliche Aufgaben des Kindes.
     *
     * @param  list<string>  $keys
     * @return list<Task>
     */
    public function starterTasks(User $parent, User $child, array $keys): array
    {
        if (! $parent->isParent() || ! $parent->sameFamilyAs($child) || $child->family_role !== FamilyRole::Child) {
            throw new GameException('Den Startassistenten nutzen Eltern für ihre Kinder.');
        }
        if ($child->starter_tasks_at !== null) {
            throw new GameException('Der Startassistent ist für '.$child->name.' schon erledigt.');
        }
        $keys = array_values(array_unique($keys));
        if (count($keys) < self::STARTER_MIN || count($keys) > self::STARTER_MAX) {
            throw new GameException('Wählt 3 bis 5 Aufgaben. Weniger ist am Anfang mehr.');
        }

        return DB::transaction(function () use ($parent, $child, $keys) {
            $created = [];
            foreach ($keys as $key) {
                $template = TaskTemplates::get($key) ?? throw new GameException("Unbekannte Vorlage „{$key}“.");
                $data = TaskTemplates::taskData($template);

                if ((int) $template['stars'] > 0) {
                    $task = $this->tasks->create($parent, $data + [
                        'scope' => 'family',
                        'assignment_mode' => 'assigned',
                        'approval_mode' => 'required',
                        'star_reward' => (int) $template['stars'],
                        'assignees' => [$child->id],
                    ]);
                } else {
                    $task = $this->tasks->create($child, $data + ['scope' => 'personal']);
                    $task->update(['created_by' => $parent->id]);
                }
                $created[] = $task;
            }
            $child->forceFill(['starter_tasks_at' => now()])->save();

            return $created;
        });
    }

    /** Wie viele neue Aufgaben ein Kind in den letzten 7 Tagen bekommen hat (ohne Startassistent und eigene). */
    public static function newTasksThisWeek(User $child): int
    {
        $since = now()->subDays(7);
        if ($child->starter_tasks_at && $child->starter_tasks_at->gt($since)) {
            $since = $child->starter_tasks_at;
        }

        return Task::whereHas('assignments', fn ($q) => $q->where('user_id', $child->id))
            ->where('created_at', '>', $since)
            ->where('created_by', '!=', $child->id)
            ->whereNull('archived_at')
            ->whereNull('challenge_id')
            ->count();
    }

    /**
     * Hinweise „höchstens eine neue Aufgabe pro Woche“ für die Kinder einer Familie.
     *
     * @param  list<int>|null  $only  nur diese Personen prüfen
     * @return list<array{user_id: int, name: string, count: int, text: string}>
     */
    public static function loadHints(int $familyId, ?array $only = null): array
    {
        return self::children($familyId)
            ->when($only !== null, fn ($c) => $c->whereIn('id', $only))
            ->map(fn (User $c) => ['child' => $c, 'count' => self::newTasksThisWeek($c)])
            ->filter(fn ($r) => $r['count'] > 1)
            ->map(fn ($r) => [
                'user_id' => $r['child']->id,
                'name' => $r['child']->name,
                'count' => $r['count'],
                'text' => "{$r['child']->name} hat diese Woche schon {$r['count']} neue Aufgaben bekommen. Höchstens eine neue pro Woche hilft beim Dranbleiben.",
            ])->values()->all();
    }

    // ---------- Preis-Hilfe und Sterne-Polster ----------

    /** Sterne-Schnitt pro Tag der letzten 14 Tage je Kind. */
    public static function starRates(int $familyId): Collection
    {
        $children = self::children($familyId);
        $sums = TaskCompletion::whereIn('user_id', $children->pluck('id'))
            ->whereIn('status', [CompletionStatus::Approved->value, CompletionStatus::Auto->value])
            ->where('created_at', '>=', now()->subDays(self::RATE_DAYS))
            ->selectRaw('user_id, sum(stars) as stars')->groupBy('user_id')->pluck('stars', 'user_id');

        return $children->map(fn (User $c) => [
            'id' => $c->id,
            'name' => $c->name,
            'stars' => (int) ($c->stats?->stars ?? 0),
            'stars_per_day' => round(((int) ($sums[$c->id] ?? 0)) / self::RATE_DAYS, 2),
        ])->values();
    }

    /** „etwa 3 Tage Familienaufgaben“ – wie lange ein Kind für einen Preis arbeitet. */
    public static function duration(int $stars, float $perDay): string
    {
        if ($perDay <= 0) {
            return 'noch keine Sterne in den letzten 14 Tagen';
        }
        $days = $stars / $perDay;

        return match (true) {
            $days < 1 => 'weniger als 1 Tag Familienaufgaben',
            $days < 1.5 => 'etwa 1 Tag Familienaufgaben',
            $days < 10 => 'etwa '.round($days).' Tage Familienaufgaben',
            $days < 63 => 'etwa '.round($days / 7).' Wochen Familienaufgaben',
            default => 'mehr als 2 Monate Familienaufgaben',
        };
    }

    /** @return list<array{id: int, name: string, stars_per_day: float, text: string}> */
    public static function priceHelp(int $familyId, int $stars): array
    {
        return self::starRates($familyId)
            ->map(fn ($r) => [...$r, 'text' => self::duration($stars, $r['stars_per_day'])])
            ->all();
    }

    /** Kinder mit mehr Sternen, als sie in 4 Wochen verdienen. */
    public static function starCushions(int $familyId): array
    {
        return self::starRates($familyId)
            ->filter(fn ($r) => $r['stars'] >= self::CUSHION_MIN && $r['stars'] > $r['stars_per_day'] * self::CUSHION_DAYS)
            ->map(fn ($r) => [
                'user_id' => $r['id'],
                'name' => $r['name'],
                'stars' => $r['stars'],
                'text' => "{$r['name']} hat {$r['stars']} Sterne gespart, mehr als in 4 Wochen dazukommen. Stellt ein größeres Ziel in den Shop oder lasst Sterne für Aufgaben langsam auslaufen (Ehrensache).",
            ])->values()->all();
    }

    // ---------- Eltern-Anleitung ----------

    public static function guideOffered(User $user): bool
    {
        return $user->isParent() && $user->parent_guide_seen_at === null;
    }

    public static function markGuideSeen(User $user): void
    {
        if ($user->parent_guide_seen_at === null) {
            $user->forceFill(['parent_guide_seen_at' => now()])->save();
        }
    }

    /** @return Collection<int, User> */
    private static function children(int $familyId): Collection
    {
        return User::with('stats')->where('family_id', $familyId)->where('family_role', FamilyRole::Child->value)->orderBy('name')->get();
    }
}
