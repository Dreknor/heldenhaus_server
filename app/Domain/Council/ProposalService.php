<?php

namespace App\Domain\Council;

use App\Domain\Gameplay\GameException;
use App\Domain\Notifications\PushNotifier;
use App\Domain\Rewards\RewardTemplates;
use App\Domain\Social\FeedService;
use App\Domain\Tasks\TaskService;
use App\Enums\AgeGroup;
use App\Enums\FamilyRole;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Vorschläge der Kinder (§14.4): Belohnungen für den Sterne-Shop, Aufgaben für die Börse oder für die Eltern.
 * Eltern nehmen an (auch mit geändertem Titel oder Preis) oder lehnen freundlich mit Begründung ab.
 */
class ProposalService
{
    /** Höchstens so viele offene Vorschläge pro Person, damit der Rat nicht überläuft. */
    public const MAX_OPEN = 5;

    /** Aufgaben, die Kinder ihren Eltern vorschlagen können (unter 13 ohne Freitext). */
    public const PARENT_TASKS = [
        'bike_repair' => ['title' => 'Fahrrad reparieren', 'icon' => '🚲'],
        'play_together' => ['title' => 'Eine Runde mit mir spielen', 'icon' => '🎲'],
        'read_aloud' => ['title' => 'Mir etwas vorlesen', 'icon' => '📖'],
        'trip_plan' => ['title' => 'Einen Ausflug planen', 'icon' => '🗺️'],
        'bake' => ['title' => 'Zusammen backen', 'icon' => '🧁'],
        'shelf' => ['title' => 'Regal aufbauen', 'icon' => '🔨'],
        'photos' => ['title' => 'Fotos ausdrucken', 'icon' => '🖼️'],
        'phone_free' => ['title' => 'Einen Abend ohne Handy', 'icon' => '📵'],
    ];

    /** Freundliche Begründungen zum Antippen (Eltern können frei schreiben). */
    public const DECLINE_REASONS = [
        'Das passt gerade nicht, lass uns später noch mal schauen.',
        'Lass uns im Familienrat darüber reden.',
        'Gute Idee, aber das ist uns zu teuer.',
        'Das machen wir lieber zusammen anders.',
    ];

    public function __construct(
        private readonly FeedService $feed,
        private readonly TaskService $tasks,
        private readonly PushNotifier $push,
    ) {}

    /** Kinder unter 13 schreiben nur frei, wenn die Eltern das erlauben. */
    public static function freeTextAllowed(User $user): bool
    {
        return $user->ageGroup() !== AgeGroup::Child || (bool) $user->family?->setting('council.free_text', false);
    }

    /** Textvorlagen für Vorschläge, passend zum Alter. */
    public static function templates(User $user): array
    {
        return [
            'reward' => collect(RewardTemplates::all())->map(fn ($t) => [
                'key' => $t['key'], 'title' => $t['title'], 'icon' => $t['icon'] ?? '🎁', 'stars' => (int) $t['star_cost'],
            ])->values()->all(),
            'pool' => collect(TaskTemplates::filter(TaskTemplates::ageBand($user)))->map(fn ($t) => [
                'key' => $t['key'], 'title' => $t['title'], 'icon' => $t['icon'], 'stars' => max(1, (int) $t['stars']),
            ])->values()->all(),
            'parent' => collect(self::PARENT_TASKS)->map(fn ($t, $key) => ['key' => $key, ...$t, 'stars' => null])->values()->all(),
        ];
    }

