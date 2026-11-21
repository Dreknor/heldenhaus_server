<?php

namespace App\Domain\Council;

use App\Domain\Achievements\ChronicleService;
use App\Domain\Gameplay\GameException;
use App\Domain\Goals\GoalService;
use App\Domain\Notifications\PushNotifier;
use App\Domain\Quests\QuestService;
use App\Domain\Social\FeedService;
use App\Enums\CompletionStatus;
use App\Enums\FamilyRole;
use App\Models\Family;
use App\Models\FamilyCouncil;
use App\Models\FeedEntry;
use App\Models\Proposal;
use App\Models\Quest;
use App\Models\TaskCompletion;
use App\Models\User;
use App\Models\Vote;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Familienrat (§14.4): wöchentlich (Standard Sonntag 18 Uhr), etwa 10 Minuten am Familiengerät.
 * 1 Rückblick · 2 Danke-Runde · 3 Vorschläge entscheiden · 4 Abstimmung · 5 ein Wunsch je Person.
 * Freiwillig: Ausgelassene Räte haben keine Folgen.
 */
class CouncilService
{
    public const STEPS = [
        1 => 'Rückblick',
        2 => 'Danke-Runde',
        3 => 'Vorschläge',
        4 => 'Abstimmung',
        5 => 'Wünsche',
    ];

    public const WEEKDAYS = [1 => 'Montag', 2 => 'Dienstag', 3 => 'Mittwoch', 4 => 'Donnerstag', 5 => 'Freitag', 6 => 'Samstag', 0 => 'Sonntag'];

    /** Gründe zum Danken zum Antippen (Kinder unter 13 ohne Freitext). */
    public const THANKS = [
        'fürs Helfen',
        'fürs Vorlesen',
        'fürs Kochen',
        'fürs Trösten',
        'fürs Aufräumen',
        'fürs Spielen',
        'fürs Zuhören',
        'fürs Fahren',
    ];

    /** Wünsche für die nächste Woche zum Antippen. */
    public const WISHES = [
        'Mehr zusammen spielen',
        'Einen Ausflug machen',
        'Weniger Streit beim Aufräumen',
        'Jeden Abend vorlesen',
        'Früher ins Bett',
        'Mehr draußen sein',
    ];

    public function __construct(
        private readonly FeedService $feed,
        private readonly PushNotifier $push,
    ) {}

    // ---------- Einstellungen und Termin ----------

    /** @return array{enabled: bool, weekday: int, time: string, free_text: bool} */
    public static function settings(Family $family): array
    {
        return [
            'enabled' => (bool) $family->setting('council.enabled', true),
            'weekday' => (int) $family->setting('council.weekday', 0),
            'time' => (string) $family->setting('council.time', '18:00'),
            'free_text' => (bool) $family->setting('council.free_text', false),
        ];
    }

    public function updateSettings(User $parent, array $data): array
    {
        if (! $parent->isParent()) {
            throw new GameException('Den Familienrat stellen die Eltern ein.');
        }
        $family = $parent->family;
        $settings = $family->settings ?? [];
        $settings['council'] = array_merge($settings['council'] ?? [], [
            'enabled' => (bool) ($data['enabled'] ?? true),
            'weekday' => (int) $data['weekday'],
            'time' => (string) $data['time'],
            'free_text' => (bool) ($data['free_text'] ?? false),
        ]);
        $family->update(['settings' => $settings]);

        return self::settings($family);
    }

    /** Nächster Termin in der Zeitzone der Familie (heute zählt, solange die Uhrzeit noch nicht vorbei ist). */
    public static function nextAt(Family $family, ?CarbonImmutable $now = null): CarbonImmutable
    {
        $s = self::settings($family);
        $tz = $family->timezone ?: 'Europe/Berlin';
        $local = ($now ?? CarbonImmutable::now())->setTimezone($tz);
        [$h, $m] = array_map('intval', explode(':', $s['time']) + [0, 0]);

        $candidate = $local->setTime($h, $m);
        $days = ($s['weekday'] - $candidate->dayOfWeek + 7) % 7;
        $candidate = $candidate->addDays($days);
        if ($candidate->lessThanOrEqualTo($local)) {
            $candidate = $candidate->addWeek();
        }

        return $candidate;
    }

