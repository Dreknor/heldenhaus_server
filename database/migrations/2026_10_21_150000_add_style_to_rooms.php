<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Eigene Gestaltung je Zimmer: Wand und Boden (Konzept §2.6). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->json('style')->nullable()->after('name');   // {"wall": "wood", "floor": "carpet"}
        });
    }

    public function down(): void
    {
        Schema::table('rooms', fn (Blueprint $t) => $t->dropColumn('style'));
    }
};
