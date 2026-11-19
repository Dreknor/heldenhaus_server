<?php

use App\Domain\Achievements\AlbumService;
use App\Domain\Achievements\ChronicleService;
use App\Domain\Achievements\HeroYearService;
use App\Domain\Achievements\LongTermCatalog;
use App\Domain\Achievements\MasteryService;
use App\Domain\Achievements\TitleService;
use App\Domain\Classes\ClassCatalog;
use App\Domain\Classes\ClassService;
use App\Domain\Gameplay\GameException;
use App\Domain\Gameplay\Progression;
use App\Domain\Gameplay\RunDayCron;
use App\Domain\Gameplay\ScoreTask;
use App\Events\LevelUp;
use App\Models\ActiveBuff;
use App\Models\AlbumProgress;
use App\Models\ChronicleUnlock;
use App\Models\FamilyEventSetting;
use App\Models\HeroYear;
use App\Models\HolidayPeriod;
use App\Models\Item;
use App\Models\MasteryLevel;
use App\Models\Quest;
use App\Models\User;
use App\Models\UserBadge;
use App\Models\UserPet;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-19 10:00', 'Europe/Berlin'));
    [$this->family, $this->parent, $this->child] = makeFamily();
});

function gold(User $user): float
{
    return (float) $user->stats()->value('gold');
}

function summerHolidays(string $subdivision, string $from, string $to): void
{
    HolidayPeriod::create(['country' => 'DE', 'subdivision' => $subdivision, 'type' => 'school', 'name' => 'Sommerferien',
        'starts_on' => $from, 'ends_on' => $to, 'source' => 'openholidays']);
}

function defeatFamilyBoss(int $familyId, string $key = 'dust_monster'): Quest
{
    return Quest::create(['family_id' => $familyId, 'scope' => 'family', 'type' => 'boss', 'quest_key' => $key, 'name' => 'Boss',
        'icon' => '👾', 'goal' => 10, 'progress' => 10, 'status' => 'completed', 'ends_at' => now()->addDays(7), 'completed_at' => now()]);
}

// ---------- Fähigkeiten-Stufen ----------

it('gibt jeder Klasse drei Fähigkeiten-Stufen bei Level 15, 20 und 25 mit bekanntem Effekt', function () {
    $types = ['score_bonus', 'cron_shield', 'family_buff', 'armed_next', 'member_shield', 'family_restore', 'family_gold', 'family_torches', 'family_items'];
    expect(ClassCatalog::all())->toHaveCount(11);

    foreach (ClassCatalog::all() as $key => $class) {
        expect(collect($class['tiers'])->pluck('level')->all())->toBe([15, 20, 25], $key);
        foreach ($class['tiers'] as $tier) {
            expect($tier['effect']['type'])->toBeIn($types)
                ->and(ClassCatalog::skill($tier['key'])['class'])->toBe($key);
            if ($tier['kind'] === 'active') {
                expect($tier['mana'])->toBeGreaterThan(0)->and($tier['message'])->not->toBeEmpty();
            }
        }
    }
});

it('Schutzschild (Krieger 15) halbiert den Schaden eines Familienmitglieds – erst ab Level 15', function () {
    ($cron = app(RunDayCron::class))($this->parent);
    makeTask($this->parent, ['type' => 'daily', 'difficulty' => 1]);
    $this->child->stats()->update(['class_key' => 'warrior', 'level' => 14, 'mp' => 50]);

    expect(fn () => app(ClassService::class)->cast($this->child, 'protective_shield', $this->parent))->toThrow(GameException::class, 'ab Level 15');

    $this->child->stats()->update(['level' => 15]);
    $message = app(ClassService::class)->cast($this->child, 'protective_shield', $this->parent);
    expect($message)->toContain($this->parent->name);

    $this->travelTo(CarbonImmutable::parse('2026-10-20 08:00', 'Europe/Berlin'));
    $cron($this->parent->refresh());

    // 2 HP Grundschaden × 0,75 (Schildwall des Kriegers) × 0,5 (Schutzschild)
    expect($this->parent->stats()->first()->hp)->toBe(49.25);
});

it('Ständchen (Barde 20) gibt allen eine Fackel, einmal am Tag', function () {
    $this->child->stats()->update(['class_key' => 'bard', 'level' => 20, 'mp' => 50]);

    app(ClassService::class)->cast($this->child, 'serenade');

    expect($this->child->stats()->value('torches'))->toBe(1)
        ->and($this->parent->stats()->value('torches'))->toBe(1)
        ->and(fn () => app(ClassService::class)->cast($this->child, 'serenade'))->toThrow(GameException::class, 'oft genug');
});