    /** Erinnerung eine Stunde vorher an alle Mitglieder (außer Gäste). Jeder Termin wird einmal erinnert. */
    public function sendReminders(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $sent = 0;

        Family::query()->chunkById(100, function ($families) use ($now, &$sent) {
            foreach ($families as $family) {
                if (! self::settings($family)['enabled']) {
                    continue;
                }
                $next = self::nextAt($family, $now);
                $key = $next->format('Y-m-d H:i');
                if ($now->lt($next->subHour()) || $family->setting('council.reminded_for') === $key) {
                    continue;
                }
                $settings = $family->settings ?? [];
                $settings['council']['reminded_for'] = $key;
                $family->update(['settings' => $settings]);

                $this->push->notify(VoteService::voters($family->id), 'family_council');
                $sent++;
            }
        });

        return $sent;
    }

    // ---------- Ablauf ----------

    public static function running(?int $familyId): ?FamilyCouncil
    {
        return $familyId ? FamilyCouncil::where('family_id', $familyId)->where('status', 'running')->latest('id')->first() : null;
    }

    public function start(User $actor): FamilyCouncil
    {
        $this->ensureMember($actor);
        if (! $actor->isParent()) {
            throw new GameException('Den Familienrat eröffnen die Eltern, am besten am Familiengerät.');
        }
        if ($council = self::running($actor->family_id)) {
            return $council;
        }
        $family = $actor->family;
        $since = $this->lastFinishedAt($family->id) ?? now()->subWeek();

        return FamilyCouncil::create([
            'family_id' => $family->id,
            'held_on' => CarbonImmutable::now($family->timezone ?: 'Europe/Berlin')->toDateString(),
            'status' => 'running',
            'step' => 1,
            'recap' => self::recap($family, CarbonImmutable::instance($since)),
            'thanks' => [],
            'decisions' => [],
            'wishes' => [],
            'started_by' => $actor->id,
        ]);
    }

    public function step(User $actor, FamilyCouncil $council, int $step): FamilyCouncil
    {
        $this->ensureRunning($actor, $council);
        if (! isset(self::STEPS[$step])) {
            throw new GameException('Diesen Schritt gibt es nicht.');
        }
        $council->update(['step' => $step]);

        return $council;
    }

    /** Danke-Runde: jedes Mitglied wählt eine Person und einen Grund. Landet im Feed. */
    public function thank(User $actor, FamilyCouncil $council, User $from, User $to, string $reason): FamilyCouncil
    {
        $this->ensureRunning($actor, $council);
        $this->ensureSpeaker($actor, $from, $council);
        if (! $to->sameFamilyAs($from) || $to->is($from) || $to->family_role === FamilyRole::Guest) {
            throw new GameException('Wähle jemand anderen aus der Familie.');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new GameException('Wofür möchtest du danken?');
        }
        if (! in_array($reason, self::THANKS, true) && ! ProposalService::freeTextAllowed($from)) {
            throw new GameException('Such dir einen Grund aus der Liste aus.');
        }
        $thanks = $council->thanks ?? [];
        if (collect($thanks)->contains('from', $from->id)) {
            throw new GameException("{$from->name} hat schon gedankt.");
        }
        $thanks[] = ['from' => $from->id, 'to' => $to->id, 'reason' => mb_substr($reason, 0, 120)];
        $council->update(['thanks' => $thanks]);

        $this->feed->post($council->family_id, $from, 'council_thanks', '💛', "{$from->name} dankt {$to->name} ".mb_substr($reason, 0, 120), ['council_id' => $council->id]);

        return $council;
    }

