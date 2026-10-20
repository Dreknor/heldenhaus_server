<?php

namespace App\Domain\Housing;

use App\Domain\Crafting\Catalog;
use App\Domain\Crafting\StorageService;
use App\Domain\Events\EventEngine;
use App\Domain\Features\Features;
use App\Domain\Gameplay\GameException;
use App\Domain\Gameplay\Progression;
use App\Domain\Inventory\Stock;
use App\Domain\Notifications\PushNotifier;
use App\Domain\Social\FeedService;
use App\Events\BuildProjectCompleted;
use App\Models\BuildContribution;
use App\Models\BuildProject;
use App\Models\Home;
use App\Models\Item;
use App\Models\User;
use App\Models\UserStats;
use Illuminate\Support\Facades\DB;

/**
 * Familien-Bauprojekte (Konzept §2.6): Materialliste + Gold, alle tragen bei, danach Bauzeit in Echtzeit
 * und eine Einweihung im Feed. Eltern starten Projekte und können ein reales Familienziel anhängen.
 */
class BuildService
{
    public function __construct(private readonly FeedService $feed, private readonly PushNotifier $push) {}

    /** Ist die Wirkung eines Projekts im Haus schon da (Raum gebaut, Stufe erreicht)? */
    public static function applied(Home $home, array $project): bool
    {
        $e = $project['effects'];

        // Projekte ohne Raum/Stufe/Station (Gewächshaus, Schneemann) gelten als gebaut, sobald eins fertig ist –
        // Event-Projekte nur innerhalb des laufenden Events
        if (! isset($e['room']) && ! isset($e['stage']) && empty($e['stations'])) {
            $event = isset($project['event_key']) ? EventEngine::find($home->family_id, $project['event_key']) : null;

            return BuildProject::where('family_id', $home->family_id)->where('project_key', $project['key'])->where('status', 'done')
                ->when($event, fn ($q) => $q->where('completed_at', '>=', $event->starts_at))->exists();
        }

        return (! isset($e['room']) || HomeService::hasRoom($home, $e['room']))
            && (! isset($e['stage']) || $home->stageAtLeast($e['stage']))
            && collect($e['stations'] ?? [])->every(fn ($level, $station) => $home->station($station) >= $level);
    }

    public static function requirementsMet(Home $home, array $project): bool
    {
        $r = $project['requires'] ?? [];
        // Event-Bauprojekte (Erntescheune, Schneemann) gibt es nur, solange ihr Event läuft
        if (isset($project['event_key']) && ! EventEngine::isRunning($home->family_id, $project['event_key'])) {
            return false;
        }

        return (! isset($r['stage']) || $home->stageAtLeast($r['stage']))
            && collect($r['rooms'] ?? [])->every(fn ($room) => HomeService::hasRoom($home, $room))
            && collect($r['stations'] ?? [])->every(fn ($level, $station) => $home->station($station) >= $level);
    }

    public function start(User $parent, string $key, ?string $rewardText = null): BuildProject
    {
        if (! $parent->isParent()) {
            throw new GameException('Bauprojekte starten die Eltern.');
        }
        $project = Catalog::project($key);
        $home = HomeService::home($parent->family_id);

        if (self::applied($home, $project)) {
            throw new GameException('Das habt ihr schon gebaut.');
        }
        if (! self::requirementsMet($home, $project)) {
            throw new GameException(isset($project['event_key']) && ! EventEngine::isRunning($home->family_id, $project['event_key'])
                ? 'Dieses Bauprojekt gibt es nur während seines Events.'
                : 'Dafür fehlt noch ein anderes Bauprojekt.');
        }

        return DB::transaction(function () use ($parent, $project, $rewardText) {
            $active = BuildProject::where('family_id', $parent->family_id)->whereIn('status', ['collecting', 'building'])->lockForUpdate()->get();
            if ($active->contains('project_key', $project['key'])) {
                throw new GameException('An diesem Projekt baut ihr schon.');
            }
            if ($active->count() >= config('game.housing.max_active_projects')) {
                throw new GameException('Ihr habt schon '.config('game.housing.max_active_projects').' Baustellen. Stellt zuerst eine fertig.');
            }

            $build = BuildProject::create([
                'family_id' => $parent->family_id,
                'project_key' => $project['key'],
                'requirements' => ['items' => $project['items'], 'gold' => $project['gold'] ?? 0],
                'progress' => ['items' => (object) [], 'gold' => 0],
                'status' => 'collecting',
                'reward_text' => $rewardText ?: null,
                'started_by' => $parent->id,
            ]);
            $this->feed->post($parent->family_id, $parent, 'build_started', $project['icon'] ?? '🏗️',
                "Neues Bauprojekt: {$project['name']}".($rewardText ? " – Ziel: {$rewardText}" : '').'. Alle können beitragen!');

            return $build;
        });
    }

