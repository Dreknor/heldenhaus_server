<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ämterplan: {"members": [ids in Reihenfolge], "interval": "daily"|"weekly"}
        Schema::table('tasks', function (Blueprint $table) {
            $table->json('rotation')->nullable()->after('assignment_mode');
            $table->unsignedInteger('team_bonus_gold')->default(0)->after('star_reward');
        });

        Schema::create('feed_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type');   // task_done|level_up|boss_defeated|pet_hatched|reward_redeemed|class_chosen|team_done
            $table->string('icon')->default('✨');
            $table->string('text');
            $table->json('data')->nullable();
            $table->timestamps();
            $table->index(['family_id', 'created_at']);
        });

        Schema::create('feed_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('feed_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('emoji', 8);
            $table->timestamps();
            $table->unique(['feed_entry_id', 'user_id', 'emoji']);
        });

        // Taschengeld-Konto: nur Buchhaltung, kein echter Zahlungsverkehr
        Schema::create('allowance_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->integer('amount_cents');
            $table->string('description');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reward_redemption_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('reward_items', function (Blueprint $table) {
            $table->unsignedInteger('allowance_cents')->nullable()->after('star_cost');
        });
    }

    public function down(): void
    {
        Schema::table('reward_items', fn (Blueprint $t) => $t->dropColumn('allowance_cents'));
        Schema::dropIfExists('allowance_entries');
        Schema::dropIfExists('feed_reactions');
        Schema::dropIfExists('feed_entries');
        Schema::table('tasks', fn (Blueprint $t) => $t->dropColumn(['rotation', 'team_bonus_gold']));
    }
};