    /**
     * @param  array{kind: string, target?: ?string, for_user_id?: ?int, template_key?: ?string, title?: ?string, stars?: ?int}  $data
     */
    public function propose(User $user, array $data): Proposal
    {
        if ($user->isParent() || $user->family_role === FamilyRole::Guest || ! $user->family_id) {
            throw new GameException('Vorschläge machen die Kinder. Eltern legen Belohnungen und Aufgaben direkt an.');
        }
        $kind = $data['kind'];
        $target = $kind === 'task' ? ($data['target'] ?? 'pool') : null;
        if ($kind === 'task' && ! in_array($target, ['pool', 'parent'], true)) {
            throw new GameException('Für wen ist die Aufgabe?');
        }

        $forUser = null;
        if ($target === 'parent') {
            $forUser = User::where('family_id', $user->family_id)->where('family_role', FamilyRole::Parent->value)
                ->whereKey($data['for_user_id'] ?? 0)->first() ?? throw new GameException('Wähle, für welches Elternteil die Aufgabe ist.');
        }

        $template = null;
        if (! empty($data['template_key'])) {
            $group = $kind === 'reward' ? 'reward' : $target;
            $template = collect(self::templates($user)[$group])->firstWhere('key', $data['template_key'])
                ?? throw new GameException('Diese Vorlage gibt es nicht.');
        } elseif (! self::freeTextAllowed($user)) {
            throw new GameException('Such dir einen Vorschlag aus der Liste aus. Eigene Texte schalten deine Eltern frei.');
        }

        $title = trim((string) ($template['title'] ?? $data['title'] ?? ''));
        if ($title === '') {
            throw new GameException('Wie soll der Vorschlag heißen?');
        }

        $open = Proposal::where('user_id', $user->id)->where('status', 'open')->count();
        if ($open >= self::MAX_OPEN) {
            throw new GameException('Du hast schon '.self::MAX_OPEN.' offene Vorschläge. Warte, bis deine Eltern entschieden haben.');
        }

        $proposal = Proposal::create([
            'family_id' => $user->family_id,
            'user_id' => $user->id,
            'kind' => $kind,
            'target' => $target,
            'for_user_id' => $forUser?->id,
            'template_key' => $template['key'] ?? null,
            'title' => mb_substr($title, 0, 120),
            'icon' => $template['icon'] ?? ($kind === 'reward' ? '🎁' : '📝'),
            'stars' => $target === 'parent' ? null : (isset($data['stars']) ? (int) $data['stars'] : ($template['stars'] ?? null)),
            'status' => 'open',
        ]);

        $this->push->notify(PushNotifier::parentsOf($user->family_id), 'proposal_new');

        return $proposal;
    }

    /**
     * Annehmen, gegebenenfalls geändert: Belohnung landet im Sterne-Shop, Aufgabe wird angelegt.
     *
     * @param  array{title?: ?string, stars?: ?int}  $changes
     */
    public function accept(User $parent, Proposal $proposal, array $changes = []): Proposal
    {
        $this->ensureParent($parent, $proposal);

        return DB::transaction(function () use ($parent, $proposal, $changes) {
            $proposal = Proposal::lockForUpdate()->findOrFail($proposal->id);
            if (! $proposal->isOpen()) {
                throw new GameException('Über diesen Vorschlag wurde schon entschieden.');
            }

            $title = trim((string) ($changes['title'] ?? '')) ?: $proposal->title;
            $stars = isset($changes['stars']) ? max(0, (int) $changes['stars']) : $proposal->stars;
            $changed = $title !== $proposal->title || ($stars !== $proposal->stars && $proposal->target !== 'parent');

            if ($proposal->kind === 'reward') {
                if (($stars ?? 0) < 1) {
                    throw new GameException('Eine Belohnung braucht einen Preis von mindestens 1 Stern.');
                }
                $item = $parent->family->rewardItems()->create([
                    'title' => $title,
                    'icon' => $proposal->icon ?: '🎁',
                    'star_cost' => $stars,
                    'requires_approval' => true,
                    'active' => true,
                ]);
                $proposal->reward_item_id = $item->id;
                $text = "Neu im Shop: „{$title}“ für {$stars} Sterne – ein Vorschlag von {$proposal->user->name}!";
            } else {
                $template = $proposal->template_key ? TaskTemplates::get($proposal->template_key) : null;
                $base = $template && $proposal->target === 'pool'
                    ? TaskTemplates::taskData($template)
                    : ['type' => 'todo', 'category' => $proposal->target === 'parent' ? 'others' : 'household', 'difficulty' => 1.0];
                if ($base['type'] === 'daily' || $base['type'] === 'habit') {
                    // Börse und Eltern-Aufgaben sind einmalige To-dos
                    $base = ['type' => 'todo', 'category' => $base['category'], 'difficulty' => $base['difficulty'], 'checklist' => $template['steps'] ?? null];
                }
                $task = $this->tasks->create($parent, array_filter([
                    ...$base,
                    'title' => $title,
                    'scope' => 'family',
                    'assignment_mode' => $proposal->target === 'parent' ? 'assigned' : 'pool',
                    'approval_mode' => $proposal->target === 'parent' ? 'none' : 'required',
                    'star_reward' => $proposal->target === 'parent' ? 0 : (int) ($stars ?? 0),
                    'assignees' => $proposal->target === 'parent' ? [$proposal->for_user_id] : [],
                ], fn ($v) => $v !== null));
                $proposal->task_id = $task->id;
                $text = $proposal->target === 'parent'
                    ? "{$proposal->forUser?->name} übernimmt „{$title}“ – ein Wunsch von {$proposal->user->name}!"
                    : "Neu in der Aufgabenbörse: „{$title}“ – ein Vorschlag von {$proposal->user->name}!";
            }

            $proposal->fill([
                'status' => 'accepted',
                'title' => $title,
                'stars' => $proposal->target === 'parent' ? null : $stars,
                'decided_by' => $parent->id,
                'decided_at' => now(),
                'reason' => $changed ? 'Angenommen mit kleinen Änderungen.' : null,
            ])->save();

            $this->feed->post($proposal->family_id, $proposal->user, 'proposal_accepted', $proposal->icon ?: '💡', $text, ['proposal_id' => $proposal->id]);
            app(CouncilService::class)->recordDecision($proposal);
            $this->push->notify($proposal->user, 'proposal_decided');

            return $proposal;
        });
    }

