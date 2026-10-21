<?php

namespace App\Http\Controllers;

use App\Domain\Family\FamilyService;
use App\Domain\Integrations\HomeAssistant\HomeAssistantWebhooks;
use App\Domain\Rewards\RewardService;
use App\Domain\Social\FeedService;
use App\Domain\Support\OpenRouterClient;
use App\Domain\Support\SupportService;
use App\Enums\FamilyRole;
use App\Http\Presenters\CharacterSheet;
use App\Http\Presenters\GamePresenter;
use App\Models\FamilyInvite;
use App\Models\HaAuditEntry;
use App\Models\HaWebhook;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
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
                    'gentle_mode_default' => (bool) $family->setting('gentle_mode_default', false),
                    'ai_enabled' => (bool) $family->setting('ai_enabled', false),
                    'ai_budget_usd' => (float) $family->setting('ai_budget_usd', config('game.ai.monthly_budget_usd')),
                ] : null,
            ],
            'members' => $family->members()->with('stats')->orderBy('family_role')->orderBy('name')->get()
                ->map(fn (User $m) => GamePresenter::member($m) + ($isParent ? ['supportSettings' => SupportService::present($m)] : [])),
            'invites' => $isParent
                ? $family->invites()->whereNull('used_at')->where('expires_at', '>', now())->latest()->get()
                    ->map(fn (FamilyInvite $i) => [
                        'id' => $i->id, 'code' => $i->code, 'role' => $i->role->value,
                        'role_label' => $i->role->label(), 'email' => $i->email, 'expires_at' => $i->expires_at->toDateString(),
                    ])
                : [],
            'isParent' => $isParent,
            'openrouterConfigured' => $isParent && OpenRouterClient::configured(),
            'familyQuest' => CharacterSheet::quests($user)['family'],
            'feed' => FeedService::present($family->id, $user->id),
            'haToken' => $request->session()->get('haToken'),
            'haConnected' => $isParent && $user->tokens()->where('name', 'home-assistant')->exists(),
            'haWebhook' => $isParent ? $this->webhookView($family->id) : null,
            'haWebhookSecret' => $request->session()->get('haWebhookSecret'),
            'haEvents' => HomeAssistantWebhooks::EVENTS,
            'haAudit' => $isParent ? HaAuditEntry::with('member:id,name')->where('family_id', $family->id)->latest('id')->limit(15)->get()
                ->map(fn (HaAuditEntry $e) => [
                    'id' => $e->id, 'direction' => $e->direction, 'action' => $e->action, 'status' => $e->status,
                    'member' => $e->member?->name, 'details' => $e->details, 'created_at' => $e->created_at->toIso8601String(),
                ]) : [],
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
            // Freitext im Familienbündnis für Teens (Kinder unter 13 nie, Konzept §3.6)
            'chat_allowed' => ['nullable', 'boolean'],
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

        $abilities = $request->boolean('approve') ? ['ha', 'ha:approve'] : ['ha'];
        $request->user()->tokens()->where('name', 'home-assistant')->delete();
        $token = $request->user()->createToken('home-assistant', $abilities)->plainTextToken;

        return back()->with('haToken', $token);
    }

    /** Rückweg zu Home Assistant: Webhook-URL (z. B. Nabu Casa) und Auswahl der Ereignisse. Das Secret wird nur einmal angezeigt. */
    public function saveHaWebhook(Request $request): RedirectResponse
    {
        $family = $request->user()->family;
        $this->authorize('manage', $family);

        $data = $request->validate([
            'url' => ['required', 'url:https,http', 'max:500'],
            'events' => ['nullable', 'array'],
            'events.*' => [Rule::in(array_keys(HomeAssistantWebhooks::EVENTS))],
            'active' => ['boolean'],
            'regenerate_secret' => ['boolean'],
        ], [], ['url' => 'Webhook-URL']);

        $webhook = HaWebhook::firstOrNew(['family_id' => $family->id]);
        $newSecret = ! $webhook->exists || $request->boolean('regenerate_secret') ? Str::random(40) : null;

        $webhook->fill([
            'url' => $data['url'],
            'events' => isset($data['events']) && count($data['events']) < count(HomeAssistantWebhooks::EVENTS) ? array_values($data['events']) : null,
            'active' => $data['active'] ?? true,
        ]);
        if ($newSecret) {
            $webhook->secret = $newSecret;
            $webhook->failures = 0;
        }
        $webhook->save();

        return back()->with('success', 'Webhook gespeichert.')->with('haWebhookSecret', $newSecret);
    }

    public function testHaWebhook(Request $request, HomeAssistantWebhooks $webhooks): RedirectResponse
    {
        $this->authorize('manage', $request->user()->family);

        $sent = $webhooks->send($request->user()->family_id, 'ping', HomeAssistantWebhooks::member($request->user()) + ['message' => 'Hallo aus dem Heldenhaus!']);

        return $sent
            ? back()->with('success', 'Test-Ereignis „heldenhaus_ping“ ist unterwegs.')
            : back()->withErrors(['game' => 'Es ist noch kein Webhook eingerichtet.']);
    }

    public function destroyHaWebhook(Request $request): RedirectResponse
    {
        $this->authorize('manage', $request->user()->family);
        HaWebhook::where('family_id', $request->user()->family_id)->delete();

        return back()->with('success', 'Webhook entfernt.');
    }

    /** Eltern loben mit Sternen. */
    public function grantStars(Request $request, User $member, RewardService $rewards): RedirectResponse
    {
        $this->authorize('manage', $request->user()->family);
        abort_unless($request->user()->sameFamilyAs($member), 403);

        $data = $request->validate([
            'stars' => ['required', 'integer', 'between:1,20'],
            'reason' => ['nullable', 'string', 'max:120'],
        ], [], ['stars' => 'Sterne', 'reason' => 'Grund']);

        $rewards->grantStars($request->user(), $member, $data['stars'], $data['reason'] ?? null);

        return back()->with('success', "{$member->name} hat {$data['stars']} ".($data['stars'] === 1 ? 'Stern' : 'Sterne').' bekommen.');
    }

    private function webhookView(int $familyId): ?array
    {
        $webhook = HaWebhook::where('family_id', $familyId)->first();

        return $webhook ? [
            'url' => $webhook->url,
            'events' => $webhook->events,
            'active' => $webhook->active,
            'last_sent_at' => $webhook->last_sent_at?->toIso8601String(),
            'last_status' => $webhook->last_status,
            'last_error' => $webhook->last_error,
            'failures' => $webhook->failures,
        ] : null;
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
