<?php

namespace App\Domain\Classes;

use App\Domain\Achievements\AchievementService;
use App\Domain\Gameplay\Formulas;
use App\Domain\Gameplay\GameException;
use App\Domain\Gameplay\Progression;
use App\Domain\Social\FeedService;
use App\Enums\FamilyRole;
use App\Models\ActiveBuff;
use App\Models\DayLog;
use App\Models\Family;
use App\Models\FamilyDay;
use App\Models\RecoveryQuest;
use App\Models\SkillCast;
use App\Models\TaskAssignment;
use App\Models\User;
use App\Models\UserStats;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

class ClassService
{
    /** Tüftler nur mit aktiver Home-Assistant-Kopplung der Familie. */
    public static function familyHasHomeAssistant(?int $familyId): bool
    {
        return $familyId !== null && PersonalAccessToken::query()
            ->where('tokenable_type', User::class)
            ->whereIn('tokenable_id', User::where('family_id', $familyId)->select('id'))
            ->where('abilities', 'like', '%"ha"%')
            ->exists();
    }

    /** @return array{allowed: bool, reason: ?string, free: bool} */
    public function canChoose(User $user, string $classKey): array
    {
        $stats = $user->stats()->first();
        $class = ClassCatalog::get($classKey);

        if ($class === null) {
            return ['allowed' => false, 'reason' => 'Diese Klasse gibt es nicht.', 'free' => false];
        }

        if ($stats->level < ClassCatalog::unlockLevel($user)) {
            return ['allowed' => false, 'reason' => 'Klassen gibt es ab Level '.ClassCatalog::unlockLevel($user).'.', 'free' => false];
        }

        if (($class['requires_home_assistant'] ?? false) && ! self::familyHasHomeAssistant($user->family_id)) {
            return ['allowed' => false, 'reason' => 'Den Tüftler gibt es nur, wenn eure Familie Home Assistant verbunden hat.', 'free' => false];
        }

        // Tüftler ohne Home Assistant darf sofort kostenlos wechseln
        $lostHomeAssistant = $stats->class_key === 'tinkerer' && ! self::familyHasHomeAssistant($user->family_id);
        $cooldownOver = $stats->class_changed_at === null || $stats->class_changed_at->lte(now()->subDays(config('game.class_change_days')));

        if ($stats->class_key !== null && ! $cooldownOver && ! $lostHomeAssistant) {
            $next = $stats->class_changed_at->addDays(config('game.class_change_days'))->format('d.m.');

            return ['allowed' => false, 'reason' => "Die Klasse kannst du einmal im Monat wechseln – wieder ab dem {$next}.", 'free' => false];
        }

        return ['allowed' => true, 'reason' => null, 'free' => true];
    }

    public function choose(User $user, string $classKey): UserStats
    {
        $check = $this->canChoose($user, $classKey);
        if (! $check['allowed']) {
            throw new GameException($check['reason']);
        }

        return DB::transaction(function () use ($user, $classKey) {
            $stats = UserStats::lockForUpdate()->findOrFail($user->id);
            $stats->class_key = $classKey;
            $stats->class_changed_at = now();
            $stats->hp = min($stats->hp, Formulas::maxHp($stats));
            $stats->mp = min($stats->mp, Formulas::maxMp($stats));
            $stats->save();

            app(FeedService::class)->post($user->family_id, $user, 'class_chosen', ClassCatalog::get($classKey)['icon'], "{$user->name} ist jetzt ".ClassCatalog::get($classKey)['name'].'!');

            // Scharfe Buffs der alten Klasse verfallen
            ActiveBuff::where('user_id', $user->id)->where('armed', true)->delete();
            app(AchievementService::class)->evaluate($user, ['class_chosen']);

            return $stats;
        });
    }

