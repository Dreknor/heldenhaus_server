<?php

namespace App\Http\Controllers;

use App\Domain\Family\FamilyService;
use App\Domain\Social\FeedService;
use App\Enums\FamilyRole;
use App\Http\Presenters\CharacterSheet;
use App\Http\Presenters\GamePresenter;
use App\Models\FamilyInvite;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class FamilyController extends Controller
{
    public function __construct(private readonly FamilyService $families) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $family = $user->family;
        $this->authorize('view', $family);
        $isParent = $user->isParent();

        return Inertia::render('family/Index', [
            'family' => [
                'id' => $family->id,
                'name' => $family->name,
                'login_code' => $isParent ? $family->login_code : null,
                'settings' => $isParent ? [
                    'auto_approve_hours' => (int) $family->setting('auto_approve_hours', config('game.auto_approve_hours')),
                    'ko_gold_loss' => (float) $family->setting('ko_gold_loss', config('game.ko_gold_loss')),
                    'damage_factor_child' => (float) $family->setting('damage_factor.child', config('game.age_damage_factor.child')),
                    'damage_factor_teen' => (float) $family->setting('damage_factor.teen', config('game.age_damage_factor.teen')),
                ] : null,
            ],
            'members' => $family->members()->with('stats')->orderBy('family_role')->orderBy('name')->get()
                ->map(fn (User $m) => GamePresenter::member($m)),
            'invites' => $isParent
                ? $family->invites()->whereNull('used_at')->where('expires_at', '>', now())->latest()->get()
                    ->map(fn (FamilyInvite $i) => [
                        'id' => $i->id, 'code' => $i->code, 'role' => $i->role->value,
                        'role_label' => $i->role->label(), 'email' => $i->email, 'expires_at' => $i->expires_at->toDateString(),
                    ])
                : [],
            'isParent' => $isParent,
            'familyQuest' => CharacterSheet::quests($user)['family'],
            'feed' => FeedService::present($family->id, $user->id),
            'haToken' => $request->session()->get('haToken'),
            'haConnected' => $isParent && $user->tokens()->where('name', 'home-assistant')->exists(),
            'apiUrl' => url('/api/v1'),
        ]);
    }

    public function tasks(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->isParent(), 403);

        return Inertia::render('family/Tasks', [
            'tasks' => Task::with('assignments')->where('family_id', $user->family_id)->active()->latest()->get()
                ->map(fn (Task $t) => GamePresenter::task($t)),
            'members' => $user->family->members()->orderBy('name')->get()
                ->map(fn (User $m) => GamePresenter::member($m, false)),
            'categories' => config('game.categories'),
            'difficulties' => config('game.difficulties'),
        ]);
    }

    public function storeChild(Request $request): RedirectResponse
    {
        $this->authorize('manage', $request->user()->family);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:40'],
            'birth_year' => ['required', 'integer', 'between:'.(now()->year - 18).','.(now()->year - 4)],
            'pin' => ['required', 'digits_between:4,6'],
        ], [], ['name' => 'Name', 'birth_year' => 'Geburtsjahr', 'pin' => 'PIN']);

        $this->families->addChild($request->user(), $data['name'], (int) $data['birth_year'], $data['pin']);

        return back()->with('success', "{$data['name']} ist jetzt dabei!");
    }

    public function updateMember(Request $request, User $member): RedirectResponse
    {
        $this->authorize('manage', $request->user()->family);
        abort_unless($request->user()->sameFamilyAs($member), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:40'],
            'birth_year' => ['nullable', 'integer', 'between:1900,'.now()->year],
            'pin' => ['nullable', 'digits_between:4,6'],
            'avatar_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);

        $member->update(array_filter($data, fn ($v) => $v !== null));

        return back()->with('success', 'Gespeichert.');
    }

    public function storeInvite(Request $request): RedirectResponse
    {
        $this->authorize('manage', $request->user()->family);

        $data = $request->validate([
            'role' => ['required', Rule::enum(FamilyRole::class)],
            'email' => ['nullable', 'email'],
        ]);

        $invite = $this->families->createInvite($request->user(), FamilyRole::from($data['role']), $data['email'] ?? null);

        return back()->with('success', "Einladungscode: {$invite->code}");
    }

    public function destroyInvite(Request $request, FamilyInvite $invite): RedirectResponse
    {
        $this->authorize('manage', $invite->family);
        $invite->delete();

        return back();
    }

    /** Token für die Home-Assistant-Integration (Fähigkeit „ha“). Wird nur einmal angezeigt. */
    public function createHaToken(Request $request): RedirectResponse
    {
        $this->authorize('manage', $request->user()->family);

        $request->user()->tokens()->where('name', 'home-assistant')->delete();
        $token = $request->user()->createToken('home-assistant', ['ha'])->plainTextToken;

        return back()->with('haToken', $token);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $family = $request->user()->family;
        $this->authorize('manage', $family);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'auto_approve_hours' => ['required', 'integer', 'between:0,168'],
            'ko_gold_loss' => ['required', 'numeric', 'between:0,1'],
            'damage_factor_child' => ['required', 'numeric', 'between:0,1'],
            'damage_factor_teen' => ['required', 'numeric', 'between:0,1'],
        ]);

        $family->update([
            'name' => $data['name'],
            'settings' => array_merge($family->settings ?? [], [
                'auto_approve_hours' => (int) $data['auto_approve_hours'],
                'ko_gold_loss' => (float) $data['ko_gold_loss'],
                'damage_factor' => ['child' => (float) $data['damage_factor_child'], 'teen' => (float) $data['damage_factor_teen']],
            ]),
        ]);

        return back()->with('success', 'Familien-Einstellungen gespeichert.');
    }
}
