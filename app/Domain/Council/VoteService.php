<?php

namespace App\Domain\Council;

use App\Domain\Gameplay\GameException;
use App\Domain\Notifications\PushNotifier;
use App\Domain\Quests\QuestService;
use App\Domain\Social\FeedService;
use App\Enums\FamilyRole;
use App\Models\User;
use App\Models\Vote;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Abstimmung über die nächste Familien-Quest (§14.4): drei Familien-Quests von der Quest-Tafel, eine Stimme je
 * Mitglied, bei Gleichstand entscheiden die Eltern. Die gewählte Quest startet über QuestService::start –
 * läuft gerade noch eine Familien-Quest, startet sie, sobald diese vorbei ist.
 */
class VoteService
{
    /** Ohne Abschluss durch die Eltern endet eine Abstimmung nach so vielen Tagen. */
    public const DAYS = 3;

    public function __construct(
        private readonly QuestService $quests,
        private readonly FeedService $feed,
        private readonly PushNotifier $push,
    ) {}

    /** Stimmberechtigt sind alle außer Gästen. */
    public static function voters(int $familyId): Collection
    {
        return User::where('family_id', $familyId)->where('family_role', '!=', FamilyRole::Guest->value)->orderBy('name')->get();
    }

    /** Laufende oder auf Entscheidung bzw. Start wartende Abstimmung der Familie. */
    public static function current(?int $familyId): ?Vote
    {
        return $familyId ? Vote::where('family_id', $familyId)->whereIn('status', ['open', 'tie', 'decided'])->latest('id')->first() : null;
    }

    public function open(User $parent, ?int $councilId = null): Vote
    {
        if (! $parent->isParent()) {
            throw new GameException('Eine Abstimmung starten die Eltern.');
        }
        if (self::current($parent->family_id) !== null) {
            throw new GameException('Es läuft schon eine Abstimmung.');
        }
        $offers = $this->quests->offers($parent, 'family');
        if (count($offers) < 2) {
            throw new GameException('Auf der Quest-Tafel sind gerade nicht genug Familien-Quests.');
        }

        $vote = Vote::create([
            'family_id' => $parent->family_id,
            'occasion' => 'family_quest',
            'options' => collect($offers)->take(QuestService::OFFERS)->map(fn ($q) => [
                'key' => $q['key'],
                'name' => $q['name'],
                'icon' => $q['icon'],
                'type' => $q['type'],
                'days' => $q['days'] ?? null,
                'description' => $q['description'] ?? null,
                'art' => $q['art'] ?? null,
            ])->values()->all(),
            'ballots' => [],
            'status' => 'open',
            'opened_by' => $parent->id,
            'family_council_id' => $councilId,
            'closes_at' => now()->addDays(self::DAYS),
        ]);

        $this->feed->post($parent->family_id, $parent, 'vote_opened', '🗳️', 'Abstimmung: Welche Familien-Quest kommt als Nächstes? Jede Stimme zählt!', ['vote_id' => $vote->id]);
        $this->push->notify(self::voters($parent->family_id)->reject(fn (User $u) => $u->is($parent)), 'vote_opened');

        return $vote;
    }

    /**
     * Eine Stimme abgeben. Am Familiengerät dürfen Eltern die Stimme eines Mitglieds eintragen.
     * Jedes Mitglied hat genau eine Stimme.
     */
    public function cast(User $actor, Vote $vote, string $option, ?User $voter = null): Vote
    {
        $voter ??= $actor;
        if (! $voter->is($actor) && ! ($actor->isParent() && $actor->sameFamilyAs($voter))) {
            throw new GameException('Du kannst nur selbst abstimmen.');
        }
        if ($voter->family_id !== $vote->family_id || $voter->family_role === FamilyRole::Guest) {
            throw new GameException('Bei dieser Abstimmung kannst du nicht mitmachen.');
        }

        return DB::transaction(function () use ($vote, $option, $voter) {
            $vote = Vote::lockForUpdate()->findOrFail($vote->id);
            if ($vote->status !== 'open') {
                throw new GameException('Die Abstimmung ist schon vorbei.');
            }
            if (! in_array($option, $vote->optionKeys(), true)) {
                throw new GameException('Diese Quest steht nicht zur Wahl.');
            }
            $ballots = $vote->ballots ?? [];
            if (array_key_exists((string) $voter->id, $ballots)) {
                throw new GameException($voter->name.' hat schon abgestimmt. Jede Person hat eine Stimme.');
            }
            $ballots[(string) $voter->id] = $option;
            $vote->ballots = $ballots;
            $vote->save();

            // Alle haben abgestimmt: Ergebnis sofort auswerten
            if (count($ballots) >= self::voters($vote->family_id)->count()) {
                $this->close($vote);
            }

            return $vote->refresh();
        });
    }

    /** Abstimmung beenden (Eltern, Ablauf oder alle haben abgestimmt). Gleichstand → die Eltern entscheiden. */
    public function close(Vote $vote, ?User $parent = null): Vote
    {
        if ($parent !== null && (! $parent->isParent() || $parent->family_id !== $vote->family_id)) {
            throw new GameException('Die Abstimmung beenden die Eltern.');
        }
        if ($vote->status !== 'open') {
            return $vote;
        }

        $leaders = self::leaders($vote);
        $vote->closed_at = now();
        if (count($leaders) === 1) {
            $vote->status = 'decided';
            $vote->winner = $leaders[0];
            $vote->save();
            $this->announce($vote);
            $this->startWinner($vote);
        } else {
            $vote->status = 'tie';
            $vote->save();
            $this->push->notify(PushNotifier::parentsOf($vote->family_id), 'vote_tie');
        }

        return $vote;
    }