    /** Aktive Fähigkeit wirken. */
    public function cast(User $user, string $skillKey, ?User $target = null): string
    {
        $skill = ClassCatalog::skill($skillKey);
        $stats = $user->stats()->first();

        if ($skill === null || $skill['kind'] !== 'active' || $skill['class'] !== $stats->class_key) {
            throw new GameException('Diese Fähigkeit hat deine Klasse nicht.');
        }

        $castsToday = SkillCast::where('user_id', $user->id)->where('skill_key', $skillKey)->where('created_at', '>=', now()->startOfDay())->count();
        if ($castsToday >= config('game.skill_casts_per_day')) {
            throw new GameException('Diese Fähigkeit hast du heute schon oft genug eingesetzt.');
        }

        if ($skillKey === 'healing_wave') {
            $target ??= $user;
            if (! $user->is($target) && ! $user->sameFamilyAs($target)) {
                throw new GameException('Du kannst nur deine Familie heilen.');
            }
        }

        return DB::transaction(function () use ($user, $skill, $skillKey, $target) {
            $stats = UserStats::lockForUpdate()->findOrFail($user->id);
            if ($stats->mp < $skill['mana']) {
                throw new GameException("Dafür brauchst du {$skill['mana']} Mana.");
            }
            $stats->mp = round($stats->mp - $skill['mana'], 1);
            $stats->save();

            SkillCast::create(['user_id' => $user->id, 'skill_key' => $skillKey, 'target_user_id' => $target?->id, 'mana_cost' => $skill['mana']]);

            return match ($skillKey) {
                'ancestral_wisdom' => $this->familyBuff($user, 'ancestral_wisdom', 0.2, $skill['hours'], 'Die ganze Familie bekommt 24 Stunden lang +20 % XP!'),
                'cheer' => $this->familyBuff($user, 'cheer', 0.15, $skill['hours'], 'Angefeuert! +15 % Boss-Schaden für alle.'),
                'mighty_blow' => $this->armedBuff($user, 'mighty_blow', 3, $skill['hours'], 'Mächtiger Hieb bereit – erledige eine schwere Aufgabe!'),
                'loot_run' => $this->armedBuff($user, 'loot_run', 2, $skill['hours'], 'Beutezug bereit – schnapp dir eine Aufgabe aus der Börse!'),
                'overload' => $this->armedBuff($user, 'overload', 2, $skill['hours'], 'Überlastung bereit – die nächste Home-Assistant-Aufgabe zählt doppelt!'),
                'healing_wave' => $this->heal($target, 15),
            };
        });
    }

    /**
     * Heiler, passiv „Zweite Chance“: einmal pro Woche eine verpasste Daily (eigene oder eines
     * Familienmitglieds) bis 24 h nach dem Tageswechsel glätten – HP zurück, Streak bleibt.
     */
    public function secondChance(User $healer, int $assignmentId): string
    {
        if ($healer->stats()->value('class_key') !== 'healer') {
            throw new GameException('Nur Heiler haben eine Zweite Chance.');
        }

        if (SkillCast::where('user_id', $healer->id)->where('skill_key', 'second_chance')->where('created_at', '>=', now()->startOfWeek())->exists()) {
            throw new GameException('Die Zweite Chance gibt es nur einmal pro Woche.');
        }

        $assignment = TaskAssignment::with('task', 'user')->findOrFail($assignmentId);
        if (! $healer->is($assignment->user) && ! $healer->sameFamilyAs($assignment->user)) {
            throw new GameException('Das ist keine Aufgabe aus deiner Familie.');
        }

        return DB::transaction(function () use ($healer, $assignment) {
            $log = DayLog::where('user_id', $assignment->user_id)
                ->where('created_at', '>=', now()->subDay())
                ->latest('id')
                ->lockForUpdate()
                ->get()
                ->first(fn (DayLog $l) => collect($l->missed ?? [])->contains('assignment_id', $assignment->id));

            if ($log === null) {
                throw new GameException('Diese Aufgabe wurde in den letzten 24 Stunden nicht verpasst.');
            }

            $entry = collect($log->missed)->firstWhere('assignment_id', $assignment->id);
            $stats = UserStats::lockForUpdate()->findOrFail($assignment->user_id);
            Progression::heal($stats, (float) $entry['damage']);
            $stats->save();

            $assignment->update(['streak' => $entry['streak'], 'value' => $entry['value'] ?? $assignment->value, 'streak_paused_at' => null]);
            RecoveryQuest::where('assignment_id', $assignment->id)->where('status', 'active')->update(['status' => 'restored']);
            $log->update(['missed' => collect($log->missed)->reject(fn ($m) => $m['assignment_id'] === $assignment->id)->values()->all()]);

            SkillCast::create(['user_id' => $healer->id, 'skill_key' => 'second_chance', 'target_user_id' => $assignment->user_id]);

            return "Zweite Chance für „{$assignment->task->title}“ – {$entry['damage']} HP zurück und die Streak bleibt!";
        });
    }

