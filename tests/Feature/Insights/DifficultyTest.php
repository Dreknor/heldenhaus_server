<?php

use App\Domain\Gameplay\GameException;
use App\Domain\Gameplay\RunDayCron;
use App\Domain\Gameplay\ScoreTask;
use App\Domain\Insights\ActivityScores;
use App\Domain\Insights\Difficulty;
use App\Domain\Quests\QuestService;
use App\Models\ActivityScore;
use App\Models\Quest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;

/** Eigene Tagespunkte für die letzten 14 Tage eintragen (sleeping: Gasthaus-Tage). */
function giveActivity(User $user, float $points, int $sleepingDays = 0, int $units = 3): void
{
    $yesterday = $user->gameDate()->subDay();
    for ($i = 0; $i < 14; $i++) {
        ActivityScore::updateOrCreate(
            ['user_id' => $user->id, 'date' => $yesterday->subDays($i)->toDateString()],
            ['family_id' => $user->family_id, 'points' => $points, 'units' => $units, 'tasks' => 3, 'sleeping' => $i < $sleepingDays],
        );
    }
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00', 'Europe/Berlin'));
    [$this->family, $this->parent, $this->child] = makeFamily();
    $this->quests = app(QuestService::class);
});

it('berechnet Boss-HP aus Aktivitätswert, Quest-Tagen und Faktor der gewählten Stufe', function () {
    giveActivity($this->child, 20);   // Kind mit eigenen Daten, Elternteil mit Standardwert (Erwachsene: 12)

    $normal = $this->quests->start($this->parent, 'dust_monster', 'family');
    expect($normal->goal)->toBe(146.0)                       // (20 + 12) × 7 × 0,65 = 145,6
        ->and($normal->data['difficulty'])->toBe('normal')
        ->and($normal->rewards['gold'])->toBe(20);
    $this->quests->abandon($this->parent, $normal);

    $hard = $this->quests->start($this->parent, 'dust_monster', 'family', null, 'hard');
    expect($hard->goal)->toBe(179.0)                         // × 0,8
        ->and($hard->rewards)->toMatchArray(['gold' => 30, 'xp' => 45, 'item' => 'food_cake']); // bessere Beute
    $this->quests->abandon($this->parent, $hard);

    expect($this->quests->start($this->parent, 'dust_monster', 'family', null, 'easy')->goal)->toBe(112.0); // × 0,5
});

it('hält die Unter- und Obergrenzen aus quests.json ein', function () {
    giveActivity($this->child, 500);
    expect($this->quests->start($this->child, 'boredom_ghost', 'solo')->goal)->toBe(200.0); // hp_max = 2 × 100
    Quest::query()->update(['status' => 'abandoned']);

    giveActivity($this->child, 1);
    expect($this->quests->start($this->child, 'boredom_ghost', 'solo')->goal)->toBe(50.0); // hp_min
});

it('nutzt ohne eigene Aktivitätsdaten den Katalogwert, Gasthaus-Tage zählen nicht', function () {
    // Nur Gasthaus-Tage: keine eigenen Daten → Katalogwert × Faktor/0,65
    giveActivity($this->child, 40, sleepingDays: 14);
    expect(app(ActivityScores::class)->value($this->child)['own'])->toBeFalse()
        ->and($this->quests->start($this->child, 'boredom_ghost', 'solo', null, 'hard')->goal)->toBe(123.0); // 100 × 0,8/0,65
});

it('rechnet Sammel-Quests genauso und legt die Ziele nur beim Start fest', function () {
    giveActivity($this->child, 20, units: 8);
    $quest = $this->quests->start($this->child, 'collect_stars', 'solo'); // 5 Tage, keine Kategorien
    expect($quest->goal)->toBe(26.0); // 8 × 5 × 0,65

    giveActivity($this->child, 80, units: 20);
    $task = makeTask($this->child);
    app(ScoreTask::class)(assignmentOf($task, $this->child), 'up');
    $this->travel(1)->days();
    app(RunDayCron::class)($this->child->refresh());

    expect($quest->refresh()->goal)->toBe(26.0)->and($quest->progress)->toBe(1.0);
});

