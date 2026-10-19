<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_equipment', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('slot');
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'slot']);
        });

        // Haustier = Ei + Schlüpfpulver; ab 50 Wachstum wird es zum Reittier
        Schema::create('user_pets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('egg_item_id')->constrained('items');
            $table->foreignId('potion_item_id')->constrained('items');
            $table->string('name');
            $table->unsignedSmallInteger('growth')->default(0);
            $table->boolean('is_mount')->default(false);
            $table->timestamps();
            $table->unique(['user_id', 'egg_item_id', 'potion_item_id']);
        });

        // Buffs aus Klassenfähigkeiten (persönlich oder für die ganze Familie)
        Schema::create('active_buffs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('source_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('key');
            $table->double('value')->default(0);
            $table->boolean('armed')->default(false); // wirkt beim nächsten passenden Abschluss und verfällt dann
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->index(['family_id', 'key']);
            $table->index(['user_id', 'key']);
        });

        Schema::create('skill_casts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('skill_key');
            $table->foreignId('target_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('mana_cost')->default(0);
            $table->timestamps();
            $table->index(['user_id', 'skill_key', 'created_at']);
        });

        // Familien-Boss (bis Phase 7 eine Lebensleiste)
        Schema::create('family_quests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->string('boss_key');
            $table->string('name');
            $table->string('icon');
            $table->unsignedInteger('max_hp');
            $table->double('hp');
            $table->string('reward_text')->nullable();
            $table->string('status')->default('active'); // active|defeated|expired
            $table->timestamp('ends_at');
            $table->timestamp('defeated_at')->nullable();
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('family_quest_contributions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_quest_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_completion_id')->nullable()->constrained()->cascadeOnDelete();
            $table->double('damage');
            $table->timestamps();
        });

        // Auswertung „alle haben alles geschafft“ (Barde: Gemeinschafts-Rhythmus)
        Schema::create('family_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->boolean('all_done')->default(false);
            $table->unsignedInteger('bonus_gold')->default(0);
            $table->timestamps();
            $table->unique(['family_id', 'date']);
        });

        Schema::table('day_logs', function (Blueprint $table) {
            $table->json('missed')->nullable(); // [{assignment_id, damage, streak}] für „Zweite Chance“
        });

        Schema::table('task_completions', function (Blueprint $table) {
            $table->double('boss_damage')->default(0);
            $table->json('effects')->nullable(); // welche Klassen-/Buff-Effekte gewirkt haben
        });
    }

    public function down(): void
    {
        Schema::table('task_completions', fn (Blueprint $t) => $t->dropColumn(['boss_damage', 'effects']));
        Schema::table('day_logs', fn (Blueprint $t) => $t->dropColumn('missed'));
        foreach (['family_days', 'family_quest_contributions', 'family_quests', 'skill_casts', 'active_buffs', 'user_pets', 'user_equipment'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
