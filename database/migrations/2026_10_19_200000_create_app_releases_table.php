<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Android-APKs für den App-Updater (Verteilung ohne Play Store)
        Schema::create('app_releases', function (Blueprint $table) {
            $table->id();
            $table->string('version_name');
            $table->unsignedInteger('version_code')->unique();
            $table->string('channel')->default('stable');
            $table->string('apk_path');
            $table->string('sha256', 64);
            $table->unsignedBigInteger('size');
            $table->text('changelog')->nullable();
            $table->unsignedInteger('min_supported_code')->default(1);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->unsignedInteger('app_build')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_releases');
        Schema::table('personal_access_tokens', fn (Blueprint $table) => $table->dropColumn('app_build'));
    }
};
