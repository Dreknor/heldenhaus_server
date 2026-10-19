<?php

use App\Domain\Classes\ClassService;
use App\Domain\Gameplay\Formulas;
use App\Domain\Gameplay\GameException;
use App\Domain\Gameplay\RunDayCron;
use App\Domain\Gameplay\ScoreTask;
use App\Domain\Inventory\DropService;
use App\Domain\Quests\QuestService;
use App\Domain\Social\FeedService;
use App\Domain\Tasks\TaskService;
use App\Models\ActiveBuff;
use App\Models\FamilyDay;
use App\Models\User;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;
use Random\Engine;
use Random\Randomizer;

/** Zufall festlegen: 0 = jede Chance trifft, 1 = keine trifft. */
function fixedScoreRandom(int $byte): void
{
    $engine = new class($byte) implements Engine
    {
        public function __construct(private readonly int $byte) {}

        public function generate(): string
        {
            return str_repeat(chr($this->byte), 8);
        }
    };
    app()->instance(ScoreTask::class, new ScoreTask(app(DropService::class), app(QuestService::class), app(FeedService::class), new Randomizer($engine)));
}

function giveClass(User $user, string $class, int $level = 10, float $mp = 50): void
{
    $user->stats()->update(['class_key' => $class, 'level' => $level, 'mp' => $mp, 'class_changed_at' => now()]);
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-19 10:00', 'Europe/Berlin'));
    [$this->family, $this->parent, $this->child] = makeFamily();
    $this->classes = app(ClassService::class);
    fixedScoreRandom(255);
});

// ---------- Freischaltung & Wechsel ----------

it('schaltet Klassen ab Level 5 (Kinder) bzw. 8 (Teens/Erwachsene) frei', function () {
    $this->child->stats()->update(['level' => 4]);
    expect(fn () => $this->classes->choose($this->child, 'warrior'))->toThrow(GameException::class, 'Level 5');

    $this->child->stats()->update(['level' => 5]);
    expect($this->classes->choose($this->child, 'warrior')->class_key)->toBe('warrior');

    $this->parent->stats()->update(['level' => 7]);
    expect($this->classes->canChoose($this->parent, 'mage')['allowed'])->toBeFalse();
});

it('erlaubt einen Klassenwechsel nur einmal im Monat', function () {
    $this->child->stats()->update(['level' => 6]);
    $this->classes->choose($this->child, 'mage');

    expect(fn () => $this->classes->choose($this->child, 'rogue'))->toThrow(GameException::class, 'einmal im Monat');

    $this->travel(31)->days();
    expect($this->classes->choose($this->child, 'rogue')->class_key)->toBe('rogue');
});

it('erlaubt den Tüftler nur mit Home Assistant und gibt danach einen kostenlosen Wechsel', function () {
    $this->child->stats()->update(['level' => 6]);
    expect($this->classes->canChoose($this->child, 'tinkerer')['allowed'])->toBeFalse();

    $token = $this->parent->createToken('home-assistant', ['ha']);
    $this->classes->choose($this->child, 'tinkerer');

    $token->accessToken->delete();
    expect($this->classes->choose($this->child, 'bard')->class_key)->toBe('bard');
});

it('gibt Kriegern 60 HP und Magiern mehr Mana', function () {
    giveClass($this->child, 'warrior');
    expect(app()->call(fn () => Formulas::maxHp($this->child->stats()->first())))->toBe(60.0);

    giveClass($this->child, 'mage');
    $stats = $this->child->stats()->first();
    expect(Formulas::maxMp($stats))->toBe(round((10 + 0 + 9) * 1.5, 1));
});

// ---------- Krieger ----------

it('Schildwall: −25 % Schaden beim Tageswechsel für die ganze Familie, ohne zu stapeln', function () {
    ($c = app(RunDayCron::class))($this->parent);
    makeTask($this->parent, ['type' => 'daily', 'difficulty' => 1]);
    giveClass($this->child, 'warrior');
    giveClass(joinFamily($this->family, attributes: ['birth_year' => now()->year - 14]), 'warrior');

    $this->travelTo(CarbonImmutable::parse('2026-10-20 08:00', 'Europe/Berlin'));
    $c($this->parent->refresh());

    expect($this->parent->stats()->first()->hp)->toBe(48.5); // 2 HP × 0,75
});

it('Mächtiger Hieb: nächste schwere Aufgabe macht dreifachen Boss-Schaden, dann verbraucht', function () {
    app(QuestService::class)->start($this->parent, 'chaos_dragon', 'family');
    giveClass($this->child, 'warrior');
    $this->classes->cast($this->child, 'mighty_blow');

    $easy = makeTask($this->child, ['difficulty' => 1]);
    $hard = makeTask($this->child, ['difficulty' => 2]);
    $score = app(ScoreTask::class);

    $easyResult = $score(assignmentOf($easy, $this->child), 'up');
    $hardResult = $score(assignmentOf($hard, $this->child), 'up');

    expect($easyResult->completion->quest_progress)->toBe(4.0)
        ->and($hardResult->completion->quest_progress)->toBe(24.0)
        ->and($hardResult->completion->effects)->toBe(['mighty_blow'])
        ->and(ActiveBuff::where('key', 'mighty_blow')->exists())->toBeFalse()
        ->and($this->child->stats()->first()->mp)->toBe(40.0);
});

