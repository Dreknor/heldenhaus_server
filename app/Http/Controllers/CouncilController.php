<?php

namespace App\Http\Controllers;

use App\Domain\Council\CouncilService;
use App\Domain\Council\VoteService;
use App\Domain\Gameplay\GameException;
use App\Models\FamilyCouncil;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Familienrat (§14.4): geführter Ablauf am Familiengerät (Web) und `GET|POST family-council` (App). */
class CouncilController extends Controller
{
    public function __construct(private readonly CouncilService $councils) {}

    public function show(Request $request): Response|JsonResponse
    {
        $data = CouncilService::overview($request->user());

        return $request->expectsJson() ? response()->json($data) : Inertia::render('family/Council', $data);
    }

    /** Ein Schritt im Ablauf: start, step, thank, wish, open_vote, finish. */
    public function act(Request $request, VoteService $votes): RedirectResponse|JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'action' => ['required', Rule::in(['start', 'step', 'thank', 'wish', 'open_vote', 'finish'])],
            'step' => ['required_if:action,step', 'integer', 'between:1,5'],
            'from_id' => ['required_if:action,thank', 'integer'],
            'to_id' => ['required_if:action,thank', 'integer'],
            'reason' => ['required_if:action,thank', 'nullable', 'string', 'max:120'],
            'member_id' => ['required_if:action,wish', 'integer'],
            'text' => ['required_if:action,wish', 'nullable', 'string', 'max:120'],
            'kind' => ['nullable', Rule::in(['note', 'goal'])],
        ]);

        $council = $data['action'] === 'start' ? null : CouncilService::running($user->family_id);
        if ($data['action'] !== 'start' && $data['action'] !== 'open_vote' && $council === null) {
            throw new GameException('Gerade läuft kein Familienrat.');
        }

        $message = match ($data['action']) {
            'start' => $this->started($this->councils->start($user)),
            'step' => $this->ok($this->councils->step($user, $council, (int) $data['step'])),
            'thank' => $this->ok($this->councils->thank($user, $council, $this->member($user, $data['from_id']), $this->member($user, $data['to_id']), (string) $data['reason']), 'Danke ist im Feed!'),
            'wish' => $this->ok($this->councils->wish($user, $council, $this->member($user, $data['member_id']), (string) $data['text'], $data['kind'] ?? 'note'), 'Wunsch notiert.'),
            'open_vote' => $votes->open($user, $council?->id) ? 'Die Abstimmung läuft!' : null,
            'finish' => $this->ok($this->councils->finish($user, $council), 'Familienrat beendet – schön, dass ihr dabei wart!'),
        };

        return $request->expectsJson()
            ? response()->json(CouncilService::overview($user->refresh()))
            : back()->with('success', $message);
    }

    public function updateSettings(Request $request): RedirectResponse|JsonResponse
    {
        $this->authorize('manage', $request->user()->family);
        $data = $request->validate([
            'enabled' => ['boolean'],
            'weekday' => ['required', 'integer', 'between:0,6'],
            'time' => ['required', 'date_format:H:i'],
            'free_text' => ['boolean'],
        ], [], ['weekday' => 'Wochentag', 'time' => 'Uhrzeit']);

        $settings = $this->councils->updateSettings($request->user(), $data);

        return $request->expectsJson() ? response()->json($settings) : back()->with('success', 'Familienrat gespeichert.');
    }

    private function member(User $actor, int $id): User
    {
        return User::where('family_id', $actor->family_id)->whereKey($id)->first() ?? throw new GameException('Diese Person gehört nicht zur Familie.');
    }

    private function started(FamilyCouncil $council): string
    {
        return $council->wasRecentlyCreated ? 'Der Familienrat beginnt!' : 'Der Familienrat läuft schon.';
    }

    private function ok(FamilyCouncil $council, ?string $message = null): ?string
    {
        return $message;
    }
}