it('passive Stufe wirkt erst ab ihrem Level: Schwerarbeiter (Krieger 20) gibt +15 % auf schwere Aufgaben', function () {
    $score = app(ScoreTask::class);
    $this->child->stats()->update(['class_key' => 'warrior', 'level' => 19]);
    $before = $score(assignmentOf(makeTask($this->child, ['difficulty' => 2]), $this->child), 'up');

    $this->child->stats()->update(['level' => 20]);
    $after = $score(assignmentOf(makeTask($this->child, ['difficulty' => 2]), $this->child), 'up');

    expect($before->completion->effects ?? [])->not->toContain('heavy_lifter')
        ->and($after->completion->effects)->toContain('heavy_lifter')
        ->and($after->completion->gold)->toBe(round($before->completion->gold * 1.15, 2));
});

it('Geistesblitz (Magier 20) verdoppelt die XP der nächsten Lernaufgabe und ist dann verbraucht', function () {
    $this->child->stats()->update(['class_key' => 'mage', 'level' => 20, 'mp' => 50]);
    app(ClassService::class)->cast($this->child, 'brainwave');
    $score = app(ScoreTask::class);

    $house = $score(assignmentOf(makeTask($this->child, ['difficulty' => 2, 'category' => 'household']), $this->child), 'up');
    $school = $score(assignmentOf(makeTask($this->child, ['difficulty' => 2, 'category' => 'school']), $this->child), 'up');

    expect($house->completion->effects ?? [])->not->toContain('brainwave')
        ->and($school->completion->effects)->toContain('brainwave')
        ->and($school->completion->xp)->toBe((int) round(12 * 1.15 * 2)) // Bücherwurm (15) und Geistesblitz
        ->and(ActiveBuff::where('key', 'brainwave')->exists())->toBeFalse();
});

it('Zeitzauber (Magier 25) wirkt als Familien-Buff auf alle', function () {
    $this->parent->stats()->update(['class_key' => 'mage', 'level' => 25, 'mp' => 50]);
    app(ClassService::class)->cast($this->parent, 'time_magic');

    $result = app(ScoreTask::class)(assignmentOf(makeTask($this->child, ['difficulty' => 2]), $this->child), 'up');

    expect($result->completion->effects)->toContain('time_magic')->and($result->completion->xp)->toBe((int) round(12 * 1.1));
});

it('zeigt die Fähigkeiten-Stufen auf der Held-Seite', function () {
    $this->child->stats()->update(['class_key' => 'bard', 'level' => 16]);

    $this->actingAs($this->child)->get('/hero')->assertOk()
        ->assertInertia(fn ($page) => $page->component('Hero')->has('hero_class.tiers', 3)->where('hero_class.tiers.1.key', 'serenade')->has('masteries', 5));
});

// ---------- Sammelalbum ----------

it('berechnet den Album-Fortschritt in Prozent und belohnt jede Stufe genau einmal', function () {
    $this->artisan('game:content-sync')->assertSuccessful();
    $eggs = Item::where('type', 'egg')->where('active', true)->orderBy('id')->get();
    $potion = Item::where('type', 'hatching_potion')->first();
    $needed = (int) ceil($eggs->count() / 4);
    foreach ($eggs->take($needed) as $egg) {
        UserPet::create(['user_id' => $this->child->id, 'egg_item_id' => $egg->id, 'potion_item_id' => $potion->id, 'name' => $egg->name, 'growth' => 0]);
    }
    $goldBefore = gold($this->child);

    $paid = app(AlbumService::class)->sync($this->child);
    app(AlbumService::class)->sync($this->child);

    $row = AlbumProgress::where('user_id', $this->child->id)->where('album', 'pets')->first();
    expect($row->percent)->toBe(AlbumService::percent($needed, $eggs->count()))
        ->and($row->percent)->toBeGreaterThanOrEqual(25)->toBeLessThan(50)
        ->and(collect($paid)->where('album', 'pets')->pluck('step')->all())->toBe([25])
        ->and(collect($paid)->firstWhere('album', 'pets')['gold'])->toBe(25.0)
        ->and(gold($this->child))->toBe($goldBefore + collect($paid)->sum('gold'))
        ->and(UserBadge::where('user_id', $this->child->id)->where('key', 'album:pets:25')->count())->toBe(1);

    // Einmal gesammelt bleibt gesammelt – und keine zweite Belohnung
    UserPet::where('user_id', $this->child->id)->delete();
    expect(app(AlbumService::class)->sync($this->child))->toBe([])
        ->and($row->refresh()->percent)->toBeGreaterThanOrEqual(25)
        ->and(gold($this->child))->toBe($goldBefore + collect($paid)->sum('gold'));
});

