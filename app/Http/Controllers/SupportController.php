<?php

namespace App\Http\Controllers;

use App\Domain\Gameplay\GameException;
use App\Domain\Support\FocusService;
use App\Domain\Support\StepService;
use App\Domain\Support\StepSuggestions;
use App\Domain\Support\SupportService;
use App\Domain\Tasks\TaskService;
use App\Enums\TaskType;
use App\Http\Presenters\GamePresenter;
use App\Models\FocusSession;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Fokus & Alltagshilfen (Konzept §11): Einstellungen, Energiespar-Tag, Kopf-Ablage, Schritte, Fokus-Zeiten – Web und API. */
class SupportController extends Controller
{
    public function __construct(private readonly SupportService $support) {}

    // ---------- Einstellungen ----------

    public function settings(Request $request, ?User $member = null): JsonResponse
    {
        $target = $this->target($request, $member);

        return response()->json(SupportService::present($target) + ['can_change' => SupportService::editableBy($request->user(), $target)]);
    }

    public function updateSettings(Request $request, ?User $member = null): RedirectResponse|JsonResponse
    {
        $target = $this->target($request, $member);
        $data = $request->validate([
            'gentle_mode' => ['sometimes', 'nullable', 'boolean'],
            'focus_mode_default' => ['sometimes', 'boolean'],
            'feedback_level' => ['sometimes', Rule::in(['full', 'subtle', 'haptic'])],
            'reduce_motion' => ['sometimes', 'boolean'],
            'sound' => ['sometimes', 'boolean'],
            'haptics' => ['sometimes', 'boolean'],
            'timer_style' => ['sometimes', Rule::in(['disc', 'digits'])],
            'max_steps' => ['sometimes', 'integer', 'between:2,10'],
            'low_energy_per_week' => ['sometimes', 'integer', 'between:0,7'],
            'ai_steps' => ['sometimes', 'boolean'],
            'theme' => ['sometimes', 'nullable', Rule::in(array_column(SupportService::themesFor($target), 'key'))],
            'high_contrast' => ['sometimes', 'boolean'],
            'text_scale' => ['sometimes', 'numeric', 'between:0.8,1.6'],
            'dyslexia_font' => ['sometimes', 'boolean'],
            'color_vision' => ['sometimes', Rule::in(['normal', 'protan', 'deutan', 'tritan'])],
        ]);

        $this->support->update($request->user(), $target, $data);

        return $request->expectsJson()
            ? response()->json(SupportService::present($target->refresh()))
            : back()->with('success', 'Alltagshilfen gespeichert.');
    }

    /** Familienweite Standards: sanfter Modus für alle, KI-Schritte an/aus und Budget. */
    public function updateFamilySettings(Request $request): RedirectResponse|JsonResponse
    {
        $family = $request->user()->family;
        $this->authorize('manage', $family);
        $data = $request->validate([
            'gentle_mode_default' => ['required', 'boolean'],
            'ai_enabled' => ['required', 'boolean'],
            'ai_budget_usd' => ['nullable', 'numeric', 'between:0,20'],
        ]);

        $family->update(['settings' => array_merge($family->settings ?? [], array_filter($data, fn ($v) => $v !== null))]);

        return $request->expectsJson() ? response()->json(['ok' => true]) : back()->with('success', 'Gespeichert.');
    }

    // ---------- Energiespar-Tag ----------

    public function startLowEnergy(Request $request, ?User $member = null): RedirectResponse|JsonResponse
    {
        $target = $this->target($request, $member);
        $this->support->startLowEnergy($request->user(), $target);

        return $request->expectsJson()
            ? response()->json(SupportService::present($target), 201)
            : back()->with('success', 'Energiespar-Tag: Heute zählen nur die Kern-Aufgaben. Alles andere ist Bonus.');
    }

    public function cancelLowEnergy(Request $request, ?User $member = null): RedirectResponse|JsonResponse
    {
        $target = $this->target($request, $member);
        $this->support->cancelLowEnergy($request->user(), $target);

        return $request->expectsJson() ? response()->json(SupportService::present($target)) : back();
    }

    // ---------- Kopf-Ablage ----------

    public function inbox(Request $request, TaskService $tasks): RedirectResponse|JsonResponse
    {
        $title = $request->validate(['title' => ['required', 'string', 'max:120']])['title'];

        $task = $tasks->create($request->user(), ['type' => TaskType::Todo->value, 'title' => $title, 'difficulty' => 1, 'category' => null]);
        $task->forceFill(['status' => 'inbox'])->save();

        return $request->expectsJson()
            ? response()->json(GamePresenter::assignment($task->assignments()->with('task', 'completions')->first()), 201)
            : back()->with('success', 'In der Kopf-Ablage. Einsortieren kannst du später.');
    }