    /** Freundlich ablehnen – immer mit Begründung. Abgelehnte Vorschläge erscheinen nicht im Feed. */
    public function decline(User $parent, Proposal $proposal, string $reason): Proposal
    {
        $this->ensureParent($parent, $proposal);
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw new GameException('Schreibt kurz dazu, warum es gerade nicht passt.');
        }

        return DB::transaction(function () use ($parent, $proposal, $reason) {
            $proposal = Proposal::lockForUpdate()->findOrFail($proposal->id);
            if (! $proposal->isOpen()) {
                throw new GameException('Über diesen Vorschlag wurde schon entschieden.');
            }
            $proposal->fill(['status' => 'declined', 'reason' => mb_substr($reason, 0, 300), 'decided_by' => $parent->id, 'decided_at' => now()])->save();
            app(CouncilService::class)->recordDecision($proposal);
            $this->push->notify($proposal->user, 'proposal_decided');

            return $proposal;
        });
    }

    public static function openCount(?int $familyId): int
    {
        return $familyId ? Proposal::where('family_id', $familyId)->where('status', 'open')->count() : 0;
    }

    /** Eltern sehen alle Vorschläge der Familie, Kinder nur ihre eigenen. */
    public static function listFor(User $viewer, int $limit = 40): array
    {
        return Proposal::with('user:id,name,avatar_color', 'forUser:id,name', 'decider:id,name')
            ->where('family_id', $viewer->family_id)
            ->when(! $viewer->isParent(), fn ($q) => $q->where('user_id', $viewer->id))
            ->orderByRaw("status = 'open' desc")->latest('id')->limit($limit)->get()
            ->map(fn (Proposal $p) => self::present($p))->all();
    }

    public static function present(Proposal $p): array
    {
        return [
            'id' => $p->id,
            'kind' => $p->kind,
            'target' => $p->target,
            'title' => $p->title,
            'icon' => $p->icon,
            'stars' => $p->stars,
            'status' => $p->status,
            'reason' => $p->reason,
            'user' => $p->user?->only(['id', 'name', 'avatar_color']),
            'for_user' => $p->forUser?->only(['id', 'name']),
            'decided_by' => $p->decider?->name,
            'created_at' => $p->created_at->toIso8601String(),
            'decided_at' => $p->decided_at?->toIso8601String(),
        ];
    }

    private function ensureParent(User $parent, Proposal $proposal): void
    {
        if (! $parent->isParent() || $parent->family_id !== $proposal->family_id) {
            throw new GameException('Über Vorschläge entscheiden die Eltern.');
        }
    }
}
