<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Langzeitmotivation (Konzept §14.5, §14.6, §14.10): Aktivitätswerte, Rückblicke, Umfragen und Kennzahlen.
 * Alles bleibt in der eigenen Datenbank (§7).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Tagespunkte je Person (Boss-Schaden-Formel) – Grundlage für Aktivitätswert und Rückblick
        Schema::create('activity_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('family_id')->nullable()->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->decimal('points', 8, 2)->default(0);
            $table->unsignedSmallInteger('units')->default(0);   // Sammel-Einheiten (schwere Aufgaben zählen doppelt)
            $table->unsignedSmallInteger('tasks')->default(0);
            $table->boolean('sleeping')->default(false);          // Gasthaus-Tag: zählt nicht für den Aktivitätswert
            $table->timestamps();
            $table->unique(['user_id', 'date']);
            $table->index(['family_id', 'date']);
        });

        // Wochen-, Monats- und Jahresrückblicke (einmal erzeugt, danach unverändert)
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('family_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('kind');                 // week|month|year
            $table->date('period_start');
            $table->date('period_end');
            $table->json('data');
            $table->timestamp('seen_at')->nullable();
            $table->timestamp('shared_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'kind', 'period_start']);
        });

        // Ausgangslage (Eltern, 3 Fragen 1–5) und Stimmungs-Check (alle Mitglieder, 5 Smileys)
        Schema::create('surveys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->string('kind');                 // baseline|mood
            $table->date('date');
            $table->json('answers')->nullable();    // null = übersprungen
            $table->timestamps();
            $table->index(['family_id', 'kind', 'date']);
            $table->index(['user_id', 'kind']);
        });

        // Kennzahlen je Familie und Tag (nächtlich zusammengefasst)
        Schema::create('family_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->json('data');
            $table->timestamps();
            $table->unique(['family_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('family_metrics');
        Schema::dropIfExists('surveys');
        Schema::dropIfExists('reviews');
        Schema::dropIfExists('activity_scores');
    }
};
