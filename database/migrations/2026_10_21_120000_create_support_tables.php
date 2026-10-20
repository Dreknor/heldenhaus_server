<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 4a – Fokus & Alltagshilfen (Konzept §11.5). Es wird keine Diagnose gespeichert. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_support_settings', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->boolean('gentle_mode')->nullable();          // null = Standard der Familie
            $table->boolean('focus_mode_default')->default(false);
            $table->string('feedback_level', 8)->default('full'); // full|subtle|haptic
            $table->boolean('reduce_motion')->default(false);
            $table->boolean('sound')->default(true);
            $table->boolean('haptics')->default(true);
            $table->string('timer_style', 8)->default('disc');   // disc|digits
            $table->unsignedTinyInteger('max_steps')->default(7);
            $table->unsignedTinyInteger('low_energy_per_week')->default(2);
            $table->boolean('ai_steps')->default(false);          // für Kinder unter 13 zusätzlich von Eltern freizugeben
            $table->foreignId('set_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->string('status', 8)->nullable()->after('type');      // inbox = Kopf-Ablage, noch nicht einsortiert
            $table->boolean('is_core')->default(false)->after('repeat');  // Kern-Daily für den Energiespar-Tag
        });
        // Einträge der Kopf-Ablage haben noch keine Kategorie
        Schema::table('tasks', function (Blueprint $table) {
            $table->string('category')->nullable()->default('household')->change();
        });

        Schema::table('task_assignments', function (Blueprint $table) {
            $table->timestamp('streak_paused_at')->nullable()->after('streak');
            $table->unsignedInteger('streak_best')->default(0)->after('streak');
        });

        Schema::table('task_completions', function (Blueprint $table) {
            $table->json('surprise')->nullable()->after('effects');
        });

        Schema::table('user_stats', function (Blueprint $table) {
            $table->unsignedSmallInteger('surprise_pity')->default(0);
            $table->unsignedTinyInteger('surprises_today')->default(0);
            $table->date('surprises_on')->nullable();
        });

        Schema::table('day_logs', function (Blueprint $table) {
            $table->boolean('low_energy')->default(false);
            $table->boolean('gentle')->default(false);
        });

        Schema::create('low_energy_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->foreignId('set_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'date']);
        });

        Schema::create('recovery_quests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assignment_id')->constrained('task_assignments')->cascadeOnDelete();
            $table->unsignedTinyInteger('needed');
            $table->unsignedTinyInteger('done')->default(0);
            $table->unsignedInteger('streak_to_restore');
            $table->date('expires_on');
            $table->string('status')->default('active');   // active|restored|failed
            $table->timestamps();
        });

        Schema::create('focus_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('planned_minutes');
            $table->timestamp('started_at');
            $table->timestamp('ends_at');
            $table->timestamp('ended_at')->nullable();
            $table->string('result', 8)->nullable();         // done|aborted
            $table->uuid('group_uuid')->nullable()->index();  // gemeinsame Fokus-Zeit
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('bonus_gold', 6, 2)->default(0);
            $table->timestamps();
        });

        // Gespeicherte Schritte der Familie („Zimmer aufräumen“ → …), damit beim nächsten Mal keine KI nötig ist
        Schema::create('step_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->string('title_key');
            $table->json('steps');
            $table->string('source', 8)->default('family');   // family|ai
            $table->timestamps();
            $table->unique(['family_id', 'title_key']);
        });

        // Nur Verbrauch und Kosten, keine Inhalte (Budget und Prüfprotokoll)
        Schema::create('ai_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('purpose', 32);
            $table->string('model', 100)->nullable();
            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            $table->decimal('cost_usd', 10, 6)->default(0);
            $table->boolean('ok')->default(true);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_requests');
        Schema::dropIfExists('step_templates');
        Schema::dropIfExists('focus_sessions');
        Schema::dropIfExists('recovery_quests');
        Schema::dropIfExists('low_energy_days');
        Schema::table('day_logs', fn (Blueprint $t) => $t->dropColumn(['low_energy', 'gentle']));
        Schema::table('user_stats', fn (Blueprint $t) => $t->dropColumn(['surprise_pity', 'surprises_today', 'surprises_on']));
        Schema::table('task_completions', fn (Blueprint $t) => $t->dropColumn('surprise'));
        Schema::table('task_assignments', fn (Blueprint $t) => $t->dropColumn(['streak_paused_at', 'streak_best']));
        Schema::table('tasks', fn (Blueprint $t) => $t->dropColumn(['status', 'is_core']));
        Schema::dropIfExists('user_support_settings');
    }
};
