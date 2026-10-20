<?php

namespace App\Http\Presenters;

use App\Domain\Art\PetArt;
use App\Domain\Gameplay\RunDayCron;
use App\Domain\Support\FocusService;
use App\Domain\Support\SupportService;
use App\Enums\CompletionStatus;
use App\Enums\TaskType;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\User;
use App\Models\UserPet;

/** Alle Daten des Heldenpanels einer Person – genutzt von Web (Inertia) und App (API). */
class HeroBoard
{
    public static function for(User $user): array
    {
        $today = $user->gameDate();

        $assignments = TaskAssignment::with([
            'task.assignments',
            'completions' => fn ($q) => $q->where('direction', 'up')->latest('id')->limit(1),
        ])
            ->where('user_id', $user->id)
            ->whereHas('task', fn ($q) => $q->whereNull('archived_at'))
            ->get()
            ->sortBy(fn (TaskAssignment $a) => [$a->task->sort, $a->task->id])
            ->map(fn (TaskAssignment $a) => GamePresenter::assignment($a, $today))
            ->groupBy('type');

        // Erledigte To-dos verschwinden, außer sie warten noch auf Freigabe
        $todos = collect($assignments->get('todo', []))
            ->filter(fn ($t) => ! $t['completed'] || ($t['pending_approval'] ?? false))
            ->values();
        // Kopf-Ablage (§11.2): unsortierte Einträge getrennt anzeigen
        [$inbox, $todos] = $todos->partition(fn ($t) => $t['status'] === 'inbox');

        return [
            'habits' => $assignments->get('habit', collect())->values(),
            'dailies' => $assignments->get('daily', collect())->values(),
            'todos' => $todos->values(),
            'inbox' => $inbox->values(),
            'rewards' => Task::where('owner_id', $user->id)->where('type', TaskType::Reward)->active()->orderBy('gold_cost')->get()
                ->map(fn (Task $t) => GamePresenter::task($t))->values(),
            'pool' => Task::where('family_id', $user->family_id)->where('assignment_mode', 'pool')->active()
                ->doesntHave('assignments')->latest()->get()
                ->map(fn (Task $t) => GamePresenter::task($t))->values(),
            'cronPending' => RunDayCron::openFromLastDay($user)
                ->map(fn (TaskAssignment $a) => ['assignment_id' => $a->id, 'title' => $a->task->title])
                ->values(),
            'quests' => CharacterSheet::quests($user),
            // Begleiter in der Szene: bevorzugt ein Reittier, sonst das neueste Haustier
            'companion' => ($pet = UserPet::with('egg', 'potion')->where('user_id', $user->id)->orderByDesc('is_mount')->latest('id')->first())
                ? ['egg_key' => $pet->egg->key, 'potion_key' => $pet->potion->key, 'is_mount' => $pet->is_mount, 'name' => $pet->name,
                    'art_url' => PetArt::url($pet->egg->key), 'palette' => PetArt::palette($pet->potion)]
                : null,
            'recentPending' => $user->completions()->where('status', CompletionStatus::Pending)->count(),
            // Fokus & Alltagshilfen
            'support' => SupportService::present($user),
            'recoveries' => SupportService::presentRecoveries($user),
            'focus' => FocusService::present(FocusService::running($user)),
        ];
    }
}
