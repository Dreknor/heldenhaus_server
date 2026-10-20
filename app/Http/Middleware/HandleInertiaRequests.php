<?php

namespace App\Http\Middleware;

use App\Enums\CompletionStatus;
use App\Http\Presenters\CharacterSheet;
use App\Http\Presenters\GamePresenter;
use App\Models\RewardRedemption;
use App\Models\TaskCompletion;
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
                    'created_at' => $user->created_at,
                    'updated_at' => $user->updated_at,
                ] : null,
                'appearance' => fn () => $user ? CharacterSheet::appearance($user) : null,
                'stats' => fn () => $user?->stats ? GamePresenter::stats($user->stats()->first()) : null,
                'family' => fn () => $user?->family ? ['id' => $user->family->id, 'name' => $user->family->name] : null,
            ],
            'counts' => fn () => $user?->isParent() ? [
                'approvals' => TaskCompletion::where('status', CompletionStatus::Pending)
                    ->whereHas('user', fn ($q) => $q->where('family_id', $user->family_id))->count()
                    + RewardRedemption::where('status', 'pending')
                        ->whereHas('user', fn ($q) => $q->where('family_id', $user->family_id))->count(),
            ] : ['approvals' => 0],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'levelUp' => fn () => $request->session()->get('levelUp'),
                'drop' => fn () => $request->session()->get('drop'),
            ],
        ];
    }
}
