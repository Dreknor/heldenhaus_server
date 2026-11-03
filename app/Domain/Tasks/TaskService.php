<?php

namespace App\Domain\Tasks;

use App\Domain\Gameplay\GameException;
use App\Domain\Support\StepSuggestions;
use App\Enums\TaskType;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class TaskService
{
    public function __construct(private readonly StepSuggestions $stepSuggestions) {}

    /** @param array<string, mixed> $data validierte Daten aus TaskRequest */
    public function create(User $actor, array $data, bool $autoSuggestSteps = false): Task
    {
        $task = DB::transaction(function () use ($actor, $data) {
            $isFamily = ($data['scope'] ?? 'personal') === 'family';

            $attributes = $this->attributes($data);
            if (! in_array($data['type'], [TaskType::Daily->value, TaskType::Todo->value], true)) {
                $attributes['checklist'] = null;
            }

            $task = Task::create([
                ...$attributes,
                'scope' => $isFamily ? 'family' : 'personal',
                'family_id' => $isFamily ? $actor->family_id : null,
                'owner_id' => $isFamily ? null : $actor->id,
                'created_by' => $actor->id,
                'type' => $data['type'],
            ]);

            if ($isFamily) {
                $this->syncAssignees($task, $data['assignees'] ?? []);
            } else {
                $task->assignments()->create(['user_id' => $actor->id]);
            }

            return $task;
        });

        $autoSuggestionDifficulties = array_map('floatval', [
            config('game.difficulties.medium'),
            config('game.difficulties.hard'),
        ]);

        if ($autoSuggestSteps && $task->supportsSteps() && empty($task->checklist) && in_array((float) $task->difficulty, $autoSuggestionDifficulties, true)) {
            $this->stepSuggestions->suggestOnCreate($actor, $task);
        }

        return $task;
    }

    /** @param array<string, mixed> $data */
    public function update(Task $task, array $data): Task
    {
        return DB::transaction(function () use ($task, $data) {
            $attributes = $this->attributes($data);
            if (! $task->supportsSteps()) {
                $attributes['checklist'] = null;
            }
            $task->update($attributes);

            if ($task->isFamilyTask() && array_key_exists('assignees', $data)) {
                $this->syncAssignees($task, $data['assignees'] ?? []);
            }

            return $task;
        });
    }

    public function archive(Task $task): void
    {
        $task->update(['archived_at' => now()]);
    }

    /** Aufgabe aus der Börse übernehmen – wer zuerst kommt, bekommt sie. */
    public function claim(Task $task, User $user): TaskAssignment
    {
        if (! $task->isPool() || $task->family_id !== $user->family_id) {
            throw new GameException('Diese Aufgabe ist nicht in der Aufgabenbörse.');
        }

        return DB::transaction(function () use ($task, $user) {
            $locked = Task::lockForUpdate()->findOrFail($task->id);

            if ($locked->assignments()->exists()) {
                throw new GameException('Zu langsam – jemand anderes hat die Aufgabe schon übernommen.');
            }

            return $locked->assignments()->create(['user_id' => $user->id]);
        });
    }

    /** @param list<int> $userIds */
    private function syncAssignees(Task $task, array $userIds): void
    {
        if ($task->assignment_mode === 'pool') {
            return;
        }

        $valid = User::where('family_id', $task->family_id)->whereIn('id', $userIds)->pluck('id');

        $task->assignments()->whereNotIn('user_id', $valid)->delete();

        foreach ($valid as $userId) {
            $task->assignments()->firstOrCreate(['user_id' => $userId]);
        }

        if ($task->assignment_mode === 'rotation') {
            $task->update(['rotation' => [
                'members' => array_values(array_filter($task->rotation['members'] ?? [], fn ($id) => $valid->contains($id))),
                'interval' => $task->rotation['interval'] ?? 'daily',
            ]]);
        }
    }

    /** @param array<string, mixed> $data */
    private function attributes(array $data): array
    {
        $attributes = Arr::only($data, [
            'title', 'notes', 'category', 'difficulty', 'up', 'down', 'daily_limit', 'repeat', 'start_date', 'due_date',
            'checklist', 'is_private', 'is_core', 'approval_mode', 'assignment_mode', 'star_reward', 'team_bonus_gold', 'gold_cost', 'ha_event_key',
        ]);

        // Ämterplan: Reihenfolge der Zuweisung = Reihenfolge im Plan
        if (($data['assignment_mode'] ?? null) === 'rotation') {
            $attributes['rotation'] = [
                'members' => array_values(array_map('intval', $data['assignees'] ?? [])),
                'interval' => $data['rotation_interval'] ?? 'daily',
            ];
        } elseif (isset($data['assignment_mode'])) {
            $attributes['rotation'] = null;
        }

        // Familienaufgaben sind nie privat (Konzept §3.7)
        if (($data['scope'] ?? null) === 'family' || isset($data['assignment_mode'])) {
            $attributes['is_private'] = false;
        }

        if (($data['type'] ?? null) === TaskType::Daily->value && empty($attributes['start_date'])) {
            $attributes['start_date'] = now()->toDateString();
        }

        return $attributes;
    }
}