    /**
     * Beitrag: Material (aus dem eigenen Inventar oder dem Familienlager) oder Gold ($itemKey = null).
     * Es wird nie mehr genommen, als noch fehlt.
     */
    public function contribute(User $user, BuildProject $build, ?string $itemKey, int $amount, bool $fromStorage = false): BuildProject
    {
        if ($build->family_id !== $user->family_id) {
            throw new GameException('Das ist nicht euer Bauprojekt.');
        }
        if ($amount < 1) {
            throw new GameException('Wie viel möchtest du beitragen?');
        }

        return DB::transaction(function () use ($user, $build, $itemKey, $amount, $fromStorage) {
            $build = BuildProject::lockForUpdate()->findOrFail($build->id);
            if ($build->status !== 'collecting') {
                throw new GameException('Hier wird schon gebaut.');
            }

            $progress = $build->progress ?? ['items' => [], 'gold' => 0];
            $progress['items'] = (array) ($progress['items'] ?? []);

            if ($itemKey === null) {
                $amount = min($amount, (int) $build->requirements['gold'] - (int) ($progress['gold'] ?? 0));
                if ($amount <= 0) {
                    throw new GameException('Gold ist schon genug da.');
                }
                $stats = UserStats::lockForUpdate()->findOrFail($user->id);
                if ($stats->gold < $amount) {
                    throw new GameException('So viel Gold hast du nicht.');
                }
                Progression::changeGold($stats, -$amount);
                $stats->save();
                $progress['gold'] = (int) ($progress['gold'] ?? 0) + $amount;
                $label = "{$amount} Gold";
            } else {
                $needed = (int) ($build->requirements['items'][$itemKey] ?? 0);
                $amount = min($amount, $needed - (int) ($progress['items'][$itemKey] ?? 0));
                if ($needed === 0) {
                    throw new GameException('Das braucht dieses Projekt nicht.');
                }
                if ($amount <= 0) {
                    throw new GameException('Davon ist schon genug da.');
                }
                $item = Item::where('key', $itemKey)->firstOrFail();
                $fromStorage ? StorageService::consume($user->family_id, $item, $amount) : Stock::take($user->id, $item, $amount);
                $progress['items'][$itemKey] = (int) ($progress['items'][$itemKey] ?? 0) + $amount;
                $label = "{$amount} {$item->name}";
            }

            BuildContribution::create(['build_project_id' => $build->id, 'user_id' => $user->id, 'item_key' => $itemKey, 'amount' => $amount]);
            $build->progress = $progress;

            $project = Catalog::project($build->project_key);
            $this->feed->post($user->family_id, $user, 'build_contribution', $project['icon'] ?? '🏗️', "{$user->name} hat {$label} zu „{$project['name']}“ beigesteuert.");

            if (self::isComplete($build)) {
                $build->status = 'building';
                $build->finishes_at = now()->addHours((int) ($project['hours'] ?? 24));
                $this->feed->post($user->family_id, null, 'build_building', '🏗️', "Alles da! „{$project['name']}“ wird jetzt gebaut.");
            }
            $build->save();

            Features::starterDone($user, 'building');

            return $build;
        });
    }

    /** Abbrechen, solange noch gesammelt wird: alle Beiträge gehen zurück. Was aus dem Lager kam, geht an die Person. */
    public function cancel(User $parent, BuildProject $build): void
    {
        if (! $parent->isParent() || $build->family_id !== $parent->family_id) {
            throw new GameException('Bauprojekte brechen die Eltern ab.');
        }

        DB::transaction(function () use ($build) {
            $build = BuildProject::lockForUpdate()->findOrFail($build->id);
            if ($build->status !== 'collecting') {
                throw new GameException('Das lässt sich nicht mehr abbrechen.');
            }
            $items = Item::whereIn('key', $build->contributions()->whereNotNull('item_key')->pluck('item_key'))->get()->keyBy('key');
            foreach ($build->contributions as $c) {
                if ($c->item_key === null) {
                    $stats = UserStats::lockForUpdate()->find($c->user_id);
                    $stats?->forceFill(['gold' => $stats->gold + $c->amount])->save();
                } elseif (isset($items[$c->item_key])) {
                    Stock::add($c->user_id, $items[$c->item_key]->id, $c->amount);
                }
            }
            $build->update(['status' => 'cancelled']);
        });
    }

