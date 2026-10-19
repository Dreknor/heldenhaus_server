<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_updates', function (Blueprint $table) {
            $table->id();
            $table->string('from_version');
            $table->string('to_version');
            $table->string('channel')->default('stable');
            $table->string('status')->default('pending');
            $table->string('step')->nullable();
            $table->json('manifest')->nullable();
            $table->json('log')->nullable();
            $table->string('package_path')->nullable();
            $table->string('release_path')->nullable();
            $table->string('previous_release_path')->nullable();
            $table->string('backup_path')->nullable();
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_updates');
    }
};
