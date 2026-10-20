<?php

namespace Database\Seeders;

use App\Domain\Family\FamilyService;
use App\Domain\Inventory\InventoryService;
use App\Domain\Tasks\TaskService;
use App\Models\Item;
use App\Models\User;
use App\Models\UserItem;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

/**
 * Demo-Familie für die lokale Entwicklung:
 *   Eltern:  mama@heldenhaus.test / password
 *   Kinder:  Familiencode DEMO01, Lea (10) PIN 1234, Ben (14) PIN 4321
 */
class DatabaseSeeder extends Seeder
{
    public function run(FamilyService $families, TaskService $tasks): void
    {
        Artisan::call('game:content-sync');

        $mama = User::create([
            'name' => 'Mama',
            'email' => 'mama@heldenhaus.test',
            'password' => 'password',
            'email_verified_at' => now(),
            'avatar_color' => '#e11d48',
            'day_start' => 4,
        ]);
        $mama->forceFill(['is_admin' => true])->save();

        $family = $families->createFamily($mama, 'Familie Demo');
        $family->update(['login_code' => 'DEMO01']);

        $lea = $families->addChild($mama->refresh(), 'Lea', now()->year - 10, '1234');
        $lea->update(['avatar_color' => '#f59e0b']);
        $ben = $families->addChild($mama, 'Ben', now()->year - 14, '4321');
        $ben->update(['avatar_color' => '#0ea5e9']);

        // Persönliche Aufgaben
        foreach ([$lea, $ben] as $kid) {
            $tasks->create($kid, ['type' => 'daily', 'title' => 'Zähne putzen', 'category' => 'others', 'difficulty' => 0.1, 'repeat' => ['type' => 'daily', 'every' => 1]]);
            $tasks->create($kid, ['type' => 'daily', 'title' => 'Hausaufgaben', 'category' => 'school', 'difficulty' => 1.5, 'repeat' => ['type' => 'weekly', 'days' => [1, 2, 3, 4, 5]]]);
            $tasks->create($kid, ['type' => 'habit', 'title' => '20 Minuten lesen', 'category' => 'school', 'difficulty' => 1, 'up' => true, 'down' => false]);
            $tasks->create($kid, ['type' => 'habit', 'title' => 'Bildschirmzeit überzogen', 'category' => 'others', 'difficulty' => 1, 'up' => false, 'down' => true]);
            $tasks->create($kid, ['type' => 'reward', 'title' => 'Lieblingsmusik laut hören', 'category' => 'creative', 'difficulty' => 1, 'gold_cost' => 15]);
        }
        $tasks->create($ben, ['type' => 'todo', 'title' => 'Referat vorbereiten', 'category' => 'school', 'difficulty' => 2, 'due_date' => now()->addDays(5)->toDateString()]);
        $tasks->create($mama, ['type' => 'daily', 'title' => 'Sport', 'category' => 'sport', 'difficulty' => 1.5, 'repeat' => ['type' => 'weekly', 'days' => [2, 4, 6]]]);

        // Familienaufgaben
        $tasks->create($mama, [
            'scope' => 'family', 'type' => 'daily', 'title' => 'Spülmaschine ausräumen', 'category' => 'household', 'difficulty' => 1,
            'repeat' => ['type' => 'daily', 'every' => 1], 'assignment_mode' => 'assigned', 'assignees' => [$ben->id],
            'approval_mode' => 'none', 'star_reward' => 1, 'ha_event_key' => 'dishwasher_done',
        ]);
        $tasks->create($mama, [
            'scope' => 'family', 'type' => 'daily', 'title' => 'Katze füttern', 'category' => 'nature', 'difficulty' => 1,
            'repeat' => ['type' => 'daily', 'every' => 1], 'assignment_mode' => 'assigned', 'assignees' => [$lea->id],
            'approval_mode' => 'none', 'star_reward' => 1, 'ha_event_key' => 'nfc_feed_cat',
        ]);
        $tasks->create($mama, [
            'scope' => 'family', 'type' => 'todo', 'title' => 'Zimmer aufräumen', 'category' => 'household', 'difficulty' => 1.5,
            'assignment_mode' => 'assigned', 'assignees' => [$lea->id, $ben->id], 'approval_mode' => 'required', 'star_reward' => 3,
        ]);
        $tasks->create($mama, [
            'scope' => 'family', 'type' => 'todo', 'title' => 'Auto aussaugen', 'category' => 'household', 'difficulty' => 2,
            'assignment_mode' => 'pool', 'approval_mode' => 'required', 'star_reward' => 5,
        ]);

        // Belohnungsshop
        $family->rewardItems()->createMany([
            ['title' => '30 Minuten Konsole', 'star_cost' => 5, 'limit_per_week' => 3, 'ha_action_key' => 'screen_time_30'],
            ['title' => 'Kino-Abend: Film aussuchen', 'star_cost' => 15],
            ['title' => 'Einmal Spülen aussetzen', 'star_cost' => 8, 'limit_per_week' => 1],
            ['title' => 'Eis essen gehen', 'star_cost' => 20],
        ]);

        $this->demoInventory($lea, $ben);
    }

    /** Ein paar Funde, Ausrüstung und Haustiere, damit die Grafiken sofort sichtbar sind. */
    private function demoInventory(User $lea, User $ben): void
    {
        $give = function (User $user, array $items): void {
            foreach ($items as $key => $quantity) {
                $item = Item::where('key', $key)->first();
                if ($item) {
                    UserItem::updateOrCreate(['user_id' => $user->id, 'item_id' => $item->id], ['quantity' => $quantity]);
                }
            }
        };

        $give($lea, ['egg_cat' => 1, 'potion_ice' => 1, 'food_apple' => 3, 'feather' => 4, 'herbs' => 2, 'potion_health' => 1, 'head_cap' => 1]);
        $give($ben, ['egg_wolf' => 1, 'potion_fire' => 2, 'wood' => 5, 'stone' => 3, 'ink' => 2, 'crystal_shard' => 1, 'food_cake' => 1, 'weapon_wooden_sword' => 1, 'shield_lid' => 1]);

        $inventory = app(InventoryService::class);
        $inventory->equip($lea, Item::where('key', 'head_cap')->first());
        $inventory->equip($ben, Item::where('key', 'weapon_wooden_sword')->first());
        $inventory->equip($ben, Item::where('key', 'shield_lid')->first());
        $inventory->hatch($ben, Item::where('key', 'egg_wolf')->first(), Item::where('key', 'potion_fire')->first());

        $lea->update(['avatar' => ['skin' => '#f6c89f', 'hair' => 'ponytail', 'hair_color' => '#a0522d', 'eyes' => 'happy', 'outfit' => '#e84393', 'background' => '#fde68a']]);
        $ben->update(['avatar' => ['skin' => '#8d5a33', 'hair' => 'spiky', 'hair_color' => '#2b1d14', 'eyes' => 'round', 'outfit' => '#3498db', 'background' => '#bbf7d0']]);
    }
}
