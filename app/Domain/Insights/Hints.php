<?php

namespace App\Domain\Insights;

use App\Enums\AgeGroup;
use App\Enums\FamilyRole;
use App\Enums\TaskType;
use App\Models\Family;
use App\Models\Survey;
use App\Models\TaskAssignment;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

/**
 * Entscheidungsregeln (Konzept §14.10) – nur Hinweise an die Eltern, die App ändert nie etwas allein.
 *
 * 1. Aktivität eines Kindes sinkt 2 Wochen in Folge um mehr als 30 %: Beide der letzten zwei Wochen
 *    liegen mehr als 30 % unter dem Schnitt der zwei Wochen davor (Tagespunkte ohne Gasthaus-Tage).
 * 2. Stimmung zweimal ≤ 2: die letzten beiden Stimmungs-Checks einer Person.
 * 3. Mehr als 12 aktive Dailies bei einem Kind unter 13.
 */
class Hints
{
    public const DROP = 0.3;

    public const MIN_BASE_POINTS = 4.0;   // darunter ist „weniger“ kein sinnvolles Signal

    public const MAX_DAILIES_CHILD = 12;

    public function __construct(private readonly ActivityScores $scores) {}

    /** @return list<array{type: string, member_id: int, name: string, icon: string, title: string, text: string, suggestions: list<string>}> */
    public function for(Family $family): array
    {
        $members = User::where('family_id', $family->id)->where('family_role', '!=', FamilyRole::Guest->value)->orderBy('name')->get();
        $hints = [];

        foreach ($members as $member) {
            if ($member->isChild() && $this->activityDropped($member)) {
                $hints[] = [
                    'type' => 'activity_drop',
                    'member_id' => $member->id,
                    'name' => $member->name,
                    'icon' => '📉',
                    'title' => "Bei {$member->name} ist seit zwei Wochen weniger los",
                    'text' => 'Das ist normal und kein Grund zur Sorge. Vielleicht hilft eine dieser Ideen:',
                    'suggestions' => [
                        'Weniger Aufgaben: ein, zwei Dailies pausieren oder seltener machen',
                        'Energiespar-Tage anbieten (unter Fokus & Alltagshilfen)',
                        'Im Familienrat gemeinsam fragen, was gerade schwer ist',
                    ],
                ];
            }

            if ($reasons = $this->lowMood($member)) {
                $named = array_map(fn ($r) => SurveyService::MOOD_REASONS[$r] ?? $r, $reasons['reasons']);
                $hints[] = [
                    'type' => 'low_mood',
                    'member_id' => $member->id,
                    'name' => $member->name,
                    'icon' => '💬',
                    'title' => "{$member->name} hatte zuletzt zweimal wenig Spaß",
                    'text' => $named ? 'Genannt wurde: '.implode(', ', $named).'.' : 'Ein guter Anlass, gemeinsam darüber zu reden.',
                    'suggestions' => array_values(array_filter([
                        'Die Gründe im Familienrat besprechen',
                        in_array('too_hard', $reasons['reasons'], true) || $named === [] ? 'Bei der nächsten Quest „leicht“ wählen' : null,
                        in_array('too_many', $reasons['reasons'], true) ? 'Weniger Aufgaben auf einmal, lieber „Seltener“' : null,
                        in_array('few_rewards', $reasons['reasons'], true) ? 'Gemeinsam neue Belohnungen für den Shop ausdenken' : null,
                        in_array('boring', $reasons['reasons'], true) ? 'Eine neue Quest oder einen Dungeon ausprobieren' : null,
                    ])),
                ];
            }

            if ($member->isChild() && $member->ageGroup() === AgeGroup::Child && ($count = $this->activeDailies($member)) > self::MAX_DAILIES_CHILD) {
                $hints[] = [
                    'type' => 'too_many_dailies',
                    'member_id' => $member->id,
                    'name' => $member->name,
                    'icon' => '🧺',
                    'title' => "{$member->name} hat {$count} tägliche Aufgaben",
                    'text' => 'Das kann für Kinder unter 13 zu viel sein. Weniger ist oft mehr.',
                    'suggestions' => [
                        'Aufgaben, die schon sitzen, „Seltener“ machen oder meistern',
                        'Mit 5 bis 8 Dailies weitermachen und später ergänzen',
                    ],
                ];
            }
        }

        return $hints;
    }

    public function activityDropped(User $user): bool
    {
        $to = $user->gameDate()->subDay();
        $from = $to->subDays(27);
        if ($user->gameDate($user->created_at ?? now())->gt($from)) {
            return false; // noch keine vier Wochen dabei
        }

        $days = array_values($this->scores->daily($user, $from, $to));
        $avg = function (array $slice) {
            $usable = array_filter($slice, fn ($d) => ! $d['sleeping']);

            return $usable === [] ? null : array_sum(array_column($usable, 'points')) / count($usable);
        };

        $base = $avg(array_slice($days, 0, 14));
        $week2 = $avg(array_slice($days, 14, 7));
        $week1 = $avg(array_slice($days, 21, 7));
        if ($base === null || $base < self::MIN_BASE_POINTS || $week1 === null || $week2 === null) {
            return false;
        }

        return $week2 < $base * (1 - self::DROP) && $week1 < $base * (1 - self::DROP);
    }

    /** @return array{reasons: list<string>}|null */
    public function lowMood(User $user): ?array
    {
        $last = Survey::where('user_id', $user->id)->where('kind', 'mood')->whereNotNull('answers')->latest('date')->latest('id')->limit(2)->get();
        if ($last->count() < 2 || $last->contains(fn ($s) => ($s->answers['mood'] ?? 5) > 2)) {
            return null;
        }

        return ['reasons' => $last->flatMap(fn ($s) => $s->answers['reasons'] ?? [])->unique()->values()->all()];
    }

    public function activeDailies(User $user): int
    {
        return TaskAssignment::where('user_id', $user->id)
            ->whereHas('task', function ($q) {
                $q->where('type', TaskType::Daily)->whereNull('archived_at');
                if (Schema::hasColumn('tasks', 'mastered_at')) {
                    $q->whereNull('mastered_at');
                }
            })->count();
    }
}
