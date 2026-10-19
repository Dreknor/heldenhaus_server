<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quest-System: ersetzt den einfachen Familien-Boss durch Boss- und Sammel-Quests,
 * jeweils als gemeinsame Familien-Quest oder als Solo-Quest einer Person.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('family_quest_contributions');
        Schema::dropIfExists('family_quests');

        Schema::create('quests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete(); // gesetzt = Solo-Quest
            $table->string('scope');      // family|solo
            $table->string('type');       // boss|collect
            $table->string('quest_key');
            $table->string('name');
            $table->string('icon');
            $table->double('goal');       // Boss: max. HP · Sammeln: Zielmenge
            $table->double('progress')->default(0); // Boss: Schaden · Sammeln: gesammelt
            $table->json('data')->nullable();       // Schwäche, Resistenz, Wut, Kategorien, Sammel-Item
            $table->json('rewards')->nullable();
            $table->string('reward_text')->nullable(); // reale Familien-Belohnung
            $table->string('status')->default('active'); // active|completed|expired|abandoned
            $table->timestamp('ends_at');
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['family_id', 'scope', 'status']);
        });

        Schema::create('quest_contributions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quest_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_completion_id')->nullable()->constrained()->cascadeOnDelete();
            $table->double('amount');
            $table->timestamps();
        });

        Schema::table('task_completions', function (Blueprint $table) {
            $table->renameColumn('boss_damage', 'quest_progress');
        });
    }

    public function down(): void
    {
        Schema::table('task_completions', fn (Blueprint $t) => $t->renameColumn('quest_progress', 'boss_damage'));
        Schema::dropIfExists('quest_contributions');
        Schema::dropIfExists('quests');
    }
};
