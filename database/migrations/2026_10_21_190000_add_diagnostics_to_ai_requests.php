<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_requests', function (Blueprint $table) {
            $table->longText('response_payload')->nullable();
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->string('finish_reason', 40)->nullable();
            $table->text('error_message')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ai_requests', function (Blueprint $table) {
            $table->dropColumn(['response_payload', 'response_status', 'finish_reason', 'error_message']);
        });
    }
};
