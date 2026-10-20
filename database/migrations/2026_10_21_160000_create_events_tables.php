<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Events (Konzept §12), Zufallsereignisse (§12.5), Zähler für Erfolge und Darstellungs-Einstellungen (Phase 8).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            // Event-Gegenstände fallen und stehen im Shop nur, solange ihr Event in der Familie läuft
            $table->string('event_key', 32)->nullable()->after('min_level')->index();
        });

        Schema::create('family_event_settings', function (Blueprint $table) {
            $table->foreignId('family_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('country', 2)->default('DE');
            $table->string('subdivision', 8)->default('DE-SN');
            $table->json('disabled_events')->nullable();
            $table->json('enabled_events')->nullable();          // standardmäßig abgeschaltete Events, die die Eltern einschalten
            $table->boolean('fun_events')->default(true);
            $table->json('disabled_fun_events')->nullable();
            $table->json('enabled_fun_events')->nullable();
            $table->string('weather_source', 12)->default('none'); // none|ha|open_meteo
            $table->decimal('weather_lat', 4, 1)->nullable();      // auf 0,1° gerundet (§7)
            $table->decimal('weather_lon', 4, 1)->nullable();
            $table->timestamps();
        });

        Schema::create('member_dates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 16)->default('birthday');
            $table->unsignedTinyInteger('month');
            $table->unsignedTinyInteger('day');
            $table->timestamps();
            $table->unique(['user_id', 'type']);
        });

        Schema::create('holiday_periods', function (Blueprint $table) {
            $table->id();
            $table->string('country', 2);
            $table->string('subdivision', 8)->nullable();
            $table->string('type', 8);                            // public|school
            $table->string('name');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('source', 12)->default('openholidays'); // openholidays|manual
            $table->foreignId('family_id')->nullable()->constrained()->cascadeOnDelete(); // manuelle Einträge je Familie
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();
            $table->index(['country', 'subdivision', 'type', 'starts_on']);
        });

        Schema::create('family_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_user_id')->nullable()->constrained('users')->nullOnDelete(); // persönliche Events (Geburtstag, Willkommen zurück)
            $table->string('event_key', 32);
            $table->string('status', 12)->default('scheduled');   // scheduled|active|ending|finished|skipped
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->timestamp('queued_until')->nullable();
            $table->string('trigger_source', 24);
            $table->string('seed', 32);
            $table->json('progress')->nullable();                 // goal, points, tiers
            $table->foreignId('quest_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->unique(['family_id', 'event_key', 'subject_user_id', 'starts_at']);
            $table->index(['status', 'starts_at']);
        });

        Schema::create('event_participations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('points', 10, 2)->default(0);
            $table->json('collected')->nullable();
            $table->timestamp('rewards_claimed_at')->nullable();
            $table->timestamps();
            $table->unique(['family_event_id', 'user_id']);
        });

        Schema::create('event_trigger_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->string('event_key', 32);
            $table->string('trigger', 32);
            $table->string('result', 16);                         // started|scheduled|queued|cooldown|disabled|duplicate|full|conditions|skipped
            $table->json('context')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['family_id', 'event_key']);
        });

        Schema::create('fun_event_instances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete(); // null = für die ganze Familie (wer zuerst …)
            $table->string('key', 32);
            $table->string('seed', 48);
            $table->string('status', 10)->default('open');        // open|claimed|expired
            $table->json('data')->nullable();
            $table->timestamp('visible_from')->nullable();
            $table->timestamp('expires_at');
            $table->foreignId('claimed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamps();
            $table->unique(['seed']);
            $table->index(['family_id', 'status', 'expires_at']);
        });

        // Zähler für Erfolge und Statistik, die sonst nirgends stehen (z. B. Ernten, besiegte Dungeon-Monster)
        Schema::create('user_counters', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('key', 32);
            $table->unsignedInteger('value')->default(0);
            $table->primary(['user_id', 'key']);
        });

        Schema::table('user_support_settings', function (Blueprint $table) {
            $table->string('theme', 24)->nullable();
            $table->boolean('high_contrast')->default(false);
            $table->decimal('text_scale', 3, 2)->default(1);
            $table->boolean('dyslexia_font')->default(false);
            $table->string('color_vision', 12)->default('normal'); // normal|protan|deutan|tritan
        });

        // Event-Punkte und Event-Funde je Abschluss, damit Rückgängig/Ablehnung sie zurücknehmen kann
        Schema::table('task_completions', function (Blueprint $table) {
            $table->json('event_progress')->nullable();          // {family_event_id: Punkte}
            $table->foreignId('event_drop_item_id')->nullable()->constrained('items')->nullOnDelete();
        });

        Schema::table('homes', function (Blueprint $table) {
            $table->unsignedTinyInteger('garden_bonus_slots')->default(0); // Gewächshaus, Erntescheune
        });

        Schema::table('user_stats', function (Blueprint $table) {
            $table->timestamp('sleeping_since')->nullable(); // Gasthaus (Pause), für „Willkommen zurück“
        });
    }

    public function down(): void
    {
        Schema::table('user_stats', fn (Blueprint $table) => $table->dropColumn('sleeping_since'));
        Schema::table('homes', fn (Blueprint $table) => $table->dropColumn('garden_bonus_slots'));
        Schema::table('task_completions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('event_drop_item_id');
            $table->dropColumn('event_progress');
        });
        Schema::table('user_support_settings', function (Blueprint $table) {
            $table->dropColumn(['theme', 'high_contrast', 'text_scale', 'dyslexia_font', 'color_vision']);
        });
        foreach (['user_counters', 'fun_event_instances', 'event_trigger_log', 'event_participations', 'family_events', 'holiday_periods', 'member_dates', 'family_event_settings'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('items', function (Blueprint $table) {
            $table->dropIndex(['event_key']);
            $table->dropColumn('event_key');
        });
    }
};
