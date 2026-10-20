<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 6 – Crafting & Housing (Konzept §2.5, §2.6, §5.3). Rezepte und Bauprojekte liegen als JSON in database/content. */
return new class extends Migration
{
    public function up(): void
    {
        // ---------- Crafting ----------
        Schema::create('user_crafting', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('level')->default(1);
            $table->unsignedInteger('xp')->default(0);
            $table->timestamps();
        });

        // Zusätzlich freigeschaltete Rezepte (über Quests, Drops, Bibliothek); levelbasierte sind immer frei
        Schema::create('user_recipes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('recipe_key');
            $table->string('source', 16)->default('library');
            $table->timestamps();
            $table->unique(['user_id', 'recipe_key']);
        });

        Schema::create('craft_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('recipe_key');
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->string('quality', 8)->default('normal');   // normal|good|master
            $table->timestamp('started_at');
            $table->timestamp('finishes_at');
            $table->string('status', 12)->default('running');   // running|ready|collected
            $table->boolean('notified')->default(false);
            $table->timestamp('collected_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'finishes_at']);
        });

        Schema::table('user_items', function (Blueprint $table) {
            $table->unsignedInteger('master_quantity')->default(0)->after('quantity'); // davon meisterhaft
        });

        Schema::create('family_storage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quantity')->default(0);
            $table->timestamps();
            $table->unique(['family_id', 'item_id']);
        });

        // Tausch nur mit Bündnis-Familien, nur Gegenstände (kein Gold, keine Sterne)
        Schema::create('trade_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->foreignId('alliance_id')->constrained('family_alliances')->cascadeOnDelete();
            $table->json('offer');                          // {"item_id": menge}
            $table->json('want');
            $table->string('status', 24);                    // awaiting_parent|open|awaiting_partner_parent|done|cancelled|rejected
            $table->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        // ---------- Housing ----------
        Schema::create('homes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('stage', 12)->default('hut');      // hut|timber|manor|castle
            $table->json('stations')->nullable();             // {"workbench": 1, "forge": 0, …}
            $table->timestamps();
        });

        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('home_id')->constrained()->cascadeOnDelete();
            $table->string('type', 16);                       // living|bedroom|workshop|stable|library|storage|garden|trophy
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->unsignedTinyInteger('width')->default(10);
            $table->unsignedTinyInteger('height')->default(6);
            $table->unsignedInteger('version')->default(0);   // gegen gleichzeitiges Bearbeiten
            $table->timestamps();
        });

        Schema::create('placed_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('placed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('x');
            $table->unsignedTinyInteger('y');
            $table->string('layer', 8)->default('floor');     // floor|wall|ceiling
            $table->unsignedSmallInteger('rotation')->default(0);
            $table->boolean('mirrored')->default(false);
            $table->boolean('master')->default(false);
            $table->timestamps();
        });

        Schema::create('build_projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->string('project_key');
            $table->json('requirements');                    // {"items": {"wood": 20}, "gold": 50}
            $table->json('progress')->nullable();            // {"items": {"wood": 12}, "gold": 30}
            $table->string('status', 12)->default('collecting'); // collecting|building|done|cancelled
            $table->string('reward_text')->nullable();       // reales Familienziel
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finishes_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('build_contributions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('build_project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('item_key')->nullable();          // null = Gold
            $table->unsignedInteger('amount');
            $table->timestamps();
        });

        Schema::create('garden_plots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('slot');
            $table->string('plant_key')->nullable();
            $table->foreignId('sown_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sown_at')->nullable();
            $table->date('watered_on')->nullable();
            $table->unsignedTinyInteger('waterings')->default(0);
            $table->timestamp('ready_at')->nullable();
            $table->timestamps();
            $table->unique(['family_id', 'slot']);
        });

        Schema::create('guestbook_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();   // besuchtes Haus
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 8);                       // text|preset|emoji
            $table->string('body', 300);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['guestbook_entries', 'garden_plots', 'build_contributions', 'build_projects', 'placed_items', 'rooms', 'homes', 'trade_offers', 'family_storage'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('user_items', fn (Blueprint $t) => $t->dropColumn('master_quantity'));
        Schema::dropIfExists('craft_jobs');
        Schema::dropIfExists('user_recipes');
        Schema::dropIfExists('user_crafting');
    }
};
