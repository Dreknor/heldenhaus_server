<?php

namespace App\Domain\Quests;

use App\Domain\Gameplay\Formulas;
use App\Domain\Gameplay\GameException;
use App\Domain\Gameplay\Progression;
use App\Domain\Social\FeedService;
use App\Enums\FamilyRole;
use App\Models\Item;
use App\Models\Quest;
use App\Models\QuestContribution;
use App\Models\Task;
use App\Models\TaskCompletion;
use App\Models\User;
use App\Models\UserItem;
use App\Models\UserStats;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Quest-System: Boss-Quests (Lebensleiste, Schwächen, Wut) und Sammel-Quests,
 * jeweils gemeinsam für die Familie oder solo für eine Person. Gleichzeitig laufen
 * höchstens eine Familien-Quest und eine Solo-Quest pro Person.
 */
class QuestService
{
    public const OFFERS = 3;

    public function __construct(private readonly FeedService $feed) {}

    /** @return array<string, array> */
    public static function catalog(): array
    {
        return collect(json_decode(file_get_contents(database_path('content/quests.json')), true, flags: JSON_THROW_ON_ERROR))
            ->keyBy('key')
            ->all();
    }

    // ---------- Abfragen ----------

    public function activeFamily(?int $familyId): ?Quest
    {
        if (! $familyId) {
            return null;
        }
        $this->expire($familyId);

        return Quest::where('family_id', $familyId)->where('scope', 'family')->where('status', 'active')->latest('id')->first();
    }

    public function activeSolo(User $user): ?Quest
    {
        if (! $user->family_id) {
            return null;
        }
        $this->expire($user->family_id);

        return Quest::where('user_id', $user->id)->where('scope', 'solo')->where('status', 'active')->latest('id')->first();
    }

    /** @return Collection<int, Quest> alle Quests, zu denen die Aufgaben dieser Person beitragen */
    public function activeFor(User $user): Collection
    {
        return collect([$this->activeFamily($user->family_id), $this->activeSolo($user)])->filter()->values();
    }

    /**
     * Quest-Tafel: wöchentlich wechselnde Angebote. Die zuletzt gespielten Quests werden
     * ausgelassen, damit es nicht immer derselbe Boss ist.
     *
     * @return list<array>
     */
    public function offers(User $user, string $scope): array
    {
        $level = $scope === 'family' ? $this->familyLevel($user) : (int) $user->stats()->value('level');

        $recent = Quest::where('family_id', $user->family_id)->where('scope', $scope)
            ->when($scope === 'solo', fn ($q) => $q->where('user_id', $user->id))
            ->latest('id')->limit(2)->pluck('quest_key')->all();

        $eligible = collect(self::catalog())
            ->filter(fn ($q) => in_array($scope, $q['scopes'], true) && $q['min_level'] <= $level)
            ->reject(fn ($q) => in_array($q['key'], $recent, true));

        // Pro Woche stabil gemischt (Familie bzw. Person als Startwert)
        $seed = crc32($scope.'|'.($scope === 'family' ? $user->family_id : $user->id).'|'.now()->format('o-W'));
        $picked = $eligible->sortBy(fn ($q) => crc32($seed.$q['key']))->take(self::OFFERS);

        return $picked->map(fn ($q) => [...$q, 'goal' => $this->scaledGoal($q, $scope, $user)])->values()->all();
    }

    // ---------- Starten & Abbrechen ----------

