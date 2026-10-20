<?php

namespace App\Events;

use App\Models\TaskCompletion;
use Illuminate\Foundation\Events\Dispatchable;

/** Eltern (oder die Auto-Freigabe) haben einen Abschluss bestätigt oder abgelehnt. */
class CompletionReviewed
{
    use Dispatchable;

    public function __construct(public readonly TaskCompletion $completion, public readonly bool $approved) {}
}
