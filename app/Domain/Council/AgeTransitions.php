<?php

namespace App\Domain\Council;

use App\Domain\Features\Features;
use App\Domain\Gameplay\GameException;
use App\Domain\Support\SupportService;
use App\Enums\FamilyRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Altersübergänge (§14.11). Mit 13 (Jahreswechsel des Geburtsjahrs, Eltern können das Geburtsjahr anpassen)
 * kommt die Karte „Du bist jetzt Teen“, ab 16 das Angebot eines Erwachsenenprofils mit eigener Anmeldung.
 * Klassen, Fortschritt, Taschengeld-Konto und Erfolge bleiben immer erhalten.
 */
class AgeTransitions
{
    public const TEEN_AGE = 13;

    public const ADULT_PROFILE_AGE = 16;

    public static function teenCardDue(User $user): bool
    {
        return $user->family_role === FamilyRole::Child && ($user->age() ?? 0) >= self::TEEN_AGE && $user->teen_card_seen_at === null;
    }

    public static function canBecomeAdult(User $user): bool
    {
        return $user->family_role === FamilyRole::Child && ($user->age() ?? 0) >= self::ADULT_PROFILE_AGE && $user->adult_profile_at === null;
    }

    public static function adultOfferDue(User $user): bool
    {
        return self::canBecomeAdult($user) && $user->adult_offer_dismissed_at === null;
    }

    /** Inhalt der Karten: Themes aus der vorhandenen Theme-Logik, private Aufgaben, Chat nach Elternfreigabe. */
    public static function present(User $user): array
    {
        return [
            'age' => $user->age(),
            'teen_card' => self::teenCardDue($user),
            'is_teen' => $user->family_role === FamilyRole::Child && ($user->age() ?? 0) >= self::TEEN_AGE,
            'themes' => SupportService::themesFor($user),
            'theme' => SupportService::theme($user),
            'chat_allowed' => (bool) $user->chat_allowed,
            'adult_offer' => self::adultOfferDue($user),
            'can_become_adult' => self::canBecomeAdult($user),
            'adult_profile' => $user->adult_profile_at !== null,
            'has_email' => $user->email !== null,
            'show_all' => (bool) $user->show_all_features,
        ];
    }

    public function teenCardSeen(User $user): void
    {
        if ($user->teen_card_seen_at === null) {
            $user->forceFill(['teen_card_seen_at' => now()])->save();
        }
    }

    public function dismissAdultOffer(User $user): void
    {
        $user->forceFill(['adult_offer_dismissed_at' => now()])->save();
    }

    /**
     * Erwachsenenprofil einrichten: eigene Anmeldung mit E-Mail und Passwort, „Alles sofort zeigen“.
     * Danach sehen die Eltern nur noch Familienaufgaben und Sterne.
     */
    public function activateAdultProfile(User $user, string $email, string $password, Features $features): User
    {
        if (! self::canBecomeAdult($user)) {
            throw new GameException('Das Erwachsenenprofil gibt es ab 16.');
        }
        if (User::where('email', $email)->whereKeyNot($user->id)->exists()) {
            throw new GameException('Diese E-Mail-Adresse wird schon verwendet.');
        }

        DB::transaction(function () use ($user, $email, $password, $features) {
            $user->forceFill([
                'email' => $email,
                'password' => $password,
                'adult_profile_at' => now(),
                'adult_offer_dismissed_at' => null,
                'teen_card_seen_at' => $user->teen_card_seen_at ?? now(),
            ])->save();
            $features->showAll($user, true);
        });

        return $user->refresh();
    }
}