    /** Vom Scheduler: fertige Baustellen einweihen. */
    public function finishDue(): int
    {
        $count = 0;
        BuildProject::where('status', 'building')->where('finishes_at', '<=', now())->get()->each(function (BuildProject $build) use (&$count) {
            DB::transaction(function () use ($build) {
                $project = Catalog::project($build->project_key);
                $home = HomeService::home($build->family_id);
                $this->apply($home, $project);
                $build->update(['status' => 'done', 'completed_at' => now()]);
                $this->feed->post($build->family_id, null, 'build_done', '🎉',
                    "Einweihung: „{$project['name']}“ ist fertig!".($build->reward_text ? " Jetzt gibt’s: {$build->reward_text}" : ''),
                    ['project_key' => $build->project_key]);
            });
            $this->push->notify(User::where('family_id', $build->family_id)->get(), 'build_done');
            event(new BuildProjectCompleted($build->refresh(), Catalog::project($build->project_key)));
            $count++;
        });

        return $count;
    }

    private function apply(Home $home, array $project): void
    {
        $e = $project['effects'];
        if (isset($e['room']) && ! HomeService::hasRoom($home, $e['room'])) {
            [$w, $h] = config("game.housing.room_size.{$home->stage}");
            $home->rooms()->create(['type' => $e['room'], 'width' => $w, 'height' => $h]);
        }
        if (isset($e['stage']) && ! $home->stageAtLeast($e['stage'])) {
            $home->stage = $e['stage'];
            [$w, $h] = config("game.housing.room_size.{$e['stage']}");
            // Gemeinschaftsräume wachsen mit; eigene Zimmer behalten ihre Größe
            $home->rooms()->where('type', '!=', 'bedroom')->where('width', '<', $w)->update(['width' => $w, 'height' => $h]);
        }
        if (! empty($e['garden_slots'])) {
            $home->garden_bonus_slots += (int) $e['garden_slots'];
        }
        if (! empty($e['stations'])) {
            $stations = $home->stations ?? [];
            foreach ($e['stations'] as $station => $level) {
                $stations[$station] = max((int) ($stations[$station] ?? 0), (int) $level);
            }
            $home->stations = $stations;
        }
        $home->save();
    }

    public static function isComplete(BuildProject $build): bool
    {
        $progress = $build->progress ?? [];
        $items = (array) ($progress['items'] ?? []);

        return (int) ($progress['gold'] ?? 0) >= (int) $build->requirements['gold']
            && collect($build->requirements['items'])->every(fn ($q, $k) => (int) ($items[$k] ?? 0) >= $q);
    }

    /** Laufende Baustellen und was die Eltern als Nächstes starten können. */
    public static function presentFor(Home $home): array
    {
        $active = BuildProject::with('contributions.user')->where('family_id', $home->family_id)->whereIn('status', ['collecting', 'building'])->get();
        $items = Item::whereIn('key', collect(Catalog::projects())->flatMap(fn ($p) => array_keys($p['items']))->unique())->get()->keyBy('key');

        return [
            'active' => $active->map(fn (BuildProject $b) => self::present($b, $items))->values(),
            'available' => collect(Catalog::projects())
                ->filter(fn ($p) => ! self::applied($home, $p) && self::requirementsMet($home, $p) && ! $active->contains('project_key', $p['key']))
                ->map(fn ($p) => [
                    'key' => $p['key'], 'name' => $p['name'], 'icon' => $p['icon'] ?? '🏗️', 'description' => $p['description'] ?? null,
                    'items' => collect($p['items'])->map(fn ($q, $k) => ['key' => $k, 'name' => $items[$k]->name ?? $k, 'icon' => $items[$k]->icon ?? '📦', 'quantity' => $q])->values(),
                    'gold' => $p['gold'] ?? 0, 'hours' => $p['hours'] ?? 24,
                ])->values(),
        ];
    }

    public static function present(BuildProject $b, $items): array
    {
        $p = Catalog::project($b->project_key);
        $progress = (array) (($b->progress ?? [])['items'] ?? []);

        return [
            'id' => $b->id,
            'key' => $b->project_key,
            'name' => $p['name'],
            'icon' => $p['icon'] ?? '🏗️',
            'status' => $b->status,
            'reward_text' => $b->reward_text,
            'items' => collect($b->requirements['items'])->map(fn ($q, $k) => [
                'key' => $k, 'name' => $items[$k]->name ?? $k, 'icon' => $items[$k]->icon ?? '📦', 'needed' => $q, 'have' => (int) ($progress[$k] ?? 0),
            ])->values(),
            'gold' => ['needed' => (int) $b->requirements['gold'], 'have' => (int) (($b->progress ?? [])['gold'] ?? 0)],
            'finishes_at' => $b->finishes_at?->toIso8601String(),
            'contributors' => $b->contributions->groupBy('user_id')->map(fn ($cs) => ['name' => $cs->first()->user?->name, 'count' => $cs->count()])->values(),
        ];
    }
}
