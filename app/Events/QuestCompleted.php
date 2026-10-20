<?php

namespace App\Events;

use App\Models\Quest;
use Illuminate\Foundation\Events\Dispatchable;

class QuestCompleted
{
    use Dispatchable;

    public function __construct(public readonly Quest $quest) {}
}