    public function sortInbox(Request $request, Task $task, TaskService $tasks): RedirectResponse|JsonResponse
    {
        abort_unless($task->owner_id === $request->user()->id && $task->status === 'inbox', 404);
        $data = $request->validate([
            'action' => ['nullable', Rule::in(['sort', 'delete'])],
            'type' => ['required_unless:action,delete', Rule::in(['todo', 'daily', 'habit'])],
            'category' => ['required_unless:action,delete', Rule::in(array_keys(config('game.categories')))],
            'difficulty' => ['nullable', Rule::in(array_values(config('game.difficulties')))],
            'due_date' => ['nullable', 'date'],
        ], [], ['category' => 'Kategorie']);

        if (($data['action'] ?? 'sort') === 'delete') {
            $tasks->archive($task);

            return $request->expectsJson() ? response()->json(['ok' => true]) : back();
        }

        $task->forceFill(array_filter([
            'status' => null,
            'type' => $data['type'],
            'category' => $data['category'],
            'difficulty' => $data['difficulty'] ?? $task->difficulty,
            'due_date' => $data['due_date'] ?? null,
            'repeat' => $data['type'] === 'daily' ? ['type' => 'daily', 'every' => 1] : null,
            'start_date' => $data['type'] === 'daily' ? now()->toDateString() : null,
            'up' => $data['type'] === 'habit' ? true : $task->up,
        ], fn ($v) => $v !== null) + ['status' => null])->save();

        return $request->expectsJson()
            ? response()->json(GamePresenter::task($task->refresh()))
            : back()->with('success', 'Einsortiert!');
    }

    // ---------- Schritte ----------

    public function scoreStep(Request $request, TaskAssignment $assignment, int $step, StepService $steps): RedirectResponse|JsonResponse
    {
        abort_unless($assignment->user_id === $request->user()->id, 403);
        $uuid = $request->validate(['client_uuid' => ['nullable', 'uuid']])['client_uuid'] ?? null;

        $result = $steps->score($assignment, $step, $request->expectsJson() ? 'app' : 'web', $uuid);

        return $request->expectsJson()
            ? response()->json($result + ['stats' => GamePresenter::stats($request->user()->stats()->first())])
            : back();
    }

    public function suggestSteps(Request $request, Task $task, StepSuggestions $suggestions): JsonResponse
    {
        $this->ensureOwnTask($request->user(), $task);
        $suggestion = $suggestions->suggest($request->user(), $task, $request->boolean('ai', true));

        return response()->json([
            'suggestion' => $suggestion,
            'ai_available' => $suggestions->aiAllowed($request->user()),
        ]);
    }

    public function saveSteps(Request $request, Task $task, StepSuggestions $suggestions): RedirectResponse|JsonResponse
    {
        $this->ensureOwnTask($request->user(), $task);
        $max = SupportService::settings($request->user())->max_steps;
        $data = $request->validate([
            'steps' => ['present', 'array', 'max:'.max($max, 2)],
            'steps.*' => ['string', 'max:120'],
            'source' => ['nullable', Rule::in(['family', 'template', 'ai'])],
        ]);

        $suggestions->save($request->user(), $task, $data['steps'], $data['source'] ?? 'family');

        return $request->expectsJson() ? response()->json(GamePresenter::task($task->refresh())) : back()->with('success', 'Schritte gespeichert.');
    }

    // ---------- Fokus-Zeiten ----------

    public function startFocus(Request $request, FocusService $focus): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'minutes' => ['required', 'integer', 'between:1,'.config('game.focus.max_minutes')],
            'task_id' => ['nullable', 'integer'],
        ]);
        $task = isset($data['task_id']) ? Task::find($data['task_id']) : null;

        $session = $focus->start($request->user(), $data['minutes'], $task);

        return $request->expectsJson() ? response()->json(FocusService::present($session), 201) : back();
    }

    public function focusAction(Request $request, FocusSession $session, string $action, FocusService $focus): RedirectResponse|JsonResponse
    {
        $user = $request->user();
        $session = match ($action) {
            'finish' => $focus->finish($user, $session, 'done'),
            'abort' => $focus->finish($user, $session, 'aborted'),
            'extend' => $focus->extend($user, $session, $request->validate(['minutes' => ['nullable', 'integer', 'between:1,30']])['minutes'] ?? 5),
            'invite' => $focus->invite($user, $session, User::findOrFail($request->validate(['member_id' => ['required', 'integer']])['member_id'])),
        };

        return $request->expectsJson() ? response()->json(FocusService::present($session->refresh())) : back();
    }

    private function target(Request $request, ?User $member): User
    {
        if ($member === null || $member->is($request->user())) {
            return $request->user();
        }
        if (! $request->user()->isParent() || ! $request->user()->sameFamilyAs($member)) {
            throw new GameException('Das dürfen nur Eltern der Familie.');
        }

        return $member;
    }

    private function ensureOwnTask(User $user, Task $task): void
    {
        if (! $task->assignments()->where('user_id', $user->id)->exists()) {
            throw new GameException('Das ist nicht deine Aufgabe.');
        }
    }
}
