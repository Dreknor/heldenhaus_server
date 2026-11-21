<?php

namespace App\Http\Controllers;

use App\Domain\Council\ProposalService;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Vorschläge der Kinder (§14.4): `GET|POST proposals`, `POST proposals/{id}/accept|decline` – Web und App. */
class ProposalController extends Controller
{
    public function __construct(private readonly ProposalService $proposals) {}

    public function index(Request $request): Response|JsonResponse
    {
        $user = $request->user();
        $data = [
            'proposals' => ProposalService::listFor($user),
            'templates' => ProposalService::templates($user),
            'freeText' => ProposalService::freeTextAllowed($user),
            'parents' => User::where('family_id', $user->family_id)->where('family_role', 'parent')->orderBy('name')->get(['id', 'name'])
                ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])->values(),
            'declineReasons' => ProposalService::DECLINE_REASONS,
            'isParent' => $user->isParent(),
            'canPropose' => ! $user->isParent() && $user->family_role?->value !== 'guest',
        ];

        return $request->expectsJson() ? response()->json($data) : Inertia::render('Proposals', $data);
    }

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(['reward', 'task'])],
            'target' => ['nullable', Rule::in(['pool', 'parent'])],
            'for_user_id' => ['nullable', 'integer'],
            'template_key' => ['nullable', 'string', 'max:60'],
            'title' => ['nullable', 'string', 'max:120'],
            'stars' => ['nullable', 'integer', 'between:1,1000'],
        ], [], ['title' => 'Titel', 'stars' => 'Wunschpreis']);

        $proposal = $this->proposals->propose($request->user(), $data);

        return $request->expectsJson()
            ? response()->json(ProposalService::present($proposal), 201)
            : back()->with('success', 'Dein Vorschlag ist bei deinen Eltern angekommen!');
    }

    public function accept(Request $request, Proposal $proposal): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:120'],
            'stars' => ['nullable', 'integer', 'between:0,10000'],
        ]);
        $proposal = $this->proposals->accept($request->user(), $proposal, $data);

        return $request->expectsJson()
            ? response()->json(ProposalService::present($proposal))
            : back()->with('success', $proposal->kind === 'reward' ? 'Angenommen – die Belohnung ist im Shop.' : 'Angenommen – die Aufgabe ist angelegt.');
    }

    public function decline(Request $request, Proposal $proposal): RedirectResponse|JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:300']], [], ['reason' => 'Begründung']);
        $proposal = $this->proposals->decline($request->user(), $proposal, $data['reason']);

        return $request->expectsJson()
            ? response()->json(ProposalService::present($proposal))
            : back()->with('success', 'Antwort ist unterwegs.');
    }
}
