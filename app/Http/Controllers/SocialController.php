<?php

namespace App\Http\Controllers;

use App\Domain\Social\AllianceService;
use App\Domain\Social\ChallengeService;
use App\Domain\Social\MessageService;
use App\Models\Challenge;
use App\Models\FamilyAlliance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Bündnisse, Challenges und Nachrichten (Konzept §3.6) – Web und API. */
class SocialController extends Controller
{
    public function __construct(
        private readonly AllianceService $alliances,
        private readonly ChallengeService $challenges,
        private readonly MessageService $messages,
    ) {}

    public function index(Request $request): Response|JsonResponse
    {
        $user = $request->user();
        $alliances = AllianceService::present($user->family_id, $user->isParent());
        $channel = $request->integer('alliance') ?: null;
        $alliance = MessageService::resolveAlliance($user, $channel);

        $data = [
            'alliances' => $alliances,
            'challenges' => $this->challenges->visible($user)->map(fn (Challenge $c) => $this->challenges->present($c, $user))->values(),
            'channel' => $alliance?->id,
            'messages' => $this->messages->list($user, $alliance),
            'canWriteText' => MessageService::canUseText($user, $alliance),
            'presets' => MessageService::PRESETS,
            'emojis' => MessageService::EMOJIS,
            'isParent' => $user->isParent(),
            'categories' => config('game.categories'),
        ];

        return $request->expectsJson() ? response()->json($data) : Inertia::render('family/Social', $data);
    }

    // ---------- Bündnisse ----------

    public function invite(Request $request): RedirectResponse|JsonResponse
    {
        $alliance = $this->alliances->invite($request->user());

        return $request->expectsJson()
            ? response()->json(['code' => $alliance->code, 'expires_at' => $alliance->expires_at->toDateString()], 201)
            : back()->with('success', "Bündnis-Code: {$alliance->code} – gib ihn an die andere Familie weiter.");
    }

    public function join(Request $request): RedirectResponse|JsonResponse
    {
        $code = $request->validate(['code' => ['required', 'string', 'max:12']])['code'];
        $alliance = $this->alliances->join($request->user(), $code);

        return $request->expectsJson()
            ? response()->json(['id' => $alliance->id, 'status' => $alliance->status])
            : back()->with('success', "Ihr seid jetzt mit „{$alliance->family->name}“ verbündet!");
    }

    public function endAlliance(Request $request, FamilyAlliance $alliance): RedirectResponse|JsonResponse
    {
        $this->alliances->end($request->user(), $alliance);

        return $request->expectsJson() ? response()->json(['ok' => true]) : back()->with('success', 'Bündnis beendet.');
    }

    // ---------- Challenges ----------

    public function storeChallenge(Request $request): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:500'],
            'icon' => ['nullable', 'string', 'max:16'],
            'tasks' => ['required', 'array', 'min:1', 'max:5'],
            'tasks.*.title' => ['required', 'string', 'max:120'],
            'tasks.*.type' => ['required', Rule::in(['daily', 'habit', 'todo'])],
            'tasks.*.category' => ['nullable', Rule::in(array_keys(config('game.categories')))],
            'target' => ['required', 'integer', 'between:1,365'],
            'reward_gold' => ['nullable', 'integer', 'between:0,500'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on', 'before_or_equal:'.now()->addYear()->toDateString()],
            'scope' => ['required', Rule::in(['family', 'alliance'])],
            'leaderboard' => ['boolean'],
        ], [], ['title' => 'Titel', 'tasks' => 'Aufgaben', 'target' => 'Ziel', 'ends_on' => 'Ende']);

        $challenge = $this->challenges->create($request->user(), [
            ...$data,
            'icon' => $data['icon'] ?? '🏁',
            'reward_gold' => $data['reward_gold'] ?? 0,
            'leaderboard' => $data['leaderboard'] ?? false,
            'tasks' => array_map(fn ($t) => ['title' => $t['title'], 'type' => $t['type'], 'category' => $t['category'] ?? null], $data['tasks']),
        ]);

        return $request->expectsJson()
            ? response()->json($this->challenges->present($challenge, $request->user()), 201)
            : back()->with('success', "Challenge „{$challenge->title}“ ist angelegt.");
    }

    public function joinChallenge(Request $request, Challenge $challenge): RedirectResponse|JsonResponse
    {
        $this->challenges->join($request->user(), $challenge);

        return $request->expectsJson()
            ? response()->json($this->challenges->present($challenge, $request->user()))
            : back()->with('success', 'Du machst mit! Die Aufgaben stehen jetzt bei dir.');
    }

    public function leaveChallenge(Request $request, Challenge $challenge): RedirectResponse|JsonResponse
    {
        abort_unless($challenge->visibleTo($request->user()->family_id), 404);
        $this->challenges->leave($request->user(), $challenge);

        return $request->expectsJson() ? response()->json(['ok' => true]) : back()->with('success', 'Du hast die Challenge verlassen.');
    }

    public function destroyChallenge(Request $request, Challenge $challenge): RedirectResponse|JsonResponse
    {
        $this->challenges->destroy($request->user(), $challenge);

        return $request->expectsJson() ? response()->json(['ok' => true]) : back()->with('success', 'Challenge beendet.');
    }

    // ---------- Nachrichten ----------

    public function messages(Request $request): JsonResponse
    {
        $user = $request->user();
        $alliance = MessageService::resolveAlliance($user, $request->integer('alliance') ?: null);

        return response()->json([
            'messages' => $this->messages->list($user, $alliance, $request->integer('after') ?: null),
            'can_write_text' => MessageService::canUseText($user, $alliance),
        ]);
    }

    public function sendMessage(Request $request): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'alliance' => ['nullable', 'integer'],
            'kind' => ['required', Rule::in(['text', 'preset', 'emoji'])],
            'body' => ['required', 'string', 'max:500'],
        ]);

        $user = $request->user();
        $message = $this->messages->send($user, MessageService::resolveAlliance($user, $data['alliance'] ?? null), $data['kind'], $data['body']);

        return $request->expectsJson() ? response()->json(['id' => $message->id], 201) : back();
    }
}