    /** Gleichstand: Die Eltern wählen eine der gleichauf liegenden Quests. */
    public function decideTie(User $parent, Vote $vote, string $option): Vote
    {
        if (! $parent->isParent() || $parent->family_id !== $vote->family_id) {
            throw new GameException('Bei Gleichstand entscheiden die Eltern.');
        }

        return DB::transaction(function () use ($parent, $vote, $option) {
            $vote = Vote::lockForUpdate()->findOrFail($vote->id);
            if ($vote->status !== 'tie') {
                throw new GameException('Hier gibt es keinen Gleichstand zu entscheiden.');
            }
            if (! in_array($option, self::leaders($vote), true)) {
                throw new GameException('Wählt eine der Quests, die gleichauf liegen.');
            }
            $vote->fill(['status' => 'decided', 'winner' => $option, 'decided_by' => $parent->id])->save();
            $this->announce($vote);
            $this->startWinner($vote);

            return $vote->refresh();
        });
    }

    /**
     * Gewählte Quest starten – über die vorhandene Startlogik der Quest-Tafel. Läuft noch eine Familien-Quest,
     * bleibt die Abstimmung „decided“ und wird beim nächsten Versuch (Quest-Ende, Scheduler) gestartet.
     */
    public function startWinner(Vote $vote): bool
    {
        if ($vote->status !== 'decided' || $vote->winner === null) {
            return false;
        }
        if ($this->quests->activeFamily($vote->family_id) !== null) {
            return false;
        }
        $starter = User::where('family_id', $vote->family_id)->where('family_role', FamilyRole::Parent->value)
            ->orderByRaw('id = ? desc', [(int) ($vote->decided_by ?? $vote->opened_by)])->first();
        if ($starter === null) {
            return false;
        }

        try {
            $quest = $this->quests->start($starter, $vote->winner, 'family');
        } catch (GameException) {
            // z. B. Event-Quest vorbei oder Level zu niedrig geworden: nicht hängen bleiben
            $vote->update(['status' => 'cancelled']);

            return false;
        }
        $vote->update(['status' => 'started', 'quest_id' => $quest->id]);

        return true;
    }

    /** Abgelaufene Abstimmungen schließen und wartende Quests starten (Scheduler). */
    public function tick(): array
    {
        $closed = 0;
        Vote::where('status', 'open')->where('closes_at', '<=', now())->get()->each(function (Vote $v) use (&$closed) {
            $this->close($v);
            $closed++;
        });
        $started = Vote::where('status', 'decided')->get()->filter(fn (Vote $v) => $this->startWinner($v))->count();

        return ['closed' => $closed, 'started' => $started];
    }

    /** @return list<string> Optionen mit den meisten Stimmen (ohne Stimmen: alle) */
    public static function leaders(Vote $vote): array
    {
        $counts = self::counts($vote);
        $max = max($counts ?: [0]);

        return array_keys(array_filter($counts, fn ($n) => $n === $max));
    }

    /** @return array<string, int> */
    public static function counts(Vote $vote): array
    {
        $counts = array_fill_keys($vote->optionKeys(), 0);
        foreach ($vote->ballots ?? [] as $option) {
            if (isset($counts[$option])) {
                $counts[$option]++;
            }
        }

        return $counts;
    }

    /** Ansicht ohne Rangliste der Personen: Stimmen je Quest, wer schon abgestimmt hat (nicht wofür). */
    public static function present(?Vote $vote, User $viewer): ?array
    {
        if ($vote === null) {
            return null;
        }
        $ballots = $vote->ballots ?? [];
        $voters = self::voters($vote->family_id);
        $counts = self::counts($vote);
        $showCounts = $vote->status !== 'open';

        return [
            'id' => $vote->id,
            'occasion' => $vote->occasion,
            'status' => $vote->status,
            'options' => collect($vote->options)->map(fn ($o) => [...$o, 'votes' => $showCounts ? $counts[$o['key']] ?? 0 : null])->values()->all(),
            'winner' => $vote->winner,
            'leaders' => $vote->status === 'tie' ? self::leaders($vote) : [],
            'my_vote' => $ballots[(string) $viewer->id] ?? null,
            'voters' => $voters->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'avatar_color' => $u->avatar_color, 'voted' => array_key_exists((string) $u->id, $ballots)])->values()->all(),
            'votes_cast' => count($ballots),
            'closes_at' => $vote->closes_at?->toIso8601String(),
            'quest_id' => $vote->quest_id,
            'can_manage' => $viewer->isParent() && $viewer->family_id === $vote->family_id,
        ];
    }

    private function announce(Vote $vote): void
    {
        $option = collect($vote->options)->firstWhere('key', $vote->winner);
        $this->feed->post($vote->family_id, null, 'vote_decided', $option['icon'] ?? '🗳️', "Abgestimmt! Als Nächstes kommt die Quest „{$option['name']}“.", ['vote_id' => $vote->id]);
    }
}
