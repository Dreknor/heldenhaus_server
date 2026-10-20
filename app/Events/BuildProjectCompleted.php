<?php

namespace App\Events;

use App\Models\BuildProject;
use Illuminate\Foundation\Events\Dispatchable;

/** Ein Bauprojekt im Familienhaus ist eingeweiht (z. B. neue Ausbaustufe → Hauseinweihung, §12.4). */
class BuildProjectCompleted
{
    use Dispatchable;

    public function __construct(public readonly BuildProject $build, public readonly array $project) {}
}
