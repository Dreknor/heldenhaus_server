<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Im Admin-Backend geänderte Inhalte von Events und Zufallsereignissen (§12.6). Gespeichert werden nur die
     * geänderten Felder; EventCatalog legt sie über events.json bzw. fun_events.json, die Dateien bleiben unverändert.
     */
    public function up(): void
    {
        Schema::create('event_overrides', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 8); // event | fun
            $table->string('key', 64);
            $table->json('data');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['kind', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_overrides');
    }
};
