<?php

namespace App\Domain\Housing;

use App\Domain\Achievements\AchievementService;
use App\Domain\Crafting\Catalog;
use App\Domain\Dungeons\DungeonService;
use App\Domain\Events\EventEngine;
use App\Domain\Features\Features;
use App\Domain\Gameplay\GameException;
use App\Domain\Inventory\Stock;
use App\Domain\Stats\Counters;
use App\Models\GardenPlot;
use App\Models\Home;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Garten (Konzept §2.6): säen → an verschiedenen Tagen gießen → in Echtzeit wachsen lassen → ernten. */
class GardenService
{
    public static function plots(Home $home): Collection
    {
        if (! HomeService::hasRoom($home, 'garden')) {
            return collect();
        }
        for ($slot = 1; $slot <= self::slots($home); $slot++) {
            GardenPlot::firstOrCreate(['family_id' => $home->family_id, 'slot' => $slot]);
        }

        return GardenPlot::where('family_id', $home->family_id)->orderBy('slot')->get();
    }

    /** Beete: Grundzahl plus Gewächshaus und Erntescheune (Bauprojekte). */
    public static function slots(Home $home): int
    {
        return (int) config('game.housing.garden_slots') + (int) $home->garden_bonus_slots;
    }

    public function sow(User $user, int $slot, string $plantKey): GardenPlot
    {
        $plant = Catalog::plant($plantKey);

        return DB::transaction(function () use ($user, $slot, $plant) {
            $plot = $this->plot($user->family_id, $slot);
            if ($plot->plant_key !== null) {
                throw new GameException('In diesem Beet wächst schon etwas.');
            }
            Stock::takeKeys($user->id, $plant['cost']);
            $plot->update([
                'plant_key' => $plant['key'],
                'sown_by' => $user->id,
                'sown_at' => now(),
                'watered_on' => null,
                'waterings' => 0,
                // Frühlingserwachen: Der Garten wächst doppelt so schnell (§12.4)
                'ready_at' => now()->addMinutes((int) round($plant['hours'] * 60 / max(1, EventEngine::modifier($user->family_id, 'garden_speed')))),
            ]);

            Features::starterDone($user, 'garden');

            return $plot;
        });
    }

    /** Gießen geht einmal pro Tag und Beet – aus der App oder über Home Assistant (Pflanzensensor). */
    public function water(int $familyId, int $slot, string $today): GardenPlot
    {
        return DB::transaction(function () use ($familyId, $slot, $today) {
            $plot = $this->plot($familyId, $slot);
            if ($plot->plant_key === null) {
                throw new GameException('In diesem Beet wächst noch nichts.');
            }
            if ($plot->watered_on?->toDateString() === $today) {
                throw new GameException('Dieses Beet ist heute schon gegossen.');
            }
            $plant = Catalog::plant($plot->plant_key);
            $plot->update(['watered_on' => $today, 'waterings' => min($plant['waterings'], $plot->waterings + 1)]);

            return $plot;
        });
    }

    /** @return array<string, int> Ernte */
    public function harvest(User $user, int $slot): array
    {
        return DB::transaction(function () use ($user, $slot) {
            $plot = $this->plot($user->family_id, $slot);
            if ($plot->plant_key === null) {
                throw new GameException('Hier gibt es nichts zu ernten.');
            }
            $plant = Catalog::plant($plot->plant_key);
            if (self::stage($plot, $plant) !== 'ready') {
                throw new GameException('Das ist noch nicht reif.');
            }
            // Erntefest: doppelte Ernte (§12.4)
            $factor = EventEngine::modifier($user->family_id, 'garden_yield');
            $yield = array_map(fn ($q) => (int) round($q * $factor), $plant['yield']);
            Stock::ensureRoom($user->id, collect(Stock::items(array_keys($yield)))->pluck('id')->all());
            Stock::addKeys($user->id, $yield);
            Counters::bump($user->id, 'harvests');
            app(AchievementService::class)->evaluate($user, ['harvests']);
            $plot->update(['plant_key' => null, 'sown_by' => null, 'sown_at' => null, 'watered_on' => null, 'waterings' => 0, 'ready_at' => null]);

            return $yield;
        });
    }

    /** empty | thirsty (braucht noch Wasser) | growing | ready */
    public static function stage(GardenPlot $plot, ?array $plant = null): string
    {
        if ($plot->plant_key === null) {
            return 'empty';
        }
        $plant ??= Catalog::plant($plot->plant_key);
        if ($plot->waterings < $plant['waterings']) {
            return 'thirsty';
        }

        return $plot->ready_at && $plot->ready_at->lte(now()) ? 'ready' : 'growing';
    }

    public static function present(Home $home): ?array
    {
        if (! HomeService::hasRoom($home, 'garden')) {
            return null;
        }
        $today = CarbonImmutable::now($home->family->timezone ?? 'Europe/Berlin')->toDateString();

        return [
            'plants' => collect(Catalog::plants())->map(fn ($p) => [
                'key' => $p['key'], 'name' => $p['name'], 'icon' => $p['icon'], 'cost' => $p['cost'], 'waterings' => $p['waterings'], 'hours' => $p['hours'], 'yield' => $p['yield'],
                // Saatgut und Ernte mit deutschem Namen und Symbol für die Anzeige
                'cost_items' => DungeonService::lootList($p['cost']), 'yield_items' => DungeonService::lootList($p['yield']),
            ])->values(),
            'plots' => self::plots($home)->map(fn (GardenPlot $plot) => [
                'slot' => $plot->slot,
                'plant_key' => $plot->plant_key,
                'stage' => self::stage($plot),
                'waterings' => $plot->waterings,
                'watered_today' => $plot->watered_on?->toDateString() === $today,
                'ready_at' => $plot->ready_at?->toIso8601String(),
            ])->values(),
        ];
    }

    private function plot(?int $familyId, int $slot): GardenPlot
    {
        $home = HomeService::home($familyId ?? throw new GameException('Einen Garten gibt es im Familienhaus.'));
        if (! HomeService::hasRoom($home, 'garden')) {
            throw new GameException('Baut zuerst einen Garten.');
        }
        if ($slot < 1 || $slot > self::slots($home)) {
            throw new GameException('Dieses Beet gibt es nicht.');
        }

        return GardenPlot::where('family_id', $familyId)->where('slot', $slot)->lockForUpdate()->first()
            ?? GardenPlot::create(['family_id' => $familyId, 'slot' => $slot]);
    }
}
