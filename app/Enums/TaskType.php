<?php

namespace App\Enums;

enum TaskType: string
{
    case Habit = 'habit';
    case Daily = 'daily';
    case Todo = 'todo';
    case Reward = 'reward';
}