    /** Ein Wunsch für die nächste Woche je Person: als Notiz oder als künftiges Wochenziel. */
    public function wish(User $actor, FamilyCouncil $council, User $member, string $text, string $kind = 'note'): FamilyCouncil
    {
        $this->ensureRunning($actor, $council);
        $this->ensureSpeaker($actor, $member, $council);
        $text = trim($text);
        if ($text === '') {
            throw new GameException('Was wünschst du dir für die nächste Woche?');
        }
        if (! in_array($text, self::WISHES, true) && ! ProposalService::freeTextAllowed($member)) {
            throw new GameException('Such dir einen Wunsch aus der Liste aus.');
        }
        if (! in_array($kind, ['note', 'goal'], true)) {
            throw new GameException('Notiz oder Wochenziel?');
        }
        $wishes = collect($council->wishes ?? [])->reject(fn ($w) => $w['user_id'] === $member->id)->values()->all();
        $wishes[] = ['user_id' => $member->id, 'kind' => $kind, 'text' => mb_substr($text, 0, 120)];
        $council->update(['wishes' => $wishes]);

        return $council;
    }

    /** Entscheidungen über Vorschläge während eines laufenden Rats festhalten. */
    public function recordDecision(Proposal $proposal): void
    {
        $council = self::running($proposal->family_id);
        if ($council === null) {
            return;
        }
        $decisions = $council->decisions ?? [];
        $decisions[] = ['proposal_id' => $proposal->id, 'title' => $proposal->title, 'decision' => $proposal->status, 'by' => $proposal->user?->name];
        $council->update(['decisions' => $decisions]);
        $proposal->forceFill(['family_council_id' => $council->id])->save();
    }

    /** Rat abschließen: ein Feed-Eintrag „Familienrat vom TT.MM.“. */
    public function finish(User $actor, FamilyCouncil $council): FamilyCouncil
    {
        $this->ensureRunning($actor, $council);
        if (! $actor->isParent()) {
            throw new GameException('Den Familienrat schließen die Eltern.');
        }

        return DB::transaction(function () use ($council) {
            $council->update(['status' => 'done', 'step' => 5, 'finished_at' => now()]);
            $vote = Vote::where('family_council_id', $council->id)->latest('id')->first() ?? VoteService::current($council->family_id);
            $winner = $vote?->winner ? collect($vote->options)->firstWhere('key', $vote->winner) : null;

            $parts = array_filter([
                count($council->thanks ?? []) ? count($council->thanks).'× Danke' : null,
                count($council->decisions ?? []) ? count($council->decisions).' '.(count($council->decisions) === 1 ? 'Entscheidung' : 'Entscheidungen') : null,
                $winner ? "nächste Quest: {$winner['name']}" : null,
                count($council->wishes ?? []) ? count($council->wishes).' '.(count($council->wishes) === 1 ? 'Wunsch' : 'Wünsche').' für die Woche' : null,
            ]);
            $date = $council->held_on->format('d.m.');
            $text = "Familienrat vom {$date}".($parts ? ': '.implode(', ', $parts).'.' : '.');

            $this->feed->post($council->family_id, null, 'council_done', '🏰', $text, [
                'council_id' => $council->id,
                'thanks' => count($council->thanks ?? []),
                'decisions' => $council->decisions ?? [],
                'wishes' => $council->wishes ?? [],
                'vote_winner' => $winner['name'] ?? null,
            ]);

            $this->wishesToGoals($council);

            return $council;
        });
    }

    /**
     * Wünsche „als Wochenziel“ (§14.4 Schritt 5): ein Ziel der Person für die kommende Woche, abzuhaken bis Sonntag.
     * Es zählt nicht zu den drei eigenen Zielen; schlägt das Anlegen fehl, bleibt der Wunsch eine Notiz.
     */
    private function wishesToGoals(FamilyCouncil $council): void
    {
        $goals = app(GoalService::class);
        foreach ($council->wishes ?? [] as $wish) {
            $member = ($wish['kind'] ?? 'note') === 'goal' ? User::find((int) $wish['user_id']) : null;
            if ($member === null || $member->family_id !== $council->family_id) {
                continue;
            }

            try {
                $goals->create($member, $member, [
                    'type' => 'count',
                    'title' => $wish['text'],
                    'why' => "Mein Wunsch aus dem Familienrat vom {$council->held_on->format('d.m.')}",
                    'icon' => '🌟',
                    'target' => 1,
                    'unit' => 'geschafft',
                    'ends_on' => $member->gameDate()->next(CarbonImmutable::SUNDAY)->toDateString(),
                ], 'council');
            } catch (GameException) {
                // Wunsch bleibt im Rat als Notiz erhalten
            }
        }
    }

