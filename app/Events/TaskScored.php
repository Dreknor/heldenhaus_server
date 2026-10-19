<?php

namespace App\Events;

use App\Domain\Gameplay\ScoreResult;
use Illuminate\Foundation\Events\Dispatchable;

class TaskScored
{
    use Dispatchable;

    public function __construct(public readonly ScoreResult $result) {}
}
