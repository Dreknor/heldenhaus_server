<?php

namespace App\Domain\Achievements;

/** Kennzahlen, die sich beim Abhaken oder Bestätigen einer Aufgabe ändern können. */
final class AchievementMetrics
{
    public const TASKS = ['tasks_done', 'habits_up', 'best_streak', 'family_tasks', 'team_tasks', 'stars_earned', 'level'];
}
