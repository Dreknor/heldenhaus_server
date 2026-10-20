<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Familienbündnis (Konzept §3.6): Familie A erzeugt einen Code, ein Elternteil von B löst ihn ein.
        Schema::create('family_alliances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->foreignId('partner_family_id')->nullable()->constrained('families')->cascadeOnDelete();
            $table->string('code', 12)->nullable()->unique();
            $table->string('status')->default('pending');   // pending|active|ended
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
        });

        // Challenges: Aufgabenpaket mit Zeitraum, Teilnahme freiwillig, Rangliste optional
        Schema::create('challenges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('icon', 16)->default('🏁');
            $table->json('tasks');                         // [{title, type: daily|habit|todo, category}]
            $table->unsignedSmallInteger('target');         // so viele Abschlüsse im Zeitraum
            $table->unsignedInteger('reward_gold')->default(0);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('scope')->default('family');     // family|alliance
            $table->boolean('leaderboard')->default(false);
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('challenge_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('challenge_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('left_at')->nullable();
            $table->timestamps();
            $table->unique(['challenge_id', 'user_id']);
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('challenge_id')->nullable()->after('family_id')->constrained()->nullOnDelete();
        });

        // Nachrichten: in der Familie oder im Bündnis. Freitext im Bündnis nur für Erwachsene und freigegebene Teens.
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();       // Familie der Absenderin
            $table->foreignId('alliance_id')->nullable()->constrained('family_alliances')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 8);                       // text|preset|emoji
            $table->string('body', 500);
            $table->timestamps();
            $table->index(['family_id', 'alliance_id', 'id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('chat_allowed')->default(false)->after('theme');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('chat_allowed'));
        Schema::dropIfExists('messages');
        Schema::table('tasks', function (Blueprint $t) {
            $t->dropConstrainedForeignId('challenge_id');
        });
        Schema::dropIfExists('challenge_participants');
        Schema::dropIfExists('challenges');
        Schema::dropIfExists('family_alliances');
    }
};
