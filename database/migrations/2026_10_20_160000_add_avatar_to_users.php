<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Avatar-Baukasten: {"skin":…, "hair":…, "hair_color":…, "eyes":…, "outfit":…, "background":…}
        Schema::table('users', fn (Blueprint $table) => $table->json('avatar')->nullable()->after('avatar_color'));
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('avatar'));
    }
};