    public function start(User $user, string $key, string $scope, ?string $rewardText = null): Quest
    {
        $definition = self::catalog()[$key] ?? throw new GameException('Diese Quest gibt es nicht.');

        if (! in_array($scope, $definition['scopes'], true)) {
            throw new GameException($scope === 'family' ? 'Diese Quest ist nur für Solo-Abenteuer.' : 'Diese Quest geht nur gemeinsam als Familie.');
        }
        if ($scope === 'family' && ! $user->isParent()) {
            throw new GameException('Familien-Quests starten die Eltern.');
        }

        $level = $scope === 'family' ? $this->familyLevel($user) : (int) $user->stats()->value('level');
        if ($definition['min_level'] > $level) {
            throw new GameException("Diese Quest gibt es erst ab Level {$definition['min_level']}.");
        }

        $running = $scope === 'family' ? $this->activeFamily($user->family_id) : $this->activeSolo($user);
        if ($running) {
            throw new GameException($scope === 'family' ? 'Es läuft schon eine Familien-Quest.' : 'Du hast schon eine Solo-Quest.');
        }

        $quest = Quest::create([
            'family_id' => $user->family_id,
            'user_id' => $scope === 'solo' ? $user->id : null,
            'scope' => $scope,
            'type' => $definition['type'],
            'quest_key' => $key,
            'name' => $definition['name'],
            'icon' => $definition['icon'],
            'goal' => $this->scaledGoal($definition, $scope, $user),
            'progress' => 0,
            'status' => 'active',
            'data' => array_filter([
                'description' => $definition['description'] ?? null,
                'weakness' => $definition['weakness'] ?? null,
                'resist' => $definition['resist'] ?? null,
                'rage' => $definition['rage'] ?? null,
                'categories' => $definition['categories'] ?? null,
                'item_name' => $definition['item_name'] ?? null,
                'item_icon' => $definition['item_icon'] ?? null,
            ]),
            'rewards' => $definition['rewards'] ?? [],
            'reward_text' => $scope === 'family' ? $rewardText : null,
            'ends_at' => now()->addDays($definition['days']),
            'started_by' => $user->id,
        ]);

        $who = $scope === 'family' ? 'Die Familie' : $user->name;
        $this->feed->post($user->family_id, $user, 'quest_started', $quest->icon, "{$who} startet die Quest „{$quest->name}“");

        return $quest;
    }

    public function abandon(User $user, Quest $quest): void
    {
        $allowed = $quest->scope === 'solo' ? $quest->user_id === $user->id : ($user->isParent() && $quest->family_id === $user->family_id);
        if (! $allowed || $quest->status !== 'active') {
            throw new GameException('Diese Quest kannst du nicht abbrechen.');
        }

        $quest->update(['status' => 'abandoned']);
    }

    // ---------- Fortschritt ----------

    /** Beitrag eines positiven Abschlusses zu allen laufenden Quests der Person. Gibt die Summe zurück. */
    public function progress(TaskCompletion $completion, User $user, Task $task, float $delta, float $bossMultiplier = 1.0, int $strength = 0): float
    {
        $total = 0.0;

        foreach ($this->activeFor($user) as $quest) {
            $amount = $quest->isBoss()
                ? Formulas::bossDamage($delta, $task->difficulty, $strength) * $bossMultiplier * $this->categoryFactor($quest, $task)
                : $this->collected($quest, $task);

            if ($amount <= 0) {
                continue;
            }

            $total += DB::transaction(function () use ($quest, $completion, $user, $amount) {
                $quest = Quest::lockForUpdate()->find($quest->id);
                if ($quest->status !== 'active') {
                    return 0.0;
                }

                $applied = round(min($amount, $quest->remaining()), 2);
                $quest->progress = round($quest->progress + $applied, 2);
                $quest->contributions()->create(['user_id' => $user->id, 'task_completion_id' => $completion->id, 'amount' => $applied]);

                if ($quest->remaining() <= 0) {
                    $this->finish($quest);
                }
                $quest->save();

                return $applied;
            });
        }

        if ($total > 0) {
            $completion->forceFill(['quest_progress' => round($total, 2)])->save();
        }

        return $total;
    }

    /** Rückgängig/Ablehnung: Beiträge zurücknehmen, solange die Quest läuft. */
    public function revert(TaskCompletion $completion): void
    {
        $contributions = QuestContribution::where('task_completion_id', $completion->id)->get();

        foreach ($contributions as $c) {
            Quest::whereKey($c->quest_id)->where('status', 'active')->decrement('progress', $c->amount);
            $c->delete();
        }

        if ($contributions->isNotEmpty()) {
            $completion->forceFill(['quest_progress' => 0])->save();
        }
    }

    /** Verpasste Dailies heilen Bosse (Familien-Boss und eigener Solo-Boss); „Wut“ verstärkt das. */
    public function healOnMissed(User $user, int $missed): void
    {
        if ($missed <= 0) {
            return;
        }

        foreach ($this->activeFor($user) as $quest) {
            if ($quest->isBoss()) {
                $heal = $missed * config('game.boss_heal_per_missed_daily') * (float) ($quest->data['rage'] ?? 1);
                $quest->update(['progress' => max(0, $quest->progress - $heal)]);
            }
        }
    }

