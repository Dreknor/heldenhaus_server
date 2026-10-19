<?php

namespace Database\Seeders;

use App\Domain\Family\FamilyService;
use App\Domain\Tasks\TaskService;
use App\Models\User;
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
    }
}
