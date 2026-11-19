<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Langzeitziele nach Level 14 (§14.7) und Chronik (§14.8): Heldenjahr, Sammelalbum,
     * Meisterschaften je Kategorie, freigeschaltete Chronik-Kapitel und das Familienwappen.
     */
    public function up(): void
    {
        Schema::create('hero_years', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Schuljahr, benannt nach dem Jahr seines Beginns (2026 = Schuljahr 2026/27)
            $table->unsignedSmallInteger('start_year');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->unsignedInteger('points')->default(0);
            $table->unsignedSmallInteger('level')->default(0);
            // Bis zu dieser Stufe sind die Belohnungen ausgezahlt (jede genau einmal)
            $table->unsignedSmallInteger('rewarded_level')->default(0);
            $table->unsignedInteger('points_today')->default(0);
            $table->date('points_on')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'start_year']);
        });

        Schema::create('album_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('album', 20);
            // Einmal gesammelt bleibt gesammelt, auch wenn das Möbelstück später weg ist
            $table->json('collected');
            $table->unsignedTinyInteger('percent')->default(0);
            $table->json('rewarded');
            $table->timestamps();

            $table->unique(['user_id', 'album']);
        });

        Schema::create('mastery_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('category', 20);
            $table->unsignedInteger('count')->default(0);
            $table->unsignedTinyInteger('level')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'category']);
        });

        Schema::create('chronicle_unlocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('chapter_key', 60);
            $table->timestamp('unlocked_at');
            $table->timestamp('read_at')->nullable();
            $table->timestamp('opened_together_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'chapter_key']);
        });

        Schema::table('families', function (Blueprint $table) {
            $table->string('crest', 16)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('families', function (Blueprint $table) {
            $table->dropColumn('crest');
        });
        Schema::dropIfExists('chronicle_unlocks');
        Schema::dropIfExists('mastery_levels');
        Schema::dropIfExists('album_progress');
        Schema::dropIfExists('hero_years');
    }
};
