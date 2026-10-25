<?php

namespace App\Http\Controllers;

use App\Domain\Classes\ClassCatalog;
use App\Domain\Events\EventService;
use App\Domain\Events\FunEventService;
use App\Domain\Support\InnService;
use App\Enums\CompletionStatus;
use App\Enums\TaskType;
use App\Models\EventParticipation;
use App\Models\FamilyEvent;
use App\Models\FunEventInstance;
use App\Models\TaskAssignment;
use App\Models\TaskCompletion;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/** Events, Zufallsereignisse und Gasthaus (Konzept §12.6, §12.5, §1). */
class EventController extends Controller
{
    public function __construct(private readonly EventService $events) {}

    public function webIndex(Request $request): InertiaResponse
    {
        return Inertia::render('events/Index', $this->events->index($request->user()) + ['canManage' => $request->user()->isParent()]);
    }

    public function webHistory(Request $request): InertiaResponse
    {
        return Inertia::render('events/History', ['events' => $this->events->history($request->user())]);
    }

    public function webShow(Request $request, FamilyEvent $event): InertiaResponse
    {
        $user = $request->user();
        abort_unless(EventService::visibleTo($event, $user), 404);

        $data = $this->events->present($event, $user, detail: true);
        $features = $data['features'];
        $data['webActions'] = [];

        if (in_array('secret_help', $features, true)) {
            $data['webActions']['familyMembers'] = User::where('family_id', $user->family_id)
                ->where('id', '!=', $user->id)->orderBy('name')->get(['id', 'name']);
            $data['webActions']['completions'] = TaskCompletion::with('task:id,title')
                ->where('user_id', $user->id)->where('direction', 'up')->where('created_at', '>=', $event->starts_at)
                ->whereNotIn('status', [CompletionStatus::Undone->value, CompletionStatus::Rejected->value])
                ->latest('id')->limit(50)->get(['id', 'task_id', 'created_at']);
        }

        if (in_array('joker', $features, true)) {
            $data['webActions']['dailies'] = TaskAssignment::with('task:id,title')
                ->where('user_id', $user->id)->where('completed', false)
                ->whereHas('task', fn ($query) => $query->where('type', TaskType::Daily->value))
                ->get(['id', 'task_id']);
        }

        if (in_array('assign_parent', $features, true)) {
            $assignedParents = EventParticipation::where('family_event_id', $event->id)
                ->where('user_id', $user->id)->first()?->collected['assigned'] ?? [];
            $data['webActions']['parents'] = User::where('family_id', $user->family_id)
                ->where('family_role', 'parent')->whereNotIn('id', $assignedParents)->orderBy('name')->get(['id', 'name']);
        }

        if (in_array('class_trial', $features, true)) {
            $data['webActions']['classes'] = collect(ClassCatalog::all())->map(fn (array $class, string $key) => [
                'key' => $key,
                'name' => $class['name'],
                'icon' => $class['icon'] ?? '✨',
            ])->values();
        }

        return Inertia::render('events/Show', $data);
    }

    public function webAdvent(Request $request, FamilyEvent $event, int $day): RedirectResponse
    {
        $result = $this->events->openAdvent($request->user(), $event, $day);
        $item = $result['item'];
        $reward = ($item['icon'] ?? '🎁').' '.($item['name'] ?? 'Geschenk');

        return back()->with('success', "Türchen {$day}: {$reward}".($result['gold'] ? " und {$result['gold']} Gold" : '').'!');
    }

    public function webMerchantBuy(Request $request, FamilyEvent $event): RedirectResponse
    {
        $data = $request->validate(['item' => ['required', 'string', 'max:64']]);
        $result = $this->events->merchantBuy($request->user(), $event, $data['item']);

        return back()->with('success', "{$result['item']['icon']} {$result['item']['name']} gekauft.");
    }

    public function webSecretHelp(Request $request, FamilyEvent $event): RedirectResponse
    {
        $data = $request->validate(['completion_id' => ['required', 'integer'], 'for_user_id' => ['required', 'integer']]);
        $result = $this->events->secretHelp($request->user(), $event, $data['completion_id'], $data['for_user_id']);

        return back()->with('success', $result['message']);
    }

    public function webJoker(Request $request, FamilyEvent $event): RedirectResponse
    {
        $data = $request->validate(['assignment_id' => ['required', 'integer']]);
        $result = $this->events->joker($request->user(), $event, $data['assignment_id']);

        return back()->with('success', $result['message']);
    }

    public function webAssignParent(Request $request, FamilyEvent $event): RedirectResponse
    {
        $data = $request->validate(['parent_id' => ['required', 'integer'], 'title' => ['required', 'string', 'max:120']]);
        $task = $this->events->assignToParent($request->user(), $event, $data['parent_id'], $data['title']);

        return back()->with('success', "Aufgabe „{$task->title}“ wurde zugewiesen.");
    }

    public function webClassTrial(Request $request, FamilyEvent $event): RedirectResponse
    {
        $data = $request->validate(['class' => ['required', 'string', 'max:32']]);
        $result = $this->events->classTrial($request->user(), $event, $data['class']);

        return back()->with('success', "Klassen-Probetag als {$result['class']} gestartet.");
    }

    public function webSettings(Request $request): InertiaResponse
    {
        abort_unless($request->user()->isParent(), 403);

        return Inertia::render('events/Settings', $this->events->settings($request->user()));
    }

    public function webUpdateSettings(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isParent(), 403);
        $this->events->updateSettings($request->user(), $request->validate($this->settingsRules()));