it('kennzeichnet Event-Einträge im Album und zeigt die Seite sowie die API', function () {
    $this->artisan('game:content-sync')->assertSuccessful();

    $pets = collect(AlbumService::entries('pets'));
    expect($pets->firstWhere('key', 'egg_bat')['event']['key'])->toBe('pumpkin_night')
        ->and($pets->firstWhere('key', 'egg_wolf')['event'])->toBeNull()
        ->and(collect(AlbumService::entries('monsters'))->firstWhere('key', 'dragon_lord')['event'])->not->toBeNull();

    $this->actingAs($this->child)->get('/album')->assertOk()
        ->assertInertia(fn ($page) => $page->component('Album')->has('albums', 6)->where('albums.0.key', 'pets'));

    Sanctum::actingAs($this->child, ['app']);
    $this->getJson('/api/v1/me/album')->assertOk()->assertJsonCount(6, 'albums')->assertJsonPath('albums.2.key', 'monsters');
});

it('zählt besiegte Bosse im Monster-Lexikon und gibt bei 100 % den Abschluss-Gegenstand', function () {
    $this->artisan('game:content-sync')->assertSuccessful();
    foreach (AlbumService::entries('monsters') as $monster) {
        defeatFamilyBoss($this->family->id, $monster['key']);
    }

    $paid = collect(app(AlbumService::class)->sync($this->child))->where('album', 'monsters');

    expect($paid->pluck('step')->all())->toBe([25, 50, 75, 100])
        ->and($paid->last()['item']['key'])->toBe('furn_dragon_trophy')
        ->and(UserBadge::where('user_id', $this->child->id)->where('kind', 'album')->count())->toBe(4);
});

// ---------- Meisterschaften ----------

it('vergibt Meisterschafts-Stufen nach Anzahl erledigter Aufgaben je Kategorie', function () {
    expect(MasteryService::levelFor(24))->toBe(0)
        ->and(MasteryService::levelFor(25))->toBe(1)
        ->and(MasteryService::levelFor(100))->toBe(2)
        ->and(MasteryService::levelFor(250))->toBe(3)
        ->and(MasteryService::levelFor(999))->toBe(4)
        ->and(MasteryService::levelFor(1000))->toBe(5)
        ->and(MasteryService::title('household', 3))->toBe('Meisterschaft Haushalt III');

    $score = app(ScoreTask::class);
    foreach (range(1, 25) as $i) {
        $score(assignmentOf(makeTask($this->child, ['category' => 'household']), $this->child), 'up');
    }
    $score(assignmentOf(makeTask($this->child, ['category' => 'school']), $this->child), 'up');

    expect(MasteryLevel::where('user_id', $this->child->id)->where('category', 'household')->value('level'))->toBe(1)
        ->and(MasteryLevel::where('user_id', $this->child->id)->where('category', 'school')->value('count'))->toBe(1)
        ->and(UserBadge::where('user_id', $this->child->id)->where('key', 'mastery:household:1')->count())->toBe(1)
        ->and(TitleService::display($this->child)['mastery'])->toBe('Meisterschaft Haushalt I');

    Sanctum::actingAs($this->child, ['app']);
    $this->getJson('/api/v1/me/masteries')->assertOk()->assertJsonPath('masteries.0.level', 1)->assertJsonPath('title.mastery', 'Meisterschaft Haushalt I');
});

it('zeigt den Titel unter dem Namen in den geteilten Daten', function () {
    MasteryLevel::create(['user_id' => $this->child->id, 'category' => 'sport', 'count' => 120, 'level' => 2]);
    $this->child->stats()->update(['level' => 21]);

    $this->actingAs($this->child)->get('/hero')->assertOk()
        ->assertInertia(fn ($page) => $page->where('auth.title.mastery', 'Meisterschaft Sport II')->where('auth.title.level', 'Stern des Hauses'));
});

// ---------- Titel ----------

it('vergibt Titel bei Level 20, 30, 50, 75, 100 und kennt kein Level-Maximum', function () {
    expect(collect(LongTermCatalog::titles())->pluck('level')->all())->toBe([20, 30, 50, 75, 100])
        ->and(TitleService::forLevel(19))->toBeNull()
        ->and(TitleService::forLevel(74)['name'])->toBe('Licht der Burg');

    event(new LevelUp($this->child, 31));
    expect(UserBadge::where('user_id', $this->child->id)->where('kind', 'title')->pluck('key')->sort()->values()->all())->toBe(['title_20', 'title_30']);

    event(new LevelUp($this->child, 31));
    expect(UserBadge::where('user_id', $this->child->id)->where('kind', 'title')->count())->toBe(2);

    // Kein Level-Maximum: auch nach Level 100 geht es weiter
    $stats = $this->child->stats()->first();
    $stats->level = 100;
    $stats->xp = 0;
    Progression::gainXp($stats, 1_000_000);
    expect($stats->level)->toBeGreaterThan(101);
});

