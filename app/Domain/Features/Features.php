<?php

namespace App\Domain\Features;

use App\Domain\Events\EventEngine;
use App\Domain\Gameplay\GameException;
use App\Domain\Integrations\HomeAssistant\HomeAssistantWebhooks;
use App\Domain\Inventory\Stock;
use App\Domain\Social\FeedService;
use App\Domain\Support\FocusService;
use App\Enums\AgeGroup;
use App\Models\FeatureUnlock;
use App\Models\Item;
use App\Models\MemberFeatureOverride;
use App\Models\User;
use App\Models\UserItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Freischaltung von Funktionen nach Level (Konzept §13). Features::has() ist die eine Stelle für die Prüfung.
 * Familienrelevantes (Aufgaben, Freigaben, Sterne, Shop, Feed, HA, Fokus-Hilfen) ist nie gesperrt und steht
 * deshalb gar nicht im Katalog.
 */
class Features
{
    private static ?array $catalog = null;

    /** Schnellstart: diese Freischaltungen kommen nach Tagen statt nach Level. */
    public const FAST_START_MAX_LEVEL = 6;

    /** @return array<string, array> */
    public static function catalog(): array
    {
        return self::$catalog ??= collect(json_decode(file_get_contents(database_path('content/features.json')), true, flags: JSON_THROW_ON_ERROR))
            ->keyBy('key')->all();
    }

    public static function get(string $key): ?array
    {
        return self::catalog()[$key] ?? null;
    }

    /** Ist die Prüfung aktiv? (In Tests standardmäßig aus, siehe tests/TestCase.php.) */
    public static function enforced(): bool
    {
        return (bool) config('game.features.enforce', true);
    }

    public static function levelFor(string $key, User $user): int
    {
        $f = self::get($key) ?? throw new \InvalidArgumentException("Unbekannte Funktion {$key}");

        return (int) ($f['level_by_age'][$user->ageGroup()->value] ?? $f['level']);
    }

    public static function has(User $user, string $key): bool
    {
        $feature = self::get($key);
        if ($feature === null || ($feature['family_relevant'] ?? false) || ! self::enforced()) {
            return true;
        }

        return self::state($user, $key) === 'unlocked' || self::state($user, $key) === 'available';
    }

    public static function ensure(User $user, string $key): void
    {
        if (! self::has($user, $key)) {
            throw new FeatureLocked($key, self::levelFor($key, $user));
        }
    }

    /** Hat mindestens ein Mitglied der Familie die Funktion (z. B. Werkstatt für den Wandernden Händler)? */
    public static function familyHas(?int $familyId, string $key): bool
    {
        return $familyId !== null && User::where('family_id', $familyId)->get()->contains(fn (User $u) => self::has($u, $key));
    }

    /**
     * unlocked (Zeile vorhanden) · available (fällig, Zeile entsteht beim nächsten Abgleich) · deferred · locked
     */
    public static function state(User $user, string $key): string
    {
        $unlocks = self::unlocks($user);
        if ($unlocks->has($key)) {
            return 'unlocked';
        }
        $override = self::overrides($user)[$key] ?? null;
        if ($override === 'defer') {
            return 'deferred';
        }
        if ($override === 'early' || $user->show_all_features) {
            return 'available';
        }
        $depends = self::get($key)['depends_on'] ?? null;
        if ($depends && ! $unlocks->has($depends) && self::state($user, $depends) !== 'available') {
            return 'locked';
        }
        if ((int) ($user->stats?->level ?? $user->stats()->value('level') ?? 1) >= self::levelFor($key, $user)) {
            return 'available';
        }
        if ($user->fast_start_at && self::fastStartAllows($user, $key)) {
            return 'available';
        }

        return 'locked';
    }

    /**
     * Fällige Freischaltungen anlegen (nach Level-up, Tageswechsel, Eltern-Freigabe): Feed-Eintrag,
     * Starter-Gegenstände, Erklärung in die Warteschlange. Gibt die neu freigeschalteten Schlüssel zurück.
     *
     * @return list<string>
     */
    public static function sync(User $user, ?string $source = null): array
    {
        if (! $user->family_id) {
            return [];
        }
        $new = [];
        foreach (self::catalog() as $key => $feature) {
            if (self::state($user, $key) !== 'available') {
                continue;
            }
            $origin = $source ?? match (true) {
                (self::overrides($user)[$key] ?? null) === 'early' => 'parent',
                (bool) $user->show_all_features => 'self',
                $user->fast_start_at !== null && (int) ($user->stats?->level ?? 1) < self::levelFor($key, $user) => 'fast_start',
                default => 'level',
            };
            $unlock = FeatureUnlock::firstOrCreate(['user_id' => $user->id, 'feature_key' => $key], ['unlocked_at' => now(), 'source' => $origin]);
            if (! $unlock->wasRecentlyCreated) {
                continue;
            }
            $new[] = $key;
            if (! empty($feature['starter_items'])) {
                Stock::addKeys($user->id, collect($feature['starter_items'])->filter(fn ($q, $k) => Item::where('key', $k)->exists())->all());
            }
            app(FeedService::class)->post($user->family_id, $user, 'feature_unlocked', '🔓', "{$user->name} hat „{$feature['intro']['title']}“ freigeschaltet!", ['feature' => $key]);
            app(HomeAssistantWebhooks::class)->send($user->family_id, 'feature_unlocked', ['feature' => $key, 'title' => $feature['intro']['title']] + HomeAssistantWebhooks::member($user));
        }
        self::forget($user);

        return $new;
    }

