<?php

namespace App\Events;

use App\Models\RewardRedemption;
use Illuminate\Foundation\Events\Dispatchable;

/** Eine Familienbelohnung ist eingelöst und (falls nötig) bestätigt. */
class RewardRedeemed
{
    use Dispatchable;

    public function __construct(public readonly RewardRedemption $redemption) {}
}
