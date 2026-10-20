<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Freischaltung von Funktionen nach Level (Konzept §13). Wer eine Funktion schon genutzt hat,
 * behält sie (source = legacy).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feature_unlocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('feature_key', 32);
            $table->timestamp('unlocked_at');
            $table->string('source', 12);                    // level|parent|self|fast_start|legacy
            $table->timestamp('intro_shown_at')->nullable();
            $table->timestamp('intro_seen_at')->nullable();
            $table->timestamp('starter_done_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'feature_key']);
        });

        Schema::create('member_feature_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('feature_key', 32);
            $table->string('mode', 8);                       // early|defer
            $table->foreignId('set_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'feature_key']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('show_all_features')->default(false);
            $table->timestamp('fast_start_at')->nullable();  // Schnellstart: eine Freischaltung pro Tag bis Level 6
        });

        $this->legacy();
    }

    /** Bereits Genutztes bleibt freigeschaltet. */
    private function legacy(): void
    {
        $now = now();
        $sources = [
            'attributes' => DB::table('user_stats')->where(fn ($q) => $q->where('str', '>', 0)->orWhere('int', '>', 0)->orWhere('con', '>', 0)->orWhere('per', '>', 0))->pluck('user_id'),
            'shop' => DB::table('user_equipment')->distinct()->pluck('user_id'),
            'pets' => DB::table('user_pets')->distinct()->pluck('user_id'),
            'quests' => DB::table('quests')->where('scope', 'solo')->whereNotNull('user_id')->distinct()->pluck('user_id'),
            'classes' => DB::table('user_stats')->whereNotNull('class_key')->pluck('user_id'),
            'home' => DB::table('placed_items')->join('rooms', 'rooms.id', '=', 'placed_items.room_id')->whereNotNull('rooms.owner_user_id')->distinct()->pluck('rooms.owner_user_id'),
            'garden' => DB::table('garden_plots')->whereNotNull('sown_by')->distinct()->pluck('sown_by'),
            'crafting' => DB::table('craft_jobs')->distinct()->pluck('user_id'),
            'building' => DB::table('build_contributions')->distinct()->pluck('user_id'),
            'dungeons' => DB::table('dungeon_positions')->distinct()->pluck('user_id'),
            'alliances' => DB::table('guestbook_entries')->distinct()->pluck('user_id'),
        ];

        foreach ($sources as $feature => $userIds) {
            foreach ($userIds->filter()->unique() as $userId) {
                DB::table('feature_unlocks')->insertOrIgnore([
                    'user_id' => $userId, 'feature_key' => $feature, 'unlocked_at' => $now, 'source' => 'legacy',
                    'intro_shown_at' => $now, 'intro_seen_at' => $now, 'starter_done_at' => $now, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['show_all_features', 'fast_start_at']));
        Schema::dropIfExists('member_feature_overrides');
        Schema::dropIfExists('feature_unlocks');
    }
};
