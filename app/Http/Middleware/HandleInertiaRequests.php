<?php

namespace App\Http\Middleware;

use App\Domain\Achievements\AchievementService;
use App\Domain\Achievements\ChronicleService;
use App\Domain\Achievements\TitleService;
use App\Domain\Council\ProposalService;
use App\Domain\Support\SupportService;
use App\Enums\CompletionStatus;
use App\Http\Presenters\CharacterSheet;
use App\Http\Presenters\FeatureView;
use App\Http\Presenters\GamePresenter;
use App\Models\RewardRedemption;
use App\Models\TaskCompletion;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'email_verified_at' => $user->email_verified_at,
                    'family_role' => $user->family_role?->value,
                    'age_group' => $user->age_group,
                    'theme' => $user->theme,
                    'avatar_color' => $user->avatar_color,
                    'is_admin' => $user->is_admin,
                    'adult_profile' => $user->isIndependent(),
                    'created_at' => $user->created_at,
                    'updated_at' => $user->updated_at,
                ] : null,
                'appearance' => fn () => $user ? CharacterSheet::appearance($user) : null,
                'stats' => fn () => $user?->stats ? GamePresenter::stats($user->stats()->first()) : null,
                'family' => fn () => $user?->family ? ['id' => $user->family->id, 'name' => $user->family->name, 'crest' => ChronicleService::crest($user->family)] : null,
                // Titel unter dem Namen (§14.7): Level-Titel und beste Meisterschaft
                'title' => fn () => $user?->family_id ? TitleService::display($user) : null,
                // Rückmeldung beim Abhaken (§11.4): Stärke, Bewegung und Ton für alle Seiten
                'feedback' => fn () => $user ? (function () use ($user) {
                    $s = SupportService::settings($user);

                    return [
                        'level' => $s->feedback_level,
                        'reduce_motion' => $s->reduce_motion,
                        'sound' => $s->sound,
                        'haptics' => $s->haptics,
                    ];
                })() : null,
                // Darstellung und Barrierefreiheit (§11.4, Phase 8): wirkt als Klassen und Variablen am <html>
                'display' => fn () => $user ? self::display($user) : null,
            ],
            'counts' => fn () => $user?->isParent() ? [
                'approvals' => TaskCompletion::where('status', CompletionStatus::Pending)
                    ->whereHas('user', fn ($q) => $q->where('family_id', $user->family_id))->count()
                    + RewardRedemption::where('status', 'pending')
                        ->whereHas('user', fn ($q) => $q->where('family_id', $user->family_id))->count(),
                'proposals' => ProposalService::openCount($user->family_id),
            ] : ['approvals' => 0, 'proposals' => 0],
            // Freischaltungen nach Level (§13): Navigation, Freischalt-Karte, Starter-Aufgaben, nächste Freischaltung
            'features' => fn () => $user?->family_id ? FeatureView::shared($user) : null,
            // Kurze Tour (§13.3) nach „Zeig's mir“ oder aus dem Heldenbuch
            'tour' => fn () => FeatureView::tour($request, $user),
            // Neu erreichte Erfolge, deren Feier noch aussteht (auch wenn sie z. B. durch eine Freigabe entstanden sind)
            'newAchievements' => fn () => $user?->family_id ? AchievementService::unseen($user) : [],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'levelUp' => fn () => $request->session()->get('levelUp'),
                'drop' => fn () => $request->session()->get('drop'),
            ],
        ];
    }

    /** @return array{theme: string, theme_dark: bool, reduce_motion: bool, high_contrast: bool, text_scale: float, dyslexia_font: bool, color_vision: string, focus_mode_default: bool, timer_style: string} */
    public static function display(User $user): array
    {
        $s = SupportService::settings($user);
        $theme = SupportService::theme($user);

        return [
            'theme' => $theme,
            'theme_dark' => (bool) config("game.themes.$theme.dark", false),
            'reduce_motion' => (bool) $s->reduce_motion,
            'high_contrast' => (bool) $s->high_contrast,
            'text_scale' => (float) ($s->text_scale ?: 1),
            'dyslexia_font' => (bool) $s->dyslexia_font,
            'color_vision' => $s->color_vision ?: 'normal',
            'focus_mode_default' => (bool) $s->focus_mode_default,
            'timer_style' => $s->timer_style ?: 'disc',
        ];
    }
}
