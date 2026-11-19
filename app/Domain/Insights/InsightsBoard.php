<?php

namespace App\Domain\Insights;

use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Karten fürs Heldenpanel (knapp): ungelesener Rückblick, fällige Umfrage und für Eltern die Zahl der Hinweise.
 * Der Wochenrückblick erscheint montags ab 05:00, die Monatskarte in der ersten Woche des Monats,
 * der Jahresrückblick in den ersten Januartagen (Raunächte).
 */
class InsightsBoard
{
    public static function for(User $user): array
    {
        if ($user->family_id === null) {
            return ['review' => null, 'survey' => null, 'hints' => 0];
        }

        return [
            'review' => self::review($user),
            'survey' => app(SurveyService::class)->due($user),
            'hints' => $user->isParent() && $user->family ? count(app(Hints::class)->for($user->family)) : 0,
        ];
    }

    private static function review(User $user): ?array
    {
        $reviews = app(ReviewService::class);
        $local = CarbonImmutable::now(ReviewService::timezone($user))->subHours(ReviewService::RELEASE_HOUR);

        $candidates = ['week'];
        if ($local->day <= 7) {
            array_unshift($candidates, 'month');
        }
        if ($local->month === 1 && $local->day <= 6) {
            array_unshift($candidates, 'year');
        }

        foreach ($candidates as $kind) {
            $review = $reviews->get($user, $kind);
            if ($review !== null && $review->seen_at === null) {
                return ReviewService::present($review, $user);
            }
        }

        return null;
    }
}
