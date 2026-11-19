<?php

namespace App\Domain\Insights;

use App\Domain\Notifications\PushNotifier;
use App\Models\Family;
use App\Models\FamilyMetric;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Nächtlicher Lauf (heldenhaus:insights, stündlich) in der Zeitzone jeder Familie:
 * - ab 02:00: Aktivitätswerte der letzten 14 Tage neu rechnen und family_metrics füllen
 *   (das ganze Fenster, weil Freigaben nachträglich abgelehnt werden können),
 * - ab 05:00: Wochen-, Monats- und Jahresrückblicke erzeugen (ReviewService gibt immer nur
 *   abgeschlossene Zeiträume heraus; schon vorhandene bleiben unverändert).
 */
class NightlyInsights
{
    public const METRICS_HOUR = 2;

    public function __construct(
        private readonly ActivityScores $scores,
        private readonly FamilyMetrics $metrics,
        private readonly ReviewService $reviews,
        private readonly PushNotifier $push,
    ) {}

    /** @return array{families: int, reviews: int} */
    public function run(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $families = 0;
        $created = 0;

        Family::chunkById(50, function ($chunk) use ($now, &$families, &$created) {
            foreach ($chunk as $family) {
                $done = $this->family($family, $now);
                $families += $done['metrics'] ? 1 : 0;
                $created += $done['reviews'];
            }
        });

        return ['families' => $families, 'reviews' => $created];
    }

    /** @return array{metrics: bool, reviews: int} */
    public function family(Family $family, CarbonImmutable $now): array
    {
        $local = $now->setTimezone($family->timezone ?: 'Europe/Berlin');
        $members = FamilyMetrics::members($family);
        $result = ['metrics' => false, 'reviews' => 0];

        $yesterday = $local->startOfDay()->subDay();
        if ($local->hour >= self::METRICS_HOUR && ! FamilyMetric::where('family_id', $family->id)->whereDate('date', $yesterday->toDateString())->exists()) {
            foreach ($members as $member) {
                $this->scores->fill($member);
            }
            $this->metrics->ensure($family, $yesterday->subDays(FamilyMetrics::WINDOW - 1), $yesterday, refresh: true);
            $result['metrics'] = true;
        }

        if ($local->hour >= ReviewService::RELEASE_HOUR) {
            $newWeek = [];
            foreach ($members as $member) {
                foreach (ReviewService::KINDS as $kind) {
                    $review = $this->reviews->get($member, $kind);
                    if ($review?->wasRecentlyCreated) {
                        $result['reviews']++;
                        $kind === 'week' && $newWeek[] = $member;
                    }
                }
            }
            if ($newWeek !== []) {
                $this->push->notify(User::whereIn('id', collect($newWeek)->pluck('id'))->get(), 'weekly_review');
            }
        }

        return $result;
    }
}
