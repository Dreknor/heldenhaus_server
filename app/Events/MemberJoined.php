<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/** Ein neues Mitglied ist der Familie beigetreten (Einladung angenommen oder Kinderprofil angelegt). */
class MemberJoined
{
    use Dispatchable;

    public function __construct(public readonly User $user) {}
}
