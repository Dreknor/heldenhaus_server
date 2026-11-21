<?php

namespace App\Http\Controllers;

use App\Domain\Features\Features;
use App\Domain\Gameplay\GameException;
use App\Http\Presenters\FeatureView;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Web-Gegenstück zu FeatureController (§13): Heldenbuch, Freischalt-Karte, „Alles sofort zeigen“, Eltern-Einstellung. */
class FeatureWebController extends Controller
{
    public function book(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('features/Book', [
            'features' => FeatureView::book($user),
            'level' => (int) ($user->stats()->value('level') ?? 1),
            'enforced' => Features::enforced(),
            'showAll' => (bool) $user->show_all_features,
            'canShowAll' => Features::canShowAll($user),
        ]);
    }

    /** Freischalt-Karte geschlossen: „Zeig's mir“ öffnet die Funktion mit Tour, „Später“ bleibt auf der Seite. */
    public function introSeen(Request $request, string $key): RedirectResponse
    {
        abort_unless(Features::get($key) !== null, 404);
        $user = $request->user();
        Features::introSeen($user, $key);

        if ($request->boolean('tour') && Features::has($user, $key)) {
            return redirect(FeatureView::tourUrl($key));
        }

        return back();
    }

    public function showAll(Request $request, Features $features): RedirectResponse
    {
        $on = $request->validate(['on' => ['required', 'boolean']])['on'];
        $features->showAll($request->user(), (bool) $on);

        return back()->with('success', $on ? 'Alles ist jetzt sichtbar.' : 'Neue Funktionen kommen wieder Schritt für Schritt.');
    }

    public function updateMember(Request $request, User $member, Features $features): RedirectResponse
    {
        $data = $request->validate([
            'features' => ['sometimes', 'array'],
            'features.*' => ['nullable', Rule::in(['early', 'defer'])],
            'fast_start' => ['sometimes', 'boolean'],
        ]);
        if (! $request->user()->isParent() || ! $request->user()->sameFamilyAs($member)) {
            throw new GameException('Das stellen die Eltern ein.');
        }
        $features->override($request->user(), $member, $data['features'] ?? [], isset($data['fast_start']) ? (bool) $data['fast_start'] : null);

        return back()->with('success', "Freischaltungen für {$member->name} gespeichert.");
    }
}
