<?php

use App\Domain\Family\FamilyService;
use App\Domain\Tasks\TaskService;
use App\Enums\FamilyRole;
use App\Models\Family;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\User;

pest()->extend(Tests\TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature');

pest()->extend(Tests\TestCase::class)->in('Unit');

/**
 * Familie mit einem Elternteil und einem Kind (10 Jahre) anlegen.
 *
 * @return array{0: Family, 1: User, 2: User}
 */
function makeFamily(): array
{
    $parent = User::factory()->create(['timezone' => 'Europe/Berlin', 'day_start' => 0]);
    $family = app(FamilyService::class)->createFamily($parent, 'Familie Test');
    $child = app(FamilyService::class)->addChild($parent->refresh(), 'Lea', (int) now()->year - 10, '1234');

    return [$family, $parent->refresh(), $child->refresh()];
}

function makeTask(User $user, array $attributes = []): Task
{
    return app(TaskService::class)->create($user, array_merge([
        'type' => 'todo',
        'title' => 'Testaufgabe',
        'difficulty' => 1,
    ], $attributes));
}

function assignmentOf(Task $task, User $user): TaskAssignment
{
    return TaskAssignment::where('task_id', $task->id)->where('user_id', $user->id)->firstOrFail();
}

/** Gold ohne die Belohnungen aus Erfolgen – für Tests, die eine andere Mechanik nachrechnen. */
function goldWithoutAchievements(User $user): float
{
    return round($user->stats()->first()->gold - $user->achievements()->sum('reward_gold'), 2);
}

function joinFamily(Family $family, FamilyRole $role = FamilyRole::Child, array $attributes = []): User
{
    return User::factory()->create(array_merge(['family_id' => $family->id, 'family_role' => $role], $attributes));
}
