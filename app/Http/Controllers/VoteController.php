<?php

namespace App\Http\Controllers;

use App\Domain\Council\CouncilService;
use App\Domain\Council\VoteService;
use App\Domain\Gameplay\GameException;
use App\Models\User;
use App\Models\Vote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Abstimmung über die nächste Familien-Quest (§14.4): `POST votes`, `GET|POST votes/{id}`. */
class VoteController extends Controller
{
    public function __construct(private readonly VoteService $votes) {}

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $vote = $this->votes->open($request->user(), CouncilService::running($request->user()->family_id)?->id);

        return $request->expectsJson()
            ? response()->json(VoteService::present($vote, $request->user()), 201)
            : back()->with('success', 'Die Abstimmung läuft!');
    }

    public function show(Request $request, Vote $vote): JsonResponse
    {
        abort_unless($vote->family_id === $request->user()->family_id, 404);

        return response()->json(VoteService::present($vote, $request->user()));
    }

    /** Stimme abgeben (`option`, am Familiengerät optional `member_id`), Gleichstand entscheiden (`decide`) oder beenden (`close`). */
    public function act(Request $request, Vote $vote): RedirectResponse|JsonResponse
    {
        $user = $request->user();
        abort_unless($vote->family_id === $user->family_id, 404);
        $data = $request->validate([
            'option' => ['nullable', 'string', 'max:60'],
            'member_id' => ['nullable', 'integer'],
            'decide' => ['nullable', 'string', 'max:60'],
            'close' => ['nullable', 'boolean'],
        ]);

        if (! empty($data['decide'])) {
            $vote = $this->votes->decideTie($user, $vote, $data['decide']);
            $message = 'Entschieden!';
        } elseif (! empty($data['close'])) {
            $vote = $this->votes->close($vote, $user);
            $message = $vote->status === 'tie' ? 'Gleichstand – ihr entscheidet.' : 'Abstimmung beendet.';
        } elseif (! empty($data['option'])) {
            $voter = isset($data['member_id'])
                ? (User::where('family_id', $user->family_id)->whereKey($data['member_id'])->first() ?? throw new GameException('Diese Person gehört nicht zur Familie.'))
                : $user;
            $vote = $this->votes->cast($user, $vote, $data['option'], $voter);
            $message = 'Stimme gezählt!';
        } else {
            throw new GameException('Wähle eine Quest aus.');
        }

        return $request->expectsJson()
            ? response()->json(VoteService::present($vote->refresh(), $user))
            : back()->with('success', $message);
    }
}
