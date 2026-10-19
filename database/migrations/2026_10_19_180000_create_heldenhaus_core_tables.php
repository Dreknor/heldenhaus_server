<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('families', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('login_code', 12)->unique();
            $table->string('timezone')->default('Europe/Berlin');
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        Schema::create('family_invites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('code', 16)->unique();
            $table->string('role');
            $table->string('email')->nullable();
            $table->timestamp('expires_at');
            $table->foreignId('used_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('user_stats', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->decimal('hp', 8, 2)->default(50);
            $table->decimal('mp', 8, 2)->default(10);
            $table->unsignedInteger('xp')->default(0);
            $table->decimal('gold', 12, 2)->default(0);
            $table->unsignedInteger('stars')->default(0);
            $table->unsignedSmallInteger('level')->default(1);
            $table->string('class_key')->nullable();
            $table->timestamp('class_changed_at')->nullable();
            $table->unsignedSmallInteger('str')->default(0);
            $table->unsignedSmallInteger('int')->default(0);
            $table->unsignedSmallInteger('con')->default(0);
            $table->unsignedSmallInteger('per')->default(0);
            $table->unsignedSmallInteger('unspent_points')->default(0);
            $table->unsignedSmallInteger('streak_shields')->default(0);
            $table->boolean('sleeping')->default(false);
            $table->date('last_cron_on')->nullable();
            $table->timestamps();
        });

        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('scope');                    // personal|family
            $table->string('type');                     // habit|daily|todo|reward
            $table->string('title');
            $table->text('notes')->nullable();
            $table->string('category')->default('household');
            $table->decimal('difficulty', 3, 1)->default(1);
            $table->boolean('up')->default(true);       // habits
            $table->boolean('down')->default(false);    // habits
            $table->json('repeat')->nullable();          // dailies
            $table->date('start_date')->nullable();
            $table->date('due_date')->nullable();        // todos
            $table->json('checklist')->nullable();
            $table->boolean('is_private')->default(false);
            $table->string('approval_mode')->default('none'); // none|required|photo
            $table->string('assignment_mode')->nullable();    // assigned|pool|rotation|team
            $table->unsignedInteger('star_reward')->default(0);
            $table->unsignedInteger('gold_cost')->default(0);   // personal rewards
            $table->string('ha_event_key')->nullable()->index();
            $table->unsignedInteger('sort')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        // Per-person state of a task. Personal tasks have exactly one row (the owner).
        Schema::create('task_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->double('value')->default(0);
            $table->unsignedInteger('streak')->default(0);
            $table->boolean('completed')->default(false);
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('counter_up')->default(0);
            $table->unsignedInteger('counter_down')->default(0);
            $table->json('checklist_done')->nullable();
            $table->timestamps();
            $table->unique(['task_id', 'user_id']);
        });

        Schema::create('task_completions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assignment_id')->nullable()->constrained('task_assignments')->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('direction');                 // up|down|purchase
            $table->double('value_before')->default(0);
            $table->double('delta')->default(0);
            $table->integer('xp')->default(0);
            $table->decimal('gold', 10, 2)->default(0);
            $table->decimal('hp', 8, 2)->default(0);
            $table->unsignedInteger('stars')->default(0);
            $table->unsignedInteger('streak_before')->default(0);
            $table->string('status')->default('none');   // none|pending|approved|auto|rejected|undone
            $table->string('source')->default('web');    // web|app|ha
            $table->string('proof_path')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_comment')->nullable();
            $table->uuid('client_uuid')->nullable()->unique();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });

        Schema::create('day_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedSmallInteger('dailies_due')->default(0);
            $table->unsignedSmallInteger('dailies_done')->default(0);
            $table->decimal('damage', 8, 2)->default(0);
            $table->boolean('knocked_out')->default(false);
            $table->boolean('sleeping')->default(false);
            $table->timestamps();
            $table->unique(['user_id', 'date']);
        });

        Schema::create('reward_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('icon')->default('gift');
            $table->unsignedInteger('star_cost');
            $table->unsignedSmallInteger('limit_per_week')->nullable();
            $table->boolean('requires_approval')->default(true);
            $table->string('ha_action_key')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('reward_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reward_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('stars');
            $table->string('status')->default('pending'); // pending|approved|rejected
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_comment')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['reward_redemptions', 'reward_items', 'day_logs', 'task_completions', 'task_assignments', 'tasks', 'user_stats', 'family_invites', 'families'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
