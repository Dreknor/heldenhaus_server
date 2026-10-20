<?php

use App\Domain\Rewards\RewardTemplates;
use App\Models\Family;
use Illuminate\Database\Migrations\Migration;

/** Familien mit leerem Belohnungsshop bekommen die Start-Belohnungen (neue Familien bekommen sie beim Anlegen). */
return new class extends Migration
{
    public function up(): void
    {
        Family::whereDoesntHave('rewardItems')->each(fn (Family $family) => RewardTemplates::seedStarter($family));
    }

    public function down(): void {}
};
