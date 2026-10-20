<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // FCM-Geräte (Konzept §5.1/§8). Hängt am App-Token: Abmelden oder Widerruf des Geräts beendet auch den Push.
        Schema::create('device_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('personal_access_token_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('token')->unique();
            $table->string('platform', 16)->default('android');
            $table->string('app_version', 32)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        Schema::create('notification_settings', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->boolean('push_enabled')->default(true);
            $table->string('quiet_start', 5)->nullable();   // „20:00“ in der Zeitzone der Person
            $table->string('quiet_end', 5)->nullable();     // „07:00“
            $table->json('muted')->nullable();              // abgeschaltete Arten, z. B. ["level_up"]
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_settings');
        Schema::dropIfExists('device_tokens');
    }
};