    /**
     * Barde, passiv „Gemeinschafts-Rhythmus“: Haben alle aktiven Mitglieder (mind. 2) an einem Tag
     * 100 % ihrer Dailies geschafft, bekommt jedes Mitglied am Folgetag einmalig Gold.
     *
     * @return int Anzahl ausgezahlter Familien
     */
    public function evaluateCommunityRhythm(?CarbonImmutable $date = null): int
    {
        $paid = 0;

        $families = Family::whereHas('members.stats', fn ($q) => $q->where('class_key', 'bard'))->get();
        foreach ($families as $family) {
            $day = ($date ?? CarbonImmutable::now($family->timezone)->subDay())->toDateString();

            if (FamilyDay::where('family_id', $family->id)->whereDate('date', $day)->exists()) {
                continue;
            }

            $members = $family->members()->where('family_role', '!=', FamilyRole::Guest->value)->pluck('id');
            $logs = DayLog::whereIn('user_id', $members)->whereDate('date', $day)->get()->keyBy('user_id');

            // Erst auswerten, wenn alle ihren Tageswechsel hatten
            if ($logs->count() < $members->count()) {
                continue;
            }

            $active = $logs->reject(fn (DayLog $l) => $l->sleeping || $l->dailies_due === 0);
            $allDone = $active->count() >= 2 && $active->every(fn (DayLog $l) => $l->dailies_done >= $l->dailies_due);
            $gold = $allDone ? (int) config('game.community_rhythm_gold') : 0;

            FamilyDay::create(['family_id' => $family->id, 'date' => $day, 'all_done' => $allDone, 'bonus_gold' => $gold]);

            if ($allDone) {
                UserStats::whereIn('user_id', $members)->increment('gold', $gold);
                $paid++;
            }
        }

        return $paid;
    }

    private function familyBuff(User $user, string $key, float $value, int $hours, string $message): string
    {
        $this->upsertBuff(['family_id' => $user->family_id, 'key' => $key], $value, $hours, false, $user);

        return $message;
    }

    private function armedBuff(User $user, string $key, float $value, int $hours, string $message): string
    {
        $this->upsertBuff(['user_id' => $user->id, 'key' => $key], $value, $hours, true, $user);

        return $message;
    }

    /** Gleiche Buffs stapeln nicht – sie verlängern nur die Laufzeit. */
    private function upsertBuff(array $scope, float $value, int $hours, bool $armed, User $source): void
    {
        $buff = ActiveBuff::current()->where($scope)->first();

        if ($buff) {
            $buff->update(['expires_at' => now()->addHours($hours)]);

            return;
        }

        ActiveBuff::create([...$scope, 'value' => $value, 'armed' => $armed, 'expires_at' => now()->addHours($hours), 'source_user_id' => $source->id]);
    }

    private function heal(User $target, float $amount): string
    {
        $stats = UserStats::lockForUpdate()->findOrFail($target->id);
        $before = $stats->hp;
        Progression::heal($stats, $amount);
        $stats->save();

        return sprintf('%s wurde um %s HP geheilt.', $target->name, round($stats->hp - $before, 1));
    }
}
