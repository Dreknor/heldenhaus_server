<?php

namespace App\Domain\Features;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Eine noch nicht freigeschaltete Funktion (§13.4): 403 mit dem Level, ab dem sie kommt. */
class FeatureLocked extends \RuntimeException
{
    public function __construct(public readonly string $feature, public readonly int $level)
    {
        parent::__construct("Das schaltest du mit Level {$level} frei.");
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['error' => 'feature_locked', 'feature' => $this->feature, 'unlocks_at_level' => $this->level, 'message' => $this->getMessage()], 403);
        }

        return back()->withErrors(['game' => $this->getMessage()]);
    }
}
