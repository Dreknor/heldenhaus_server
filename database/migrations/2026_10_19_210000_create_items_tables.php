<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Spielinhalte: von Admins im Backend gepflegt, Startbestand aus database/content/items.json
        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('type');                       // egg|hatching_potion|food|material|potion|equipment|furniture
            $table->string('slot')->nullable();           // equipment: head|armor|weapon|shield|accessory
            $table->string('rarity')->default('common');  // common|uncommon|rare|epic|legendary
            $table->string('icon')->default('📦');
            $table->json('stats')->nullable();            // {"str":1,"int":0,"con":0,"per":2}
            $table->unsignedInteger('price_gold')->nullable(); // null = nicht im Shop
            $table->unsignedInteger('drop_weight')->default(0); // 0 = fällt nie als Belohnung
            $table->json('drop_categories')->nullable();  // nur bei Aufgaben dieser Kategorien (null = alle)
            $table->unsignedSmallInteger('min_level')->default(1);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('user_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quantity')->default(0);
            $table->timestamps();
            $table->unique(['user_id', 'item_id']);
        });

        Schema::create('game_settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->json('value');
            $table->timestamps();
        });

        Schema::table('task_completions', function (Blueprint $table) {
            $table->foreignId('drop_item_id')->nullable()->after('stars')->constrained('items')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('task_completions', fn (Blueprint $table) => $table->dropConstrainedForeignId('drop_item_id'));
        Schema::dropIfExists('game_settings');
        Schema::dropIfExists('user_items');
        Schema::dropIfExists('items');
    }
};