// ---------- Magier ----------

it('Weisheit der Ahnen: +20 % XP für alle in der Familie, gleiche Buffs stapeln nicht', function () {
    giveClass($this->parent, 'mage');
    $this->classes->cast($this->parent, 'ancestral_wisdom');
    $this->classes->cast($this->parent, 'ancestral_wisdom');

    $task = makeTask($this->child, ['difficulty' => 2]);
    $result = app(ScoreTask::class)(assignmentOf($task, $this->child), 'up');

    expect(ActiveBuff::where('key', 'ancestral_wisdom')->count())->toBe(1)
        ->and($result->completion->xp)->toBe((int) round(12 * 1.2));
});

it('Fokus-Schild: Mana für Dailies vor 12 Uhr', function () {
    giveClass($this->child, 'mage', mp: 10);
    $daily = makeTask($this->child, ['type' => 'daily']);
    $afternoon = makeTask($this->child, ['type' => 'daily']);
    $score = app(ScoreTask::class);

    $score(assignmentOf($daily, $this->child), 'up'); // 10:00
    $this->travelTo(CarbonImmutable::parse('2026-10-19 15:00', 'Europe/Berlin'));
    $score(assignmentOf($afternoon, $this->child), 'up');

    expect($this->child->stats()->first()->mp)->toBe(12.0);
});

// ---------- Schurke ----------

it('Glückstreffer: bei Treffer dreifaches Gold', function () {
    giveClass($this->child, 'rogue');
    fixedScoreRandom(0);

    $result = app(ScoreTask::class)(assignmentOf(makeTask($this->child), $this->child), 'up');

    expect($result->completion->gold)->toBe(3.0)->and($result->completion->effects)->toContain('lucky_strike');
});

it('Glückstreffer: ohne Treffer normales Gold', function () {
    giveClass($this->child, 'rogue');

    expect(app(ScoreTask::class)(assignmentOf(makeTask($this->child), $this->child), 'up')->completion->gold)->toBe(1.0);
});

it('Beutezug: doppeltes Gold plus 10 bei der nächsten Börsen-Aufgabe', function () {
    giveClass($this->child, 'rogue');
    $this->classes->cast($this->child, 'loot_run');
    $pool = makeTask($this->parent, ['scope' => 'family', 'assignment_mode' => 'pool', 'approval_mode' => 'none']);
    app(TaskService::class)->claim($pool, $this->child);

    $result = app(ScoreTask::class)(assignmentOf($pool, $this->child), 'up');

    expect($result->completion->gold)->toBe(12.0)->and(ActiveBuff::count())->toBe(0);
});

// ---------- Heiler ----------

it('Heilende Welle heilt ein Familienmitglied, nicht fremde', function () {
    giveClass($this->parent, 'healer');
    $this->child->stats()->update(['hp' => 20]);
    [, , $foreign] = makeFamily();

    $this->classes->cast($this->parent, 'healing_wave', $this->child);
    expect($this->child->stats()->first()->hp)->toBe(35.0);

    expect(fn () => $this->classes->cast($this->parent, 'healing_wave', $foreign))->toThrow(GameException::class);
});

it('Zweite Chance: verpasste Daily einmal pro Woche glätten', function () {
    ($c = app(RunDayCron::class))($this->child);
    $daily = makeTask($this->child, ['type' => 'daily', 'difficulty' => 2]);
    assignmentOf($daily, $this->child)->update(['streak' => 6]);
    giveClass($this->parent, 'healer');

    $this->travelTo(CarbonImmutable::parse('2026-10-20 08:00', 'Europe/Berlin'));
    $c($this->child->refresh());
    expect($this->child->stats()->first()->hp)->toBe(48.0);

    $message = $this->classes->secondChance($this->parent, assignmentOf($daily, $this->child)->id);

    expect($message)->toContain('Zweite Chance')
        ->and($this->child->stats()->first()->hp)->toBe(50.0)
        ->and(assignmentOf($daily, $this->child)->streak)->toBe(6)
        ->and(fn () => $this->classes->secondChance($this->parent, assignmentOf($daily, $this->child)->id))->toThrow(GameException::class, 'einmal pro Woche');
});

// ---------- Tüftler ----------

