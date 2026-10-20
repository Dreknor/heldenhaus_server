<?php

namespace App\Domain\Crafting;

use App\Domain\Gameplay\GameException;
use App\Domain\Gameplay\Progression;
use App\Domain\Housing\HomeService;
use App\Domain\Inventory\Stock;
use App\Domain\Notifications\PushNotifier;
use App\Domain\Social\FeedService;
use App\Models\CraftJob;
use App\Models\Item;
use App\Models\User;
use App\Models\UserCrafting;
use App\Models\UserRecipe;
use App\Models\UserStats;
use Illuminate\Support\Facades\DB;
use Random\Randomizer;

/**
 * Crafting (Konzept §2.5): Rezept + Materialien + Gold an einer Station → Herstellungsauftrag mit Echtzeit-Dauer.
 * Die Qualität (normal/gut/meisterhaft) wird beim Start ausgewürfelt; die Chance steigt mit der Handwerksstufe.
 * Klassen geben bewusst keine Crafting-Boni.
 */
class CraftingService
{
    /** Gute bzw. meisterhafte Verbrauchsgüter gibt es öfter statt besser. */
    public const BONUS_QUANTITY = ['normal' => 0, 'good' => 1, 'master' => 2];

    private Randomizer $random;

    public function __construct(private readonly FeedService $feed, ?Randomizer $random = null)
    {
        $this->random = $random ?? new Randomizer;
    }

    public static function crafting(User $user): UserCrafting
    {
        return UserCrafting::firstOrCreate(['user_id' => $user->id]);
    }

    public static function isUnlocked(User $user, array $recipe, ?UserCrafting $crafting = null): bool
    {
        $crafting ??= self::crafting($user);
        if ($crafting->level < ($recipe['level'] ?? 1)) {
            return false;
        }

        return ($recipe['unlock'] ?? 'level') === 'level'
            || UserRecipe::where('user_id', $user->id)->where('recipe_key', $recipe['key'])->exists();
    }

    /** Rezept in der Bibliothek lernen (kostet Pergament und Tinte). */
    public function learn(User $user, string $key): UserRecipe
    {
        $recipe = Catalog::recipe($key);
        if (($recipe['unlock'] ?? 'level') !== 'library') {
            throw new GameException('Dieses Rezept kennst du ab der passenden Handwerksstufe von selbst.');
        }
        if (! $user->family_id || ! HomeService::hasRoom(HomeService::home($user->family_id), 'library')) {
            throw new GameException('Rezepte lernt man in der Bibliothek – baut zuerst eine.');
        }

        return DB::transaction(function () use ($user, $recipe) {
            if (UserRecipe::where('user_id', $user->id)->where('recipe_key', $recipe['key'])->exists()) {
                throw new GameException('Dieses Rezept kennst du schon.');
            }
            Stock::takeKeys($user->id, $recipe['learn'] ?? []);

            return UserRecipe::create(['user_id' => $user->id, 'recipe_key' => $recipe['key'], 'source' => 'library']);
        });
    }

    /** Rezept als Belohnung freischalten (Quest, seltener Fund). */
    public static function grant(User $user, string $key, string $source): void
    {
        Catalog::recipe($key);
        UserRecipe::firstOrCreate(['user_id' => $user->id, 'recipe_key' => $key], ['source' => $source]);
    }

