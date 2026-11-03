<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Gewählter Begleiter (Haustier oder Reittier) für die Heldenszene. */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('companion_pet_id')->nullable()->constrained('user_pets')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('companion_pet_id');
        });
    }
};