it('Smart-Connect: +25 % nur bei Home Assistant; Überlastung verdoppelt zusätzlich', function () {
    $this->parent->createToken('home-assistant', ['ha']);
    giveClass($this->child, 'tinkerer');
    $score = app(ScoreTask::class);

    $web = $score(assignmentOf(makeTask($this->child, ['difficulty' => 2]), $this->child), 'up', 'web');
    $ha = $score(assignmentOf(makeTask($this->child, ['difficulty' => 2]), $this->child), 'up', 'ha');
    $this->classes->cast($this->child, 'overload');
    $overload = $score(assignmentOf(makeTask($this->child, ['difficulty' => 2]), $this->child), 'up', 'ha');

    expect($web->completion->xp)->toBe(12)
        ->and($ha->completion->xp)->toBe(15)
        ->and($overload->completion->xp)->toBe(30)
        ->and($overload->completion->effects)->toBe(['smart_connect', 'overload']);
});

it('Smart-Connect hat ein Tageslimit', function () {
    config(['game.smart_connect_daily_limit' => 1]);
    giveClass($this->child, 'tinkerer');
    $score = app(ScoreTask::class);

    $score(assignmentOf(makeTask($this->child), $this->child), 'up', 'ha');
    $second = $score(assignmentOf(makeTask($this->child), $this->child), 'up', 'ha');

    expect($second->completion->xp)->toBe(6);
});

// ---------- Barde ----------

it('Anfeuern: +15 % Boss-Schaden für alle', function () {
    app(QuestService::class)->start($this->parent, 'chaos_dragon', 'family');
    giveClass($this->parent, 'bard');
    $this->classes->cast($this->parent, 'cheer');

    $result = app(ScoreTask::class)(assignmentOf(makeTask($this->child, ['difficulty' => 1]), $this->child), 'up');

    expect($result->completion->quest_progress)->toBe(4.6);
});

it('Gemeinschafts-Rhythmus: Gold für alle, wenn alle aktiven Mitglieder 100 % schaffen – nur einmal', function () {
    giveClass($this->parent, 'bard');
    $c = app(RunDayCron::class);
    $c($this->parent);
    $c($this->child);
    $parentDaily = makeTask($this->parent, ['type' => 'daily']);
    $childDaily = makeTask($this->child, ['type' => 'daily']);
    $score = app(ScoreTask::class);
    $score(assignmentOf($parentDaily, $this->parent), 'up');
    $score(assignmentOf($childDaily, $this->child), 'up');

    $this->travelTo(CarbonImmutable::parse('2026-10-20 08:00', 'Europe/Berlin'));
    $c($this->parent->refresh());
    $goldBefore = $this->child->stats()->first()->gold;

    expect($this->classes->evaluateCommunityRhythm())->toBe(0); // Kind hatte noch keinen Tageswechsel

    $c($this->child->refresh());
    expect($this->classes->evaluateCommunityRhythm())->toBe(1)
        ->and($this->child->stats()->first()->gold)->toBe($goldBefore + 15)
        ->and($this->classes->evaluateCommunityRhythm())->toBe(0)
        ->and(FamilyDay::first()->all_done)->toBeTrue();
});

it('Gemeinschafts-Rhythmus zahlt nichts, wenn jemand eine Daily verpasst', function () {
    giveClass($this->parent, 'bard');
    $c = app(RunDayCron::class);
    $c($this->parent);
    $c($this->child);
    makeTask($this->parent, ['type' => 'daily']);
    $done = makeTask($this->child, ['type' => 'daily']);
    app(ScoreTask::class)(assignmentOf($done, $this->child), 'up');

    $this->travelTo(CarbonImmutable::parse('2026-10-20 08:00', 'Europe/Berlin'));
    $c($this->parent->refresh());
    $c($this->child->refresh());

    expect($this->classes->evaluateCommunityRhythm())->toBe(0)->and(FamilyDay::first()->all_done)->toBeFalse();
});

// ---------- Allgemein ----------

it('braucht genug Mana und begrenzt Fähigkeiten pro Tag', function () {
    giveClass($this->child, 'bard', mp: 14);
    expect(fn () => $this->classes->cast($this->child, 'cheer'))->toThrow(GameException::class, 'Mana');

    giveClass($this->child, 'bard', mp: 200);
    foreach (range(1, 3) as $i) {
        $this->classes->cast($this->child, 'cheer');
    }
    expect(fn () => $this->classes->cast($this->child, 'cheer'))->toThrow(GameException::class, 'oft genug');
});

it('lässt nur Fähigkeiten der eigenen Klasse zu', function () {
    giveClass($this->child, 'rogue');

    expect(fn () => $this->classes->cast($this->child, 'cheer'))->toThrow(GameException::class);
});

it('bietet Held-Seite und API an', function () {
    giveClass($this->child, 'bard');

    $this->actingAs($this->child)->get('/hero')->assertOk()
        ->assertInertia(fn ($page) => $page->component('Hero')->where('hero_class.key', 'bard')->has('classes', 6));

    Sanctum::actingAs($this->child, ['app']);
    $this->postJson('/api/v1/hero/cast', ['skill' => 'cheer'])->assertOk()->assertJsonPath('message', 'Angefeuert! +15 % Boss-Schaden für alle.');
    $this->getJson('/api/v1/hero')->assertOk()->assertJsonPath('buffs.0.key', 'cheer');
});