    /** Der Familienrat schlägt gemeinsam das nächste Kapitel der Chronik auf (§14.8). */
    public function openChapter(User $actor, FamilyCouncil $council): ?array
    {
        $this->ensureRunning($actor, $council);

        return app(ChronicleService::class)->openTogether($council->family);
    }

    /**
     * Wünsche aus den Räten seit $since – Grundlage für Wochenziele (§14.1, verdrahtet das Ziele-Modul).
     *
     * @return Collection<int, array{council_id: int, held_on: string, user_id: int, kind: string, text: string}>
     */
    public function wishes(Family $family, \DateTimeInterface|string|null $since = null): Collection
    {
        return FamilyCouncil::where('family_id', $family->id)
            ->when($since, fn ($q) => $q->where('held_on', '>=', CarbonImmutable::parse($since)->toDateString()))
            ->orderBy('held_on')->orderBy('id')->get()
            ->flatMap(fn (FamilyCouncil $c) => collect($c->wishes ?? [])->map(fn ($w) => [
                'council_id' => $c->id,
                'held_on' => $c->held_on->toDateString(),
                'user_id' => (int) $w['user_id'],
                'kind' => $w['kind'],
                'text' => $w['text'],
            ]))->values();
    }

    // ---------- Rückblick ----------

    /**
     * Familien-Wochenleistung als Bild – ohne Ranglisten: besiegte Bosse und geschaffte Quests, fertige Bauprojekte,
     * erreichte Event-Stufen, laufende Quest und die Zahl gemeinsam erledigter Familienaufgaben.
     */
    public static function recap(Family $family, CarbonImmutable $since): array
    {
        $quests = Quest::where('family_id', $family->id)->where('scope', 'family')->where('status', 'completed')
            ->where('completed_at', '>=', $since)->orderBy('completed_at')->get();
        $active = app(QuestService::class)->activeFamily($family->id);
        $feed = FeedEntry::where('family_id', $family->id)->whereIn('type', ['build_done', 'event_tier'])
            ->where('created_at', '>=', $since)->orderBy('id')->get();
        $memberIds = User::where('family_id', $family->id)->pluck('id');

        return [
            'since' => $since->toDateString(),
            'quests' => $quests->map(fn (Quest $q) => ['name' => $q->name, 'icon' => $q->icon, 'boss' => $q->isBoss()])->values()->all(),
            'active_quest' => $active ? [
                'name' => $active->name,
                'icon' => $active->icon,
                'percent' => $active->goal > 0 ? (int) min(100, round($active->progress / $active->goal * 100)) : 0,
            ] : null,
            'builds' => $feed->where('type', 'build_done')->map(fn (FeedEntry $e) => ['icon' => $e->icon, 'text' => $e->text])->values()->all(),
            'event_tiers' => $feed->where('type', 'event_tier')->map(fn (FeedEntry $e) => ['icon' => $e->icon, 'text' => $e->text])->values()->all(),
            'family_tasks' => TaskCompletion::whereIn('user_id', $memberIds)->where('direction', 'up')
                ->whereIn('status', [CompletionStatus::None->value, CompletionStatus::Pending->value, CompletionStatus::Approved->value, CompletionStatus::Auto->value])
                ->where('created_at', '>=', $since)
                ->whereHas('task', fn ($q) => $q->where('scope', 'family'))->count(),
        ];
    }

    // ---------- Ansicht ----------