    public static function present(?Quest $quest): ?array
    {
        if ($quest === null) {
            return null;
        }

        $categories = config('game.categories');

        return [
            'id' => $quest->id,
            'scope' => $quest->scope,
            'type' => $quest->type,
            'key' => $quest->quest_key,
            'name' => $quest->name,
            'icon' => $quest->icon,
            'description' => $quest->data['description'] ?? null,
            'goal' => $quest->goal,
            'progress' => round($quest->progress, 1),
            'remaining' => round($quest->remaining(), 1),
            'weakness' => isset($quest->data['weakness']) ? $categories[$quest->data['weakness']] ?? null : null,
            'resist' => isset($quest->data['resist']) ? $categories[$quest->data['resist']] ?? null : null,
            'rage' => $quest->data['rage'] ?? null,
            'categories' => array_map(fn ($c) => $categories[$c] ?? $c, $quest->data['categories'] ?? []),
            'item_name' => $quest->data['item_name'] ?? null,
            'item_icon' => $quest->data['item_icon'] ?? null,
            'rewards' => $quest->rewards,
            'reward_text' => $quest->reward_text,
            'status' => $quest->status,
            'ends_at' => $quest->ends_at->toIso8601String(),
            // Beiträge ohne Rangliste (Kooperation statt Konkurrenz, Konzept §1)
            'contributions' => $quest->scope === 'family'
                ? $quest->contributions()->selectRaw('user_id, sum(amount) as amount')->groupBy('user_id')
                    ->with('user:id,name,avatar_color')->get()
                    ->map(fn ($c) => ['name' => $c->user?->name, 'avatar_color' => $c->user?->avatar_color, 'amount' => round((float) $c->amount, 1)])
                : [],
        ];
    }

    // ---------- intern ----------

    private function finish(Quest $quest): void
    {
        $quest->status = 'completed';
        $quest->completed_at = now();

        $participants = $quest->scope === 'solo'
            ? collect([$quest->user_id])
            : User::where('family_id', $quest->family_id)->where('family_role', '!=', FamilyRole::Guest->value)->pluck('id');

        $rewards = $quest->rewards ?? [];
        $item = isset($rewards['item']) ? Item::where('key', $rewards['item'])->where('active', true)->first() : null;

        foreach ($participants as $userId) {
            $stats = UserStats::lockForUpdate()->find($userId);
            if ($stats === null) {
                continue;
            }
            Progression::changeGold($stats, (float) ($rewards['gold'] ?? 0));
            Progression::gainXp($stats, (int) ($rewards['xp'] ?? 0));
            $stats->save();

            if ($item) {
                UserItem::firstOrCreate(['user_id' => $userId, 'item_id' => $item->id])->increment('quantity');
            }
        }

        $winner = $quest->scope === 'solo' ? $quest->user?->name : 'Die Familie';
        $verb = $quest->isBoss() ? "hat {$quest->name} besiegt" : "hat die Quest „{$quest->name}“ geschafft";
        $extra = $quest->reward_text ? " – {$quest->reward_text}!" : '!';
        $this->feed->post($quest->family_id, $quest->user, 'quest_completed', $quest->icon, "Sieg! {$winner} {$verb}{$extra}");
    }

    private function expire(int $familyId): void
    {
        Quest::where('family_id', $familyId)->where('status', 'active')->where('ends_at', '<=', now())->update(['status' => 'expired']);
    }

    /** Schwäche ×1,5, Resistenz ×0,5 je nach Aufgabenkategorie. */
    private function categoryFactor(Quest $quest, Task $task): float
    {
        return match ($task->category) {
            $quest->data['weakness'] ?? null => 1.5,
            $quest->data['resist'] ?? null => 0.5,
            default => 1.0,
        };
    }

    /** Sammel-Quests: nur passende Kategorien; schwere Aufgaben bringen 2 Stück. */
    private function collected(Quest $quest, Task $task): float
    {
        $categories = $quest->data['categories'] ?? [];
        if ($categories && ! in_array($task->category, $categories, true)) {
            return 0;
        }

        return $task->difficulty >= 2 ? 2 : 1;
    }

    /** Familien-Quests wachsen mit der Zahl der Mitspieler. */
    private function scaledGoal(array $definition, string $scope, User $user): float
    {
        $base = (float) ($definition['type'] === 'boss' ? $definition['hp'] : $definition['goal']);
        if ($scope === 'solo') {
            return $base;
        }

        $players = User::where('family_id', $user->family_id)->where('family_role', '!=', FamilyRole::Guest->value)->count();

        return round($base * max(1, $players / 2));
    }

    private function familyLevel(User $user): int
    {
        return (int) UserStats::whereIn('user_id', User::where('family_id', $user->family_id)->select('id'))->max('level');
    }
}