        return back()->with('success', 'Einstellungen für Ereignisse gespeichert.');
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->events->index($request->user()));
    }

    public function history(Request $request): JsonResponse
    {
        return response()->json(['events' => $this->events->history($request->user())]);
    }

    public function show(Request $request, FamilyEvent $event): JsonResponse
    {
        abort_unless(EventService::visibleTo($event, $request->user()), 404);

        return response()->json($this->events->present($event, $request->user(), detail: true));
    }

    public function advent(Request $request, FamilyEvent $event, int $day): JsonResponse
    {
        return response()->json($this->events->openAdvent($request->user(), $event, $day));
    }

    public function secretHelp(Request $request, FamilyEvent $event): JsonResponse
    {
        $data = $request->validate(['completion_id' => ['required', 'integer'], 'for_user_id' => ['required', 'integer']]);

        return response()->json($this->events->secretHelp($request->user(), $event, $data['completion_id'], $data['for_user_id']));
    }

    public function merchantBuy(Request $request, FamilyEvent $event): JsonResponse
    {
        $data = $request->validate(['item' => ['required', 'string', 'max:64']]);

        return response()->json($this->events->merchantBuy($request->user(), $event, $data['item']));
    }

    public function joker(Request $request, FamilyEvent $event): JsonResponse
    {
        $data = $request->validate(['assignment_id' => ['required', 'integer']]);

        return response()->json($this->events->joker($request->user(), $event, $data['assignment_id']));
    }

    public function assignParent(Request $request, FamilyEvent $event): JsonResponse
    {
        $data = $request->validate(['parent_id' => ['required', 'integer'], 'title' => ['required', 'string', 'max:120']]);
        $task = $this->events->assignToParent($request->user(), $event, $data['parent_id'], $data['title']);

        return response()->json(['task_id' => $task->id, 'title' => $task->title], 201);
    }

    public function classTrial(Request $request, FamilyEvent $event): JsonResponse
    {
        $data = $request->validate(['class' => ['required', 'string', 'max:32']]);

        return response()->json($this->events->classTrial($request->user(), $event, $data['class']));
    }

    public function start(Request $request): JsonResponse
    {
        $data = $request->validate(['key' => ['required', 'string', 'max:32'], 'subject_user_id' => ['nullable', 'integer']]);
        $event = $this->events->startManually($request->user(), $data['key'], $data['subject_user_id'] ?? null);

        return response()->json($this->events->present($event, $request->user()), 201);
    }

    public function settings(Request $request): JsonResponse
    {
        abort_unless($request->user()->isParent(), 403);

        return response()->json($this->events->settings($request->user()));
    }

    public function updateSettings(Request $request): JsonResponse
    {
        abort_unless($request->user()->isParent(), 403);
        $data = $request->validate($this->settingsRules());

        return response()->json($this->events->updateSettings($request->user(), $data));
    }

    private function settingsRules(): array
    {
        return [
            'country' => ['sometimes', 'string', 'size:2', 'alpha'],
            'subdivision' => ['sometimes', 'nullable', 'string', 'max:8', 'regex:/^[A-Z]{2}-[A-Z0-9]{1,3}$/'],
            'fun_events' => ['sometimes', 'boolean'],
            'weather_source' => ['sometimes', 'in:none,ha,open_meteo'],
            'weather_lat' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'weather_lon' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            'events' => ['sometimes', 'array'],
            'events.*' => ['boolean'],
            'fun' => ['sometimes', 'array'],
            'fun.*' => ['boolean'],
            'school_holidays' => ['sometimes', 'nullable', 'array', 'max:20'],
            'school_holidays.*.name' => ['required', 'string', 'max:60'],
            'school_holidays.*.starts_on' => ['required', 'date'],
            'school_holidays.*.ends_on' => ['required', 'date', 'after_or_equal:school_holidays.*.starts_on'],
        ];
    }

    public function updateDates(Request $request, User $member): JsonResponse
    {
        $data = $request->validate([
            'birthday' => ['present', 'nullable', 'array'],
            'birthday.month' => ['required_with:birthday', 'integer', 'between:1,12'],
            'birthday.day' => ['required_with:birthday', 'integer', 'between:1,31'],
        ]);
        $date = $this->events->updateDates($request->user(), $member, $data['birthday']);

        return response()->json(['birthday' => $date?->only(['month', 'day'])]);
    }

    // ---------- Zufallsereignisse (§12.5) ----------

    public function funEvents(Request $request, FunEventService $fun): JsonResponse
    {
        return response()->json(['fun_events' => $fun->visible($request->user()), 'greeting' => FunEventService::wishGreeting($request->user())]);
    }

    public function rollFunEvent(Request $request, FunEventService $fun): JsonResponse
    {
        $data = $request->validate(['context' => ['required', 'in:'.implode(',', FunEventService::CONTEXTS)]]);

        return response()->json(['fun_event' => $fun->forContext($request->user(), $data['context'])]);
    }

    public function interact(Request $request, FunEventInstance $instance, FunEventService $fun): JsonResponse
    {
        $data = $request->validate(['wish' => ['nullable', 'integer', 'min:0'], 'room' => ['nullable', 'string', 'max:32']]);

        return response()->json($fun->interact($request->user(), $instance, $data));
    }

    // ---------- Gasthaus ----------

    public function enterInn(Request $request, InnService $inn, ?User $member = null): JsonResponse
    {
        $stats = $inn->enter($request->user(), $member ?? $request->user());

        return response()->json(['sleeping' => $stats->sleeping, 'since' => $stats->sleeping_since?->toIso8601String()]);
    }

    public function leaveInn(Request $request, InnService $inn, ?User $member = null): JsonResponse
    {
        $stats = $inn->leave($request->user(), $member ?? $request->user());

        return response()->json(['sleeping' => $stats->sleeping]);
    }
}
