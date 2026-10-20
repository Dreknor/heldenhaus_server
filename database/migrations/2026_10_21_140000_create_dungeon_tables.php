<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 7 – Dungeon-Quests (Konzept §2.7, §5.3). Monster, Themen und Bausteine liegen in database/content/dungeons.json. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dungeons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();   // gesetzt = Solo-Gewölbe
            $table->string('kind', 8);                        // family|solo
            $table->string('theme');
            $table->string('seed', 32);
            $table->string('mode', 8)->default('child');      // child (9–12) | teen (ab 13)
            $table->string('status', 12)->default('active');  // active|completed|expired
            $table->date('starts_on');
            $table->timestamp('ends_at');
            $table->foreignId('quest_id')->nullable()->constrained()->nullOnDelete(); // Endboss = Familien-Boss
            $table->json('state');                            // Raumgraph, Nebel, Türen, Monster, Beute
            $table->unsignedInteger('counter')->default(0);   // Aktionszähler für den deterministischen Zufall
            $table->boolean('boss_unlocked')->default(false);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['family_id', 'kind', 'status']);
        });

        Schema::create('dungeon_positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dungeon_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('room')->default(0);
            $table->unsignedTinyInteger('previous_room')->nullable();
            $table->double('hp');
            $table->json('fight')->nullable();                 // laufender Kampf: Runde, Gegner-HP
            $table->unsignedSmallInteger('knockouts')->default(0);
            $table->timestamps();
            $table->unique(['dungeon_id', 'user_id']);
        });

        Schema::create('dungeon_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dungeon_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('client_uuid')->nullable();
            $table->unsignedInteger('counter');
            $table->string('action', 16);
            $table->unsignedTinyInteger('room');
            $table->unsignedSmallInteger('torches')->default(0);
            $table->json('result');
            $table->timestamp('created_at')->nullable();
            $table->unique(['user_id', 'client_uuid']);
            $table->index(['dungeon_id', 'id']);
        });

        Schema::table('user_stats', function (Blueprint $table) {
            $table->unsignedSmallInteger('torches')->default(0);
            $table->unsignedSmallInteger('torches_today')->default(0);
            $table->date('torches_on')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('user_stats', fn (Blueprint $t) => $t->dropColumn(['torches', 'torches_today', 'torches_on']));
        Schema::dropIfExists('dungeon_actions');
        Schema::dropIfExists('dungeon_positions');
        Schema::dropIfExists('dungeons');
    }
};