    public static function present(?FamilyCouncil $council): ?array
    {
        if ($council === null) {
            return null;
        }
        $names = User::where('family_id', $council->family_id)->pluck('name', 'id');

        return [
            'id' => $council->id,
            'held_on' => $council->held_on->toDateString(),
            'status' => $council->status,
            'step' => $council->step,
            'recap' => $council->recap,
            'thanks' => collect($council->thanks ?? [])->map(fn ($t) => [...$t, 'from_name' => $names[$t['from']] ?? '?', 'to_name' => $names[$t['to']] ?? '?'])->values()->all(),
            'decisions' => $council->decisions ?? [],
            'wishes' => collect($council->wishes ?? [])->map(fn ($w) => [...$w, 'name' => $names[$w['user_id']] ?? '?'])->values()->all(),
            'finished_at' => $council->finished_at?->toIso8601String(),
        ];
    }

    /** Alles für die Rats-Seite (Web) und `GET family-council` (App). */
    public static function overview(User $viewer): array
    {
        $family = $viewer->family;
        $settings = self::settings($family);
        $running = self::running($family->id);

        return [
            'settings' => $settings + ['weekday_label' => self::WEEKDAYS[$settings['weekday']] ?? 'Sonntag'],
            'next_at' => self::nextAt($family)->toIso8601String(),
            'council' => self::present($running),
            'preview' => $running ? null : self::recap($family, CarbonImmutable::instance(app(self::class)->lastFinishedAt($family->id) ?? now()->subWeek())),
            'members' => VoteService::voters($family->id)->map(fn (User $u) => [
                'id' => $u->id, 'name' => $u->name, 'avatar_color' => $u->avatar_color, 'role' => $u->family_role?->value,
                'free_text' => ProposalService::freeTextAllowed($u),
            ])->values()->all(),
            'proposals' => collect(ProposalService::listFor($viewer))->where('status', 'open')->values()->all(),
            'vote' => VoteService::present(VoteService::current($family->id), $viewer),
            'history' => FamilyCouncil::where('family_id', $family->id)->where('status', 'done')->latest('held_on')->limit(5)->get()
                ->map(fn (FamilyCouncil $c) => [
                    'id' => $c->id, 'held_on' => $c->held_on->toDateString(), 'thanks' => count($c->thanks ?? []),
                    'decisions' => count($c->decisions ?? []), 'wishes' => count($c->wishes ?? []),
                ])->values()->all(),
            'steps' => self::STEPS,
            'thank_reasons' => self::THANKS,
            'wish_templates' => self::WISHES,
            'decline_reasons' => ProposalService::DECLINE_REASONS,
            'can_lead' => $viewer->isParent(),
        ];
    }

    public function lastFinishedAt(int $familyId): ?\DateTimeInterface
    {
        return FamilyCouncil::where('family_id', $familyId)->where('status', 'done')->latest('finished_at')->value('finished_at');
    }

    private function ensureMember(User $actor): void
    {
        if (! $actor->family_id || $actor->family_role === FamilyRole::Guest) {
            throw new GameException('Beim Familienrat machen alle aus der Familie mit.');
        }
    }

    private function ensureRunning(User $actor, FamilyCouncil $council): void
    {
        $this->ensureMember($actor);
        if ($council->family_id !== $actor->family_id) {
            throw new GameException('Das ist nicht euer Familienrat.');
        }
        if (! $council->isRunning()) {
            throw new GameException('Dieser Familienrat ist schon vorbei.');
        }
    }

    /** Am Familiengerät tragen Eltern für alle ein, sonst spricht jede Person für sich. */
    private function ensureSpeaker(User $actor, User $speaker, FamilyCouncil $council): void
    {
        if ($speaker->family_id !== $council->family_id || $speaker->family_role === FamilyRole::Guest) {
            throw new GameException('Diese Person gehört nicht zum Familienrat.');
        }
        if (! $actor->is($speaker) && ! $actor->isParent()) {
            throw new GameException('Du kannst nur für dich selbst sprechen.');
        }
    }
}