    public function start(User $user, string $key): CraftJob
    {
        $recipe = Catalog::recipe($key);
        if (! $user->family_id) {
            throw new GameException('Crafting gibt es im Familienhaus.');
        }

        $home = HomeService::home($user->family_id);
        $station = $recipe['station'];
        if ($home->station($station) < ($recipe['station_level'] ?? 1)) {
            throw new GameException('Dafür braucht ihr '.self::stationName($station).' Stufe '.($recipe['station_level'] ?? 1).'.');
        }

        $crafting = self::crafting($user);
        if (! self::isUnlocked($user, $recipe, $crafting)) {
            throw new GameException(($recipe['unlock'] ?? 'level') === 'library' && $crafting->level >= $recipe['level']
                ? 'Dieses Rezept musst du zuerst in der Bibliothek lernen.'
                : "Dieses Rezept gibt es ab Handwerksstufe {$recipe['level']}.");
        }

        return DB::transaction(function () use ($user, $recipe, $crafting) {
            $stats = UserStats::lockForUpdate()->findOrFail($user->id);
            $running = CraftJob::where('user_id', $user->id)->where('status', '!=', 'collected')->count();
            if ($running >= config('game.crafting.max_jobs')) {
                throw new GameException('Du hast schon '.config('game.crafting.max_jobs').' Aufträge laufen. Hol zuerst etwas ab.');
            }
            if ($stats->gold < ($recipe['gold'] ?? 0)) {
                throw new GameException('Dafür reicht dein Gold noch nicht.');
            }

            Stock::takeKeys($user->id, $recipe['inputs']);
            Progression::changeGold($stats, -($recipe['gold'] ?? 0));
            $stats->save();

            $item = Item::where('key', $recipe['output'])->firstOrFail();
            $quality = $this->rollQuality($crafting->level);
            $consumable = ! in_array($item->type, ['equipment', 'furniture'], true);

            return CraftJob::create([
                'user_id' => $user->id,
                'recipe_key' => $recipe['key'],
                'item_id' => $item->id,
                'quantity' => ($recipe['quantity'] ?? 1) + ($consumable ? self::BONUS_QUANTITY[$quality] : 0),
                'quality' => $quality,
                'started_at' => now(),
                'finishes_at' => now()->addSeconds((int) ($recipe['seconds'] ?? 0)),
                'status' => ($recipe['seconds'] ?? 0) > 0 ? 'running' : 'ready',
            ]);
        });
    }

    /** @return array{job: CraftJob, level_up: bool} */
    public function collect(User $user, CraftJob $job): array
    {
        if ($job->user_id !== $user->id) {
            throw new GameException('Das ist nicht dein Auftrag.');
        }

        return DB::transaction(function () use ($user, $job) {
            $job = CraftJob::with('item')->lockForUpdate()->findOrFail($job->id);
            if ($job->status === 'collected') {
                throw new GameException('Das hast du schon abgeholt.');
            }
            if (! $job->isDone()) {
                throw new GameException('Das ist noch nicht fertig.');
            }

            $master = $job->quality === 'master' && in_array($job->item->type, ['equipment', 'furniture'], true);
            Stock::add($user->id, $job->item_id, $job->quantity, $master);
            $job->update(['status' => 'collected', 'collected_at' => now()]);

            $levelUp = $this->addXp($user, (int) (Catalog::recipes()[$job->recipe_key]['xp'] ?? 1));
            if ($job->quality === 'master') {
                $this->feed->post($user->family_id, $user, 'craft_master', '🌟', "{$user->name} hat meisterhaft hergestellt: {$job->item->name}");
            }

            return ['job' => $job, 'level_up' => $levelUp];
        });
    }

    /** Vom Scheduler: fertige Aufträge melden (Push ohne Inhalt, §2.5). */
    public function markReady(PushNotifier $push): int
    {
        $jobs = CraftJob::with('user')->where('status', 'running')->where('finishes_at', '<=', now())->get();
        CraftJob::whereIn('id', $jobs->pluck('id'))->update(['status' => 'ready', 'notified' => true]);
        $jobs->pluck('user')->unique('id')->each(fn (User $u) => $push->notify($u, 'craft_ready'));

        return $jobs->count();
    }

    public function rollQuality(int $level): string
    {
        $master = min(config('game.crafting.master.max'), config('game.crafting.master.base') + config('game.crafting.master.step') * $level);
        $good = min(config('game.crafting.good.max'), config('game.crafting.good.base') + config('game.crafting.good.step') * ($level - 1));
        $roll = $this->random->nextFloat();

        return match (true) {
            $roll < $master => 'master',
            $roll < $master + $good => 'good',
            default => 'normal',
        };
    }

