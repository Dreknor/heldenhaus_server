<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Frei vergebene Abzeichen (§14): erreichte Ziele, gemeisterte Gewohnheiten, „Verlässlich“ (Ehrensache),
     * Jahres-Abzeichen, Album-Belohnungen. Katalog-Erfolge bleiben in user_achievements.
     */
    public function up(): void
    {
        Schema::create('user_badges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('key', 120);
            $table->string('kind', 30);
            $table->string('name', 120);
            $table->string('icon', 16);
            $table->json('data')->nullable();
            $table->timestamp('seen_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_badges');
    }
};
