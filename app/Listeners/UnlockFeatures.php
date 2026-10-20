<?php

namespace App\Listeners;

use App\Domain\Features\Features;
use App\Events\LevelUp;

/** Nach einem Level-up fällige Freischaltungen anlegen (§13.4); die Erklärung kommt höchstens einmal am Tag. */
class UnlockFeatures
{
    public function handleLevelUp(LevelUp $event): void
    {
        if (Features::enforced()) {
            $user = $event->user->fresh('stats');
            $user && Features::sync($user);
        }
    }
}
