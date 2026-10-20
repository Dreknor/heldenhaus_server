<?php

namespace App\Events;

use App\Models\RewardRedemption;
use Illuminate\Foundation\Events\Dispatchable;

/** Eine Einlösung wartet auf die Bestätigung der Eltern. */
class RedemptionRequested
{
    use Dispatchable;

    public function __construct(public readonly RewardRedemption $redemption) {}
}
