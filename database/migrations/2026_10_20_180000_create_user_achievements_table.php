<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Eine Zeile je erreichter Stufe eines Erfolgs (Katalog: database/content/achievements.json)
        Schema::create('user_achievements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('achievement_key');
            $table->unsignedTinyInteger('tier');      // 1-basiert
            $table->unsignedInteger('threshold');     // erreichter Schwellwert, falls der Katalog sich ändert
            $table->unsignedInteger('reward_gold')->default(0);
            $table->timestamp('seen_at')->nullable(); // null = Feier noch nicht gezeigt
            $table->timestamps();
            $table->unique(['user_id', 'achievement_key', 'tier']);
            $table->index(['user_id', 'seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_achievements');
    }
};