// ---------- Heldenjahr ----------

it('startet das Heldenjahr zum Schuljahresbeginn je Bundesland neu, sonst am 1. August', function () {
    summerHolidays('DE-SN', '2026-07-04', '2026-08-14');
    summerHolidays('DE-BY', '2026-08-03', '2026-09-14');
    [$byFamily, $byParent] = makeFamily();
    FamilyEventSetting::create(['family_id' => $byFamily->id, 'country' => 'DE', 'subdivision' => 'DE-BY']);
    $service = app(HeroYearService::class);

    $this->travelTo(CarbonImmutable::parse('2026-08-20 12:00', 'Europe/Berlin'));
    $sn = $service->current($this->child);
    $by = $service->current($byParent->refresh());

    expect($sn->start_year)->toBe(2026)->and($sn->starts_on->toDateString())->toBe('2026-08-15')
        ->and($by->start_year)->toBe(2025);

    $service->addPoints($byParent, 30, 'goal');
    $this->travelTo(CarbonImmutable::parse('2026-09-15 12:00', 'Europe/Berlin'));
    $service->addPoints($byParent, 20, 'goal');

    expect(HeroYear::where('user_id', $byParent->id)->orderBy('start_year')->pluck('points', 'start_year')->all())->toBe([2025 => 30, 2026 => 20])
        ->and($service->current($byParent)->starts_on->toDateString())->toBe('2026-09-15');

    // Ohne Ferien-Daten (anderes Land): 1. August
    expect(HeroYearService::schoolYearStart(null, 2027)->toDateString())->toBe('2027-08-01');
});

it('erreicht Stufe 50 mit etwa 50 % der üblichen Aktivität, mit 30 % nicht (Simulation)', function () {
    summerHolidays('DE-SN', '2026-07-04', '2026-08-14');
    summerHolidays('DE-SN', '2027-07-10', '2027-08-20');
    $cfg = LongTermCatalog::heroYear();
    $points = HeroYearService::pointsForDifficulty((float) $cfg['usual_day']['difficulty']);
    $usualTasks = (int) $cfg['usual_day']['tasks'];
    $service = app(HeroYearService::class);
    [, , $lazy] = makeFamily();

    // Ein ganzes Schuljahr, an einem Tag pro Woche Pause; Lea schafft die Hälfte, das andere Kind 30 %
    $day = CarbonImmutable::parse('2026-08-15 18:00', 'Europe/Berlin');
    $half = intdiv($usualTasks, 2);
    while ($day->lt(CarbonImmutable::parse('2027-08-21', 'Europe/Berlin'))) {
        $this->travelTo($day);
        if ($day->dayOfWeekIso !== 7) {
            foreach (range(1, $half) as $i) {
                $service->addPoints($this->child, $points, 'task');
            }
            $service->addPoints($lazy, (int) round($usualTasks * 0.3 * $points), 'task');
        }
        $day = $day->addDay();
    }

    $year = HeroYear::where('user_id', $this->child->id)->where('start_year', 2026)->first();
    expect($year->level)->toBe(50)
        ->and(HeroYear::where('user_id', $lazy->id)->where('start_year', 2026)->value('level'))->toBeLessThan(50)
        ->and(UserBadge::where('user_id', $this->child->id)->where('key', 'hero_year:2026')->count())->toBe(1);
});

it('begrenzt Aufgaben-Punkte pro Tag, belohnt Stufen genau einmal und zählt echte Abschlüsse', function () {
    $service = app(HeroYearService::class);
    $cap = (int) LongTermCatalog::heroYear()['daily_task_cap'];
    $goldBefore = gold($this->child);

    expect($service->addPoints($this->child, $cap + 50, 'task'))->toBe($cap)
        ->and($service->addPoints($this->child, 10, 'task'))->toBe(0)
        ->and($service->addPoints($this->child, 40, 'goal'))->toBe(40);

    $year = $service->current($this->child);
    expect($year->level)->toBe(intdiv($cap + 40, (int) LongTermCatalog::heroYear()['points_per_level']))
        ->and($year->rewarded_level)->toBe($year->level)
        ->and(gold($this->child))->toBe($goldBefore + 10 * $year->level);

    $this->travel(1)->days();
    $before = $service->current($this->child)->points;
    app(ScoreTask::class)(assignmentOf(makeTask($this->child, ['difficulty' => 2]), $this->child), 'up');
    expect($service->current($this->child)->points)->toBe($before + HeroYearService::pointsForDifficulty(2));

    $this->actingAs($this->child)->get('/hero-year')->assertOk()
        ->assertInertia(fn ($page) => $page->component('HeroYear')->has('year.stages', 50)->where('year.stages.49.badge', true));
    Sanctum::actingAs($this->child, ['app']);
    $this->getJson('/api/v1/me/hero-year')->assertOk()->assertJsonPath('year.max_level', 50);
});