it('zeigt auf der Quest-Tafel Ziele je Stufe und nimmt die Stufe über Web und API an', function () {
    giveActivity($this->child, 20);
    $offer = $this->quests->offers($this->child, 'solo')[0];
    expect($offer['goals'])->toHaveKeys(['easy', 'normal', 'hard'])
        ->and($offer['goals']['easy'])->toBeLessThanOrEqual($offer['goals']['hard']);

    $this->actingAs($this->parent)->post('/quests', ['quest' => 'chaos_dragon', 'scope' => 'family', 'difficulty' => 'easy'])->assertSessionHasNoErrors();
    expect(Quest::first()->data['difficulty'])->toBe('easy');
    $this->get('/quests')->assertInertia(fn ($page) => $page->has('difficulties', 3)->where('family.difficulty', 'easy'));

    Sanctum::actingAs($this->child, ['app']);
    $this->postJson('/api/v1/quests', ['quest' => 'boredom_ghost', 'scope' => 'solo', 'difficulty' => 'hard'])
        ->assertCreated()->assertJsonPath('difficulty', 'hard');
    $this->postJson('/api/v1/quests', ['quest' => 'collect_stars', 'scope' => 'solo', 'difficulty' => 'brutal'])->assertUnprocessable();
    expect(fn () => $this->quests->start($this->child, 'collect_stars', 'solo', null, 'brutal'))->toThrow(GameException::class);
});

it('schlägt nach zwei knappen Niederlagen „leicht“ vor, nach drei deutlichen Siegen „schwer“', function () {
    $quest = fn (array $a) => Quest::create([
        'family_id' => $this->family->id, 'scope' => 'family', 'type' => 'boss', 'quest_key' => 'dust_monster', 'name' => 'Staubmonster',
        'icon' => '👾', 'goal' => 100, 'progress' => 0, 'status' => 'active', 'ends_at' => now()->addDays(7), ...$a,
    ]);

    $quest(['status' => 'expired', 'progress' => 40]);   // deutlich verloren
    $quest(['status' => 'expired', 'progress' => 75]);   // knapp
    expect(Difficulty::suggestion($this->family->id, 'family'))->toBeNull();

    $quest(['status' => 'expired', 'progress' => 90]);   // zweimal knapp hintereinander
    expect(Difficulty::suggestion($this->family->id, 'family')['difficulty'])->toBe('easy');
    $this->actingAs($this->parent)->get('/quests')->assertInertia(fn ($page) => $page->where('familySuggestion.difficulty', 'easy'));

    foreach (range(1, 3) as $i) {
        $quest(['status' => 'completed', 'progress' => 100, 'completed_at' => now()->addDays(2)]); // nach 2 von 7 Tagen
    }
    expect(Difficulty::suggestion($this->family->id, 'family')['difficulty'])->toBe('hard');

    $quest(['status' => 'completed', 'progress' => 100, 'completed_at' => now()->addDays(6)]); // knapper Sieg
    expect(Difficulty::suggestion($this->family->id, 'family'))->toBeNull();
});

it('berechnet Event-Ziele aus dem Aktivitätswert mit Grenzen', function () {
    $members = collect([$this->parent, $this->child]);
    expect(app(Difficulty::class)->eventGoal(12, $members, 7))->toBe(168.0); // ohne Daten: wie bisher 12 × 2 × 7

    giveActivity($this->child, 20);
    expect(app(Difficulty::class)->eventGoal(12, $members, 7))->toBe(146.0); // (20 + 12) × 7 × 0,65

    giveActivity($this->child, 400);
    expect(app(Difficulty::class)->eventGoal(12, $members, 7))->toBe(336.0); // höchstens das Doppelte
});
