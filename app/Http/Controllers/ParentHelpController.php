<?php

namespace App\Http\Controllers;

use App\Domain\Council\ParentHelp;
use App\Domain\Council\TaskTemplates;
use App\Domain\Gameplay\GameException;
use App\Domain\Tasks\TaskService;
use App\Enums\FamilyRole;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Hilfen für Eltern (§14.9): Aufgaben-Vorlagen, Startassistent, Preis-Hilfe, Eltern-Anleitung. */
class ParentHelpController extends Controller
{
    /** `GET task-templates`: nach Altersstufe (`age` oder `member`) und Bereich gefiltert. */
    public function templates(Request $request): JsonResponse
    {
        $data = $request->validate([
            'age' => ['nullable', Rule::in(TaskTemplates::AGES)],
            'area' => ['nullable', Rule::in(array_keys(TaskTemplates::AREAS))],
            'member' => ['nullable', 'integer'],
        ]);
        $age = $data['age'] ?? null;
        if (! $age && isset($data['member'])) {
            $member = User::where('family_id', $request->user()->family_id)->whereKey($data['member'])->firstOrFail();
            $age = TaskTemplates::ageBand($member);
        }

        return response()->json([
            'templates' => array_map(fn ($t) => TaskTemplates::present($t), TaskTemplates::filter($age, $data['area'] ?? null)),
            'areas' => TaskTemplates::AREAS,
            'ages' => TaskTemplates::AGES,
            'age' => $age,
        ]);
    }

    /** Eine Vorlage als Familienaufgabe übernehmen (Web-Auswahl auf der Seite Familienaufgaben). */
    public function adopt(Request $request, string $key, TaskService $tasks): RedirectResponse|JsonResponse
    {
        $parent = $request->user();
        abort_unless($parent->isParent(), 403);
        $template = TaskTemplates::get($key) ?? abort(404);
        $data = $request->validate([
            'assignment_mode' => ['required', Rule::in(['assigned', 'pool'])],
            'assignees' => ['nullable', 'array'],
            'assignees.*' => ['integer'],
            'star_reward' => ['nullable', 'integer', 'between:0,1000'],
        ]);
        if ($data['assignment_mode'] === 'assigned' && empty($data['assignees'])) {
            throw new GameException('Wähle, wer die Aufgabe bekommt.');
        }
        $stars = $data['star_reward'] ?? (int) $template['stars'];
        $base = TaskTemplates::taskData($template);
        if ($data['assignment_mode'] === 'pool' && $base['type'] !== 'todo') {
            $base = ['type' => 'todo', 'title' => $base['title'], 'category' => $base['category'], 'difficulty' => $base['difficulty'], 'checklist' => $template['steps'] ?: null];
        }

        $task = $tasks->create($parent, array_filter($base + [
            'scope' => 'family',
            'assignment_mode' => $data['assignment_mode'],
            'approval_mode' => $stars > 0 ? 'required' : 'none',
            'star_reward' => $stars,
            'assignees' => $data['assignees'] ?? [],
        ], fn ($v) => $v !== null));

        $hints = ParentHelp::loadHints($parent->family_id, $data['assignees'] ?? []);
        $message = 'Vorlage übernommen: '.$task->title.'.'.($hints ? ' '.implode(' ', array_column($hints, 'text')) : '');

        return $request->expectsJson()
            ? response()->json(['task_id' => $task->id, 'hints' => $hints], 201)
            : back()->with('success', $message);
    }

    /** Startassistent nach dem Anlegen eines Kindes. */
    public function wizard(Request $request, User $member): Response
    {
        $this->ensureChild($request->user(), $member);
        $age = TaskTemplates::ageBand($member);

        return Inertia::render('family/StartWizard', [
            'member' => ['id' => $member->id, 'name' => $member->name, 'age_band' => $age, 'done' => $member->starter_tasks_at !== null],
            'templates' => array_map(fn ($t) => TaskTemplates::present($t), TaskTemplates::filter($age)),
            'areas' => TaskTemplates::AREAS,
            'min' => ParentHelp::STARTER_MIN,
            'max' => ParentHelp::STARTER_MAX,
        ]);
    }

    public function storeStarter(Request $request, User $member, ParentHelp $help): RedirectResponse|JsonResponse
    {
        $this->ensureChild($request->user(), $member);
        $data = $request->validate([
            'templates' => ['required', 'array', 'min:'.ParentHelp::STARTER_MIN, 'max:'.ParentHelp::STARTER_MAX],
            'templates.*' => ['string', 'max:60'],
        ], [
            'templates.min' => 'Wählt mindestens 3 Aufgaben.',
            'templates.max' => 'Höchstens 5 Aufgaben – weniger ist am Anfang mehr.',
        ]);
        $tasks = $help->starterTasks($request->user(), $member, $data['templates']);

        return $request->expectsJson()
            ? response()->json(['created' => count($tasks)], 201)
            : to_route('family.settings')->with('success', count($tasks)." Aufgaben für {$member->name} angelegt. Ab jetzt höchstens eine neue pro Woche.");
    }

    /** `GET shop/price-help?stars=`: wie lange jedes Kind für diesen Preis arbeitet. */
    public function priceHelp(Request $request): JsonResponse
    {
        abort_unless($request->user()->isParent(), 403);
        $stars = (int) $request->validate(['stars' => ['required', 'integer', 'between:1,10000']])['stars'];

        return response()->json([
            'stars' => $stars,
            'children' => ParentHelp::priceHelp($request->user()->family_id, $stars),
            'cushions' => ParentHelp::starCushions($request->user()->family_id),
        ]);
    }

    public function guide(Request $request): Response|JsonResponse
    {
        $user = $request->user();
        if ($user->isParent()) {
            ParentHelp::markGuideSeen($user);
        }
        $data = ['cards' => ParentHelp::GUIDE];

        return $request->expectsJson() ? response()->json($data) : Inertia::render('help/ParentGuide', $data);
    }

    private function ensureChild(User $parent, User $member): void
    {
        abort_unless($parent->isParent() && $parent->sameFamilyAs($member) && $member->family_role === FamilyRole::Child, 403);
    }
}
