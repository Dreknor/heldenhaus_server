<?php

namespace App\Http\Presenters;

use App\Domain\Features\Features;
use App\Domain\Gameplay\Formulas;
use App\Models\FeatureUnlock;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Freischaltungen (§13) für die Web-Oberfläche: Navigation, Freischalt-Karte, Vorschau-Seiten,
 * Heldenbuch und kurze Touren. Die Logik selbst steckt in Features.
 */
class FeatureView
{
    /** Kurzinfo zu einer Funktion: was sie ist, ab welchem Level, ob sie offen ist. */
    public static function entry(User $user, string $key): array
    {
        $f = Features::get($key);
        $state = Features::enforced() ? Features::state($user, $key) : 'unlocked';

        return [
            'key' => $key,
            'title' => $f['intro']['title'],
            'emoji' => $f['emoji'] ?? '🔒',
            'teaser' => $f['teaser'] ?? null,
            'href' => $f['href'] ?? '/dashboard',
            'level' => Features::levelFor($key, $user),
            'state' => $state === 'available' ? 'unlocked' : $state,
            'open' => Features::has($user, $key),
        ];
    }

    /** Für alle Seiten (Inertia-Shared-Props): Stand der Freischaltungen, Freischalt-Karte, Starter-Aufgaben. */
    public static function shared(User $user): array
    {
        $summary = Features::summary($user);
        $list = collect(array_keys(Features::catalog()))->map(fn (string $k) => self::entry($user, $k));
        $byKey = $list->keyBy('key');

        $intro = $summary['intro'] ?? null;
        if ($intro !== null) {
            $intro += [
                'emoji' => $byKey[$intro['key']]['emoji'],
                'href' => $byKey[$intro['key']]['href'],
            ];
        }
        $next = $summary['next'] ?? null;
        if ($next !== null) {
            $next += ['emoji' => $byKey[$next['key']]['emoji'], 'teaser' => $byKey[$next['key']]['teaser']];
        }

        return [
            'enforced' => $summary['enforced'],
            'show_all' => (bool) ($summary['show_all'] ?? $user->show_all_features),
            'can_show_all' => Features::canShowAll($user),
            'list' => $list->values()->all(),
            'next' => $next,
            'intro' => $intro,
            'starters' => collect($summary['starters'] ?? [])->filter(fn (array $s) => $s['goal'] !== null)
                ->map(fn (array $s) => $s + [
                    'title' => $byKey[$s['key']]['title'],
                    'emoji' => $byKey[$s['key']]['emoji'],
                    'href' => $byKey[$s['key']]['href'],
                ])->values()->all(),
        ];
    }

    /** Vorschau einer gesperrten Seite: nur Name, Level und ein Satz, was kommt – keine Daten der Funktion. */
    public static function preview(User $user, array $keys): array
    {
        $entries = collect($keys)->map(fn (string $k) => self::entry($user, $k));
        $feature = $entries->sortBy('level')->first();
        $stats = $user->stats()->first();

        return [
            'feature' => $feature,
            'others' => $entries->reject(fn ($e) => $e['key'] === $feature['key'])->values()->all(),
            'level' => (int) ($stats?->level ?? 1),
            'xp' => (float) ($stats?->xp ?? 0),
            'xpToNext' => (float) Formulas::xpToNextLevel((int) ($stats?->level ?? 1)),
            'dependsOn' => ($dep = Features::get($feature['key'])['depends_on'] ?? null) && ! Features::has($user, $dep)
                ? self::entry($user, $dep) : null,
        ];
    }

    /** Heldenbuch: alle Funktionen, was schon offen ist, was kommt, Erklärungen zum Nachlesen. */
    public static function book(User $user): array
    {
        $age = $user->ageGroup()->value;
        $unlocks = FeatureUnlock::where('user_id', $user->id)->get()->keyBy('feature_key');

        return collect(Features::catalog())->map(function (array $f, string $key) use ($user, $age, $unlocks) {
            $entry = self::entry($user, $key);
            $open = $entry['open'];

            return $entry + [
                // Erklärung und Tour erst, wenn die Funktion offen ist; vorher nur die kurze Vorschau
                'text' => $open ? ($f['intro']['text'][$age] ?? $f['intro']['text']['child']) : null,
                'tour' => $open ? array_slice($f['tour'] ?? [], 0, 3) : [],
                'starter' => $f['starter_task'] ?? null,
                'starter_done' => $unlocks->has($key) ? $unlocks[$key]->starter_done_at !== null : false,
                'unlocked_at' => $unlocks->has($key) ? $unlocks[$key]->unlocked_at?->toDateString() : null,
            ];
        })->sortBy('level')->values()->all();
    }

    /** Kurze Tour (§13.3): höchstens drei Stellen, nur für offene Funktionen, per ?tour=schlüssel. */
    public static function tour(Request $request, ?User $user): ?array
    {
        $key = $request->query('tour');
        if (! is_string($key) || $user === null || Features::get($key) === null || ! Features::has($user, $key)) {
            return null;
        }
        $f = Features::get($key);

        return ['key' => $key, 'title' => $f['intro']['title'], 'emoji' => $f['emoji'] ?? null, 'steps' => array_slice($f['tour'] ?? [], 0, 3)];
    }

    /** Ziel-URL einer Funktion mit gestarteter Tour. */
    public static function tourUrl(string $key): string
    {
        $href = Features::get($key)['href'] ?? '/dashboard';

        return $href.(str_contains($href, '?') ? '&' : '?').'tour='.$key;
    }
}