    private function addXp(User $user, int $xp): bool
    {
        $crafting = UserCrafting::lockForUpdate()->findOrFail($user->id);
        $crafting->xp += $xp;
        $levelUp = false;
        while ($crafting->level < config('game.crafting.max_level') && $crafting->xp >= $crafting->level * config('game.crafting.xp_per_level')) {
            $crafting->xp -= $crafting->level * config('game.crafting.xp_per_level');
            $crafting->level++;
            $levelUp = true;
        }
        $crafting->save();

        if ($levelUp) {
            $this->feed->post($user->family_id, $user, 'craft_level', '🛠️', "{$user->name} ist jetzt Handwerksstufe {$crafting->level}!");
        }

        return $levelUp;
    }

    public static function stationName(string $key): string
    {
        return ['workbench' => 'die Werkbank', 'forge' => 'die Schmiede', 'alchemy' => 'den Alchemietisch', 'sewing' => 'den Nähtisch'][$key] ?? $key;
    }

    public static function present(User $user): array
    {
        $crafting = self::crafting($user);
        $home = $user->family_id ? HomeService::home($user->family_id) : null;
        $learned = UserRecipe::where('user_id', $user->id)->pluck('recipe_key')->all();
        $recipes = Catalog::recipes();
        $keys = collect($recipes)->flatMap(fn ($r) => array_merge([$r['output']], array_keys($r['inputs']), array_keys($r['learn'] ?? [])))->unique()->values()->all();
        $items = Item::whereIn('key', $keys)->get()->keyBy('key');
        $have = Stock::counts($user->id, $keys);
        $item = fn (string $key, int $qty) => ['key' => $key, 'name' => $items[$key]->name ?? $key, 'icon' => $items[$key]->icon ?? '📦', 'quantity' => $qty, 'have' => $have[$key] ?? 0];

        return [
            'level' => $crafting->level,
            'xp' => $crafting->xp,
            'xp_next' => $crafting->level * config('game.crafting.xp_per_level'),
            'stations' => (object) ($home?->stations ?? []),
            'max_jobs' => config('game.crafting.max_jobs'),
            'jobs' => CraftJob::with('item')->where('user_id', $user->id)->where('status', '!=', 'collected')->orderBy('finishes_at')->get()
                ->map(fn (CraftJob $j) => [
                    'id' => $j->id,
                    'recipe_key' => $j->recipe_key,
                    'item' => ['key' => $j->item->key, 'name' => $j->item->name, 'icon' => $j->item->icon],
                    'quantity' => $j->quantity,
                    'quality' => $j->quality,
                    'finishes_at' => $j->finishes_at->toIso8601String(),
                    'ready' => $j->isDone(),
                ])->values(),
            'recipes' => collect($recipes)->map(function (array $r) use ($crafting, $learned, $home, $item) {
                $library = ($r['unlock'] ?? 'level') === 'library';
                $unlocked = $crafting->level >= $r['level'] && (! $library || in_array($r['key'], $learned, true));
                $inputs = collect($r['inputs'])->map(fn ($q, $k) => $item($k, $q))->values();

                return [
                    'key' => $r['key'],
                    'output' => $item($r['output'], $r['quantity'] ?? 1),
                    'station' => $r['station'],
                    'station_level' => $r['station_level'] ?? 1,
                    'level' => $r['level'],
                    'unlock' => $r['unlock'] ?? 'level',
                    'learn' => $library ? collect($r['learn'] ?? [])->map(fn ($q, $k) => $item($k, $q))->values() : null,
                    'inputs' => $inputs,
                    'gold' => $r['gold'] ?? 0,
                    'seconds' => $r['seconds'] ?? 0,
                    'unlocked' => $unlocked,
                    'station_ok' => $home !== null && $home->station($r['station']) >= ($r['station_level'] ?? 1),
                    'has_materials' => $inputs->every(fn ($i) => $i['have'] >= $i['quantity']),
                ];
            })->values(),
        ];
    }
}
