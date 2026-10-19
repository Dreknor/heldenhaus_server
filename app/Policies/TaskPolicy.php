<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\User;

class TaskPolicy
{
    public function view(User $user, Task $task): bool
    {
        if ($task->owner_id === $user->id) {
            return true;
        }

        if ($task->isFamilyTask()) {
            return $task->family_id === $user->family_id;
        }

        // Persönliche Aufgaben anderer: Eltern sehen sie, außer sie sind privat (Teens).
        return $user->isParent() && $user->sameFamilyAs($task->owner) && ! $task->is_private;
    }

    public function createFamilyTask(User $user): bool
    {
        return $user->isParent();
    }

    public function update(User $user, Task $task): bool
    {
        return $task->isFamilyTask()
            ? $user->isParent() && $task->family_id === $user->family_id
            : $task->owner_id === $user->id;
    }

    public function delete(User $user, Task $task): bool
    {
        return $this->update($user, $task);
    }

    public function claim(User $user, Task $task): bool
    {
        return $task->isPool() && $task->family_id === $user->family_id;
    }
}