    /** Für Board und Abgleich: freigeschaltet, nächste Freischaltung, ausstehende Erklärung (höchstens eine pro Tag). */
    public static function summary(User $user): array
    {
        if (! self::enforced()) {
            return ['enforced' => false, 'unlocked' => array_keys(self::catalog()), 'next' => null, 'intro' => null];
        }
        self::sync($user);
        $unlocks = self::unlocks($user);
        $level = (int) ($user->stats?->level ?? $user->stats()->value('level') ?? 1);

        $next = collect(self::catalog())->reject(fn ($f, $k) => $unlocks->has($k) || self::state($user, $k) === 'deferred')
            ->sortBy(fn ($f, $k) => self::levelFor($k, $user))->keys()->first();

        return [
            'enforced' => true,
            'unlocked' => $unlocks->keys()->values()->all(),
            'deferred' => collect(self::overrides($user))->filter(fn ($m) => $m === 'defer')->keys()->values()->all(),
            'show_all' => (bool) $user->show_all_features,
            'can_show_all' => self::canShowAll($user),
            'next' => $next ? [
                'key' => $next, 'title' => self::get($next)['intro']['title'], 'level' => self::levelFor($next, $user),
                'levels_to_go' => max(0, self::levelFor($next, $user) - $level), 'preview_image' => self::get($next)['preview_image'] ?? null,
            ] : null,
            'intro' => self::pendingIntro($user),
            'starters' => $unlocks->filter(fn (FeatureUnlock $u) => $u->starter_done_at === null)->keys()->map(fn ($k) => [
                'key' => $k, 'goal' => self::get($k)['starter_task']['goal'] ?? null, 'rewards' => self::get($k)['starter_task']['rewards'] ?? null,
            ])->values()->all(),
        ];
    }

    /** Höchstens eine Erklärung pro Tag (§13.1), im Fokus-Modus erst danach. */
    public static function pendingIntro(User $user): ?array
    {
        $unlocks = self::unlocks($user);
        $today = $user->gameDate()->utc();
        $shownToday = $unlocks->first(fn (FeatureUnlock $u) => $u->intro_shown_at && $u->intro_shown_at->gte($today) && $u->intro_seen_at === null);
        $alreadyToday = $unlocks->contains(fn (FeatureUnlock $u) => $u->intro_shown_at && $u->intro_shown_at->gte($today));
        $unlock = $shownToday ?? ($alreadyToday ? null : $unlocks->filter(fn (FeatureUnlock $u) => $u->intro_shown_at === null)->sortBy('unlocked_at')->first());
        if ($unlock === null || FocusService::running($user) !== null) {
            return null;
        }
        if ($unlock->intro_shown_at === null) {
            $unlock->forceFill(['intro_shown_at' => now()])->save();
        }
        $f = self::get($unlock->feature_key);
        $age = $user->ageGroup()->value;

        return [
            'key' => $unlock->feature_key,
            'title' => $f['intro']['title'],
            'text' => $f['intro']['text'][$age] ?? $f['intro']['text']['child'],
            'tour' => array_slice($f['tour'] ?? [], 0, 3),
            'starter_task' => $f['starter_task'] ?? null,
            'preview_image' => $f['preview_image'] ?? null,
            // „Vorher sammeln, später staunen“: was schon in der Materialtasche liegt
            'collected' => $unlock->feature_key === 'crafting' ? self::materials($user) : null,
        ];
    }

    public static function introSeen(User $user, string $key): void
    {
        FeatureUnlock::where('user_id', $user->id)->where('feature_key', $key)->update(['intro_seen_at' => now(), 'intro_shown_at' => now()]);
        self::forget($user);
    }