// ---------- Chronik ----------

it('schaltet Chronik-Kapitel über Level und Familien-Bosse frei', function () {
    $this->child->stats()->update(['level' => 10]);
    $chronicle = app(ChronicleService::class);

    $chronicle->sync($this->child);
    $keys = fn () => ChronicleUnlock::where('user_id', $this->child->id)->pluck('chapter_key')->sort()->values()->all();
    expect($keys())->toBe(['ch01_old_house', 'ch02_dust_monster', 'ch03_laundry_hydra', 'ch04_procrastination_troll']);

    defeatFamilyBoss($this->family->id);
    $chronicle->sync($this->child);
    expect($keys())->toContain('ch05_couch_golem')->not->toContain('ch06_boredom_ghost');

    // Level-Aufstieg schaltet automatisch frei
    $this->child->stats()->update(['level' => 12]);
    event(new LevelUp($this->child, 12));
    expect($keys())->toContain('ch06_boredom_ghost');
});

it('personalisiert die Chronik mit Familienname, Heldenname und Wappen und merkt sich Gelesenes', function () {
    $this->family->update(['name' => 'Berger', 'crest' => '🦊']);
    $this->child->stats()->update(['level' => 2]);

    $this->actingAs($this->child->refresh())->get('/chronicle')->assertOk()
        ->assertInertia(fn ($page) => $page->component('Chronicle')
            ->where('chapters.0.unlocked', true)
            ->where('chapters.0.read', false)
            ->where('chapters.1.unlocked', false)
            ->where('chapters.1.text', [])
            ->where('crest', '🦊'));

    $text = implode(' ', app(ChronicleService::class)->present($this->child->refresh())['chapters'][0]['text']);
    expect($text)->toContain('Familie Berger')->toContain('Lea')->toContain('🦊')->not->toContain('{');

    $this->actingAs($this->child)->post('/chronicle/ch01_old_house/read')->assertRedirect();
    expect(ChronicleUnlock::where('user_id', $this->child->id)->where('chapter_key', 'ch01_old_house')->value('read_at'))->not->toBeNull();

    Sanctum::actingAs($this->child, ['app']);
    $this->getJson('/api/v1/chronicle')->assertOk()->assertJsonCount(12, 'chapters')->assertJsonPath('chapters.0.read', true);
});

it('lässt nur Eltern ein Wappen aus der Auswahl wählen', function () {
    $this->actingAs($this->child)->put('/family/crest', ['crest' => '🦁'])->assertForbidden();
    $this->actingAs($this->parent)->put('/family/crest', ['crest' => '💩'])->assertSessionHasErrors('crest');
    $this->actingAs($this->parent)->put('/family/crest', ['crest' => '🦁'])->assertRedirect();

    expect($this->family->refresh()->crest)->toBe('🦁')
        ->and(ChronicleService::familyName($this->family))->toBe('Familie Test');
});

it('schlägt ein Kapitel gemeinsam auf (Familienrat) und zeigt Hinweise im Heldenpanel', function () {
    $this->parent->stats()->update(['level' => 4]);

    $this->actingAs($this->child)->get('/dashboard')->assertOk()
        ->assertInertia(fn ($page) => $page->where('longTerm.chapter', null));
    $this->actingAs($this->parent)->get('/dashboard')->assertOk()
        ->assertInertia(fn ($page) => $page->where('longTerm.chapter.key', 'ch01_old_house'));

    $opened = app(ChronicleService::class)->openTogether($this->family);

    expect($opened['key'])->toBe('ch01_old_house')
        ->and(ChronicleUnlock::where('chapter_key', 'ch01_old_house')->whereNotNull('read_at')->count())->toBe(2);

    $next = app(ChronicleService::class)->openTogether($this->family);
    expect($next['key'])->toBe('ch02_dust_monster')
        ->and(app(ChronicleService::class)->openTogether($this->family))->toBeNull();
});
