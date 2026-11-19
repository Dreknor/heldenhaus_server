<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ziele und Wochenziele (§14.1), Erinnerungen (§14.2), Meistern und Ausschleichen (§14.3).
     */
    public function up(): void
    {
        Schema::create('goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 12); // weekly | milestone | count
            $table->string('source', 12)->default('own'); // own | mastery | council
            $table->string('template_key', 80)->nullable();
            $table->string('title', 120);
            $table->string('why', 200);
            $table->string('icon', 16)->default('🎯');
            $table->string('category', 20)->nullable();
            $table->unsignedSmallInteger('target')->nullable();
            $table->string('unit', 30)->nullable();
            $table->unsignedInteger('manual_progress')->default(0);
            $table->date('ends_on')->nullable();
            $table->string('status', 12)->default('active'); // active | reached | expired | released
            $table->boolean('is_private')->default(false);
            $table->unsignedSmallInteger('reward_stars')->default(0);
            $table->string('reward_wish', 120)->nullable();
            $table->unsignedSmallInteger('weeks_reached')->default(0);
            $table->timestamp('reached_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        Schema::create('goal_milestones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goal_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('position');
            $table->string('title', 120);
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('done_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('goal_links', function (Blueprint $table) {
            $table->foreignId('goal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->primary(['goal_id', 'task_id']);
        });

        Schema::create('goal_supporters', function (Blueprint $table) {
            $table->foreignId('goal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['goal_id', 'user_id']);
        });

        Schema::create('goal_weeks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goal_id')->constrained()->cascadeOnDelete();
            $table->date('week_start');
            $table->unsignedTinyInteger('done');
            $table->unsignedTinyInteger('target');
            $table->boolean('reached');
            $table->timestamps();
            $table->unique(['goal_id', 'week_start']);
        });

        Schema::table('tasks', function (Blueprint $table) {
            // §14.2 Erinnerungen
            $table->string('anchor', 80)->nullable();
            $table->time('remind_at')->nullable();
            $table->boolean('remind_ha')->default(false);
            // „Aufgabe pausieren“: bis zu diesem Tag nicht fällig, keine Erinnerung, kein Schaden
            $table->date('paused_until')->nullable();
            // §14.3 Meistern
            $table->timestamp('mastered_at')->nullable();
            $table->date('mastery_check_until')->nullable();
            $table->date('mastery_checked_on')->nullable();
            $table->date('mastery_offer_after')->nullable();
            $table->timestamp('mastery_offered_at')->nullable();
            // §14.3 Ausschleichen („Ehrensache“)
            $table->unsignedSmallInteger('star_fade_base')->nullable();
            $table->date('star_fade_from')->nullable();
            $table->unsignedTinyInteger('star_fade_step')->default(0);
            $table->timestamp('star_fade_offered_at')->nullable();
        });

        Schema::table('task_assignments', function (Blueprint $table) {
            $table->unsignedTinyInteger('ignored_reminders')->default(0);
            $table->timestamp('reminder_snoozed_until')->nullable();
            $table->boolean('reminder_question')->default(false);
        });

        Schema::create('reminder_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_id')->constrained('task_assignments')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->timestamp('sent_at');
            $table->boolean('completed')->default(false);
            $table->boolean('evaluated')->default(false);
            $table->timestamps();
            $table->unique(['assignment_id', 'date']);
            $table->index(['user_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reminder_logs');
        Schema::table('task_assignments', function (Blueprint $table) {
            $table->dropColumn(['ignored_reminders', 'reminder_snoozed_until', 'reminder_question']);
        });
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn([
                'anchor', 'remind_at', 'remind_ha', 'paused_until', 'mastered_at', 'mastery_check_until', 'mastery_checked_on', 'mastery_offer_after',
                'mastery_offered_at', 'star_fade_base', 'star_fade_from', 'star_fade_step', 'star_fade_offered_at',
            ]);
        });
        Schema::dropIfExists('goal_weeks');
        Schema::dropIfExists('goal_supporters');
        Schema::dropIfExists('goal_links');
        Schema::dropIfExists('goal_milestones');
        Schema::dropIfExists('goals');
    }
};