    /** Erste Mini-Aufgabe geschafft: kleine Belohnung, genau einmal. Wird meist vom Server selbst erkannt. */
    public static function starterDone(User $user, string $key): bool
    {
        if (! self::enforced() || self::get($key) === null) {
            return false;
        }
        $updated = FeatureUnlock::where('user_id', $user->id)->where('feature_key', $key)->whereNull('starter_done_at')->update(['starter_done_at' => now()]);
        if ($updated === 0) {
            return false;
        }
        app(EventEngine::class)->reward($user, self::get($key)['starter_task']['rewards'] ?? []);
        self::forget($user);

        return true;
    }

    public static function canShowAll(User $user): bool
    {
        return $user->ageGroup() !== AgeGroup::Child;
    }

    public function showAll(User $user, bool $on): void
    {
        if ($on && ! self::canShowAll($user)) {
            throw new GameException('„Alles sofort zeigen“ gibt es ab 13.');
        }
        $user->forceFill(['show_all_features' => $on])->save();
        self::forget($user);
        if ($on) {
            self::sync($user, 'self');
        }
    }

    /**
     * Eltern: früher freischalten (early), später freischalten (defer, nur Kinder unter 13), Schnellstart.
     *
     * @param  array<string, ?string>  $modes
     */
    public function override(User $parent, User $member, array $modes, ?bool $fastStart = null): array
    {
        if (! $parent->isParent() || ! $parent->sameFamilyAs($member)) {
            throw new GameException('Das stellen die Eltern ein.');
        }
        foreach ($modes as $key => $mode) {
            $feature = self::get($key) ?? throw new GameException("Unbekannte Funktion „{$key}“.");
            if ($mode === null) {
                MemberFeatureOverride::where('user_id', $member->id)->where('feature_key', $key)->delete();

                continue;
            }
            if ($mode === 'defer' && ($member->ageGroup() !== AgeGroup::Child || ! ($feature['parent_can_defer'] ?? true))) {
                throw new GameException('Später freischalten geht nur für Kinder unter 13.');
            }
            MemberFeatureOverride::updateOrCreate(['user_id' => $member->id, 'feature_key' => $key], ['mode' => $mode, 'set_by' => $parent->id]);
        }
        if ($fastStart !== null) {
            $member->forceFill(['fast_start_at' => $fastStart ? ($member->fast_start_at ?? now()) : null])->save();
        }
        self::forget($member);
        self::sync($member);

        return self::forMember($member);
    }

    public static function forMember(User $member): array
    {
        $overrides = self::overrides($member);

        return [
            'fast_start' => $member->fast_start_at !== null,
            'features' => collect(self::catalog())->map(fn ($f, $k) => [
                'key' => $k, 'title' => $f['intro']['title'], 'level' => self::levelFor($k, $member),
                'state' => self::state($member, $k), 'override' => $overrides[$k] ?? null,
                'can_defer' => $member->ageGroup() === AgeGroup::Child && ($f['parent_can_defer'] ?? true),
            ])->values()->all(),
        ];
    }

    // ---------- intern ----------

    /** @var array<int, array{unlocks: Collection, overrides: array}> */
    private static array $memo = [];

    /** @return Collection<string, FeatureUnlock> */
    private static function unlocks(User $user): Collection
    {
        return self::$memo[$user->id]['unlocks'] ??= FeatureUnlock::where('user_id', $user->id)->get()->keyBy('feature_key');
    }

    /** @return array<string, string> */
    private static function overrides(User $user): array
    {
        return self::$memo[$user->id]['overrides'] ??= MemberFeatureOverride::where('user_id', $user->id)->pluck('mode', 'feature_key')->all();
    }

    public static function forget(?User $user = null): void
    {
        if ($user === null) {
            self::$memo = [];
        } else {
            unset(self::$memo[$user->id]);
        }
    }

    private static function fastStartAllows(User $user, string $key): bool
    {
        if (self::levelFor($key, $user) > self::FAST_START_MAX_LEVEL) {
            return false;
        }
        $order = collect(self::catalog())->filter(fn ($f, $k) => self::levelFor($k, $user) <= self::FAST_START_MAX_LEVEL)
            ->sortBy(fn ($f, $k) => self::levelFor($k, $user))->keys()->values();
        $days = (int) CarbonImmutable::instance($user->fast_start_at)->startOfDay()->diffInDays(now()->startOfDay());

        return $order->search($key) !== false && $order->search($key) <= $days;
    }

    private static function materials(User $user): array
    {
        return UserItem::with('item')->where('user_id', $user->id)->where('quantity', '>', 0)->get()
            ->filter(fn (UserItem $u) => $u->item?->type === 'material')
            ->map(fn (UserItem $u) => ['key' => $u->item->key, 'name' => $u->item->name, 'icon' => $u->item->icon, 'quantity' => $u->quantity])
            ->sortByDesc('quantity')->values()->all();
    }
}
