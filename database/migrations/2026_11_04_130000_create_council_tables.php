<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Familienrat und Mitbestimmung (§14.4), Hilfen für Eltern (§14.9) und Altersübergänge (§14.11).
     */
    public function up(): void
    {
        Schema::create('family_councils', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->date('held_on');
            $table->string('status', 12)->default('running'); // running|done
            $table->unsignedTinyInteger('step')->default(1);   // 1 Rückblick … 5 Wünsche
            $table->json('recap')->nullable();
            $table->json('thanks')->nullable();     // [{from, to, reason}]
            $table->json('decisions')->nullable();  // [{proposal_id, title, decision}]
            $table->json('wishes')->nullable();     // [{user_id, kind: note|goal, text}]
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['family_id', 'held_on']);
        });

        Schema::create('proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();       // von wem
            $table->string('kind', 10);                                          // reward|task
            $table->string('target', 10)->nullable();                            // Aufgaben: pool|parent
            $table->foreignId('for_user_id')->nullable()->constrained('users')->nullOnDelete(); // Eltern-Aufgabe für …
            $table->string('template_key', 60)->nullable();
            $table->string('title', 120);
            $table->string('icon', 16)->nullable();
            $table->unsignedInteger('stars')->nullable();                         // Wunschpreis bzw. Sterne-Vorschlag
            $table->string('status', 10)->default('open');                       // open|accepted|declined
            $table->string('reason', 300)->nullable();                           // Begründung bei Ablehnung
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('reward_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('task_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('family_council_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['family_id', 'status']);
        });

        Schema::create('votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->string('occasion', 20)->default('family_quest');
            $table->json('options');                                             // [{key, name, icon, type, …}]
            $table->json('ballots')->nullable();                                 // {user_id: option_key}
            $table->string('status', 10)->default('open');                       // open|tie|decided|started|cancelled
            $table->string('winner', 60)->nullable();
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('quest_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('family_council_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('closes_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['family_id', 'status']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('teen_card_seen_at')->nullable();
            $table->timestamp('adult_offer_dismissed_at')->nullable();
            $table->timestamp('adult_profile_at')->nullable();
            $table->timestamp('parent_guide_seen_at')->nullable();
            $table->timestamp('starter_tasks_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['teen_card_seen_at', 'adult_offer_dismissed_at', 'adult_profile_at', 'parent_guide_seen_at', 'starter_tasks_at']);
        });
        Schema::dropIfExists('votes');
        Schema::dropIfExists('proposals');
        Schema::dropIfExists('family_councils');
    }
};
