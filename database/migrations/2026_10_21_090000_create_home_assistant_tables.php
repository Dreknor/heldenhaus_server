<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ausgehende Webhooks an Home Assistant (Konzept §4.2 Punkt 3), einer pro Familie
        Schema::create('ha_webhooks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('url', 500);
            $table->text('secret');                     // verschlüsselt (Cast „encrypted“)
            $table->json('events')->nullable();          // null = alle Ereignisse
            $table->boolean('active')->default(true);
            $table->timestamp('last_sent_at')->nullable();
            $table->unsignedSmallInteger('last_status')->nullable();
            $table->string('last_error')->nullable();
            $table->unsignedSmallInteger('failures')->default(0);
            $table->timestamps();
        });

        // Prüfprotokoll aller Aktionen über Home Assistant (Konzept §4.5)
        Schema::create('ha_audit_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->string('direction', 8);              // in|out
            $table->string('action');                    // z. B. POST ha/events, webhook:reward_redeemed
            $table->foreignId('member_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('status')->nullable();
            $table->json('details')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['family_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ha_audit_log');
        Schema::dropIfExists('ha_webhooks');
    }
};
