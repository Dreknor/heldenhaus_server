<?php

namespace App\Domain\Council;

use App\Models\Proposal;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Knappe Hinweise für das Helden-Board (Web und App): Familienrat, offene Abstimmung, Vorschläge,
 * Eltern-Anleitung und Altersübergänge. Höchstens drei auf einmal, wichtigste zuerst.
 */
class BoardHints
{
    public const MAX = 3;

    /** @return list<array{key: string, icon: string, text: string, href: string, action?: string, dismiss?: string}> */
    public static function for(User $user): array
    {
        if (! $user->family_id || ! $user->family) {
            return [];
        }
        $family = $user->family;
        $hints = [];

        // Eltern-Anleitung: beim ersten Login einmal angeboten, bis sie gelesen oder weggeklickt ist
        if (ParentHelp::guideOffered($user)) {
            $hints[] = ['key' => 'guide', 'icon' => '📘', 'text' => 'Neu hier? 6 kurze Tipps für Eltern.', 'href' => '/help/parents', 'dismiss' => 'guide'];
        }

        if (AgeTransitions::teenCardDue($user)) {
            $hints[] = ['key' => 'teen', 'icon' => '🎉', 'text' => 'Du bist jetzt Teen! Schau dir an, was neu ist.', 'href' => '/me/growing-up', 'dismiss' => 'teen'];
        } elseif (AgeTransitions::adultOfferDue($user)) {
            $hints[] = ['key' => 'adult', 'icon' => '🔑', 'text' => 'Angebot: Erwachsenenprofil mit eigener Anmeldung einrichten.', 'href' => '/me/growing-up', 'dismiss' => 'adult'];
        }

        if ($council = CouncilService::running($family->id)) {
            $hints[] = ['key' => 'council', 'icon' => '🏰', 'text' => 'Der Familienrat läuft gerade. Komm dazu!', 'href' => '/family/council'];
        } else {
            $settings = CouncilService::settings($family);
            $next = CouncilService::nextAt($family);
            $now = CarbonImmutable::now($family->timezone ?: 'Europe/Berlin');
            if ($settings['enabled'] && $next->isSameDay($now)) {
                $hints[] = ['key' => 'council', 'icon' => '🏰', 'text' => 'Heute um '.$next->format('H:i').' Uhr ist Familienrat.', 'href' => '/family/council'];
            }
        }

        $vote = VoteService::current($family->id);
        if ($vote && $vote->status === 'open' && ! array_key_exists((string) $user->id, $vote->ballots ?? []) && $user->family_role?->value !== 'guest') {
            $hints[] = ['key' => 'vote', 'icon' => '🗳️', 'text' => 'Abstimmung: Welche Familien-Quest kommt als Nächstes?', 'href' => '/family/council#vote'];
        } elseif ($vote && $vote->status === 'tie' && $user->isParent()) {
            $hints[] = ['key' => 'vote', 'icon' => '⚖️', 'text' => 'Gleichstand bei der Abstimmung – ihr entscheidet.', 'href' => '/family/council#vote'];
        }

        if ($user->isParent()) {
            $open = ProposalService::openCount($family->id);
            if ($open > 0) {
                $hints[] = ['key' => 'proposals', 'icon' => '💡', 'text' => $open === 1 ? 'Ein Vorschlag wartet auf euch.' : "{$open} Vorschläge warten auf euch.", 'href' => '/proposals'];
            }
        } else {
            $decided = Proposal::where('user_id', $user->id)->where('status', '!=', 'open')
                ->where('decided_at', '>=', now()->subDays(2))->latest('decided_at')->first();
            if ($decided) {
                $hints[] = ['key' => 'proposal', 'icon' => $decided->status === 'accepted' ? '✅' : '💬',
                    'text' => $decided->status === 'accepted' ? "Dein Vorschlag „{$decided->title}“ wurde angenommen!" : "Zu deinem Vorschlag „{$decided->title}“ gibt es eine Antwort.",
                    'href' => '/proposals'];
            }
        }

        return array_slice($hints, 0, self::MAX);
    }
}
