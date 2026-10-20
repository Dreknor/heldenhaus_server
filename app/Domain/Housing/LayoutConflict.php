<?php

namespace App\Domain\Housing;

use App\Domain\Gameplay\GameException;
use App\Models\Room;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Jemand anderes hat das Zimmer inzwischen umgeräumt – der Editor bekommt den aktuellen Stand (409). */
class LayoutConflict extends GameException
{
    public function __construct(public readonly Room $room)
    {
        parent::__construct('Das Zimmer wurde gerade woanders umgeräumt. Hier ist der neue Stand.');
    }

    public function render(Request $request): ?JsonResponse
    {
        return $request->expectsJson()
            ? response()->json(['message' => $this->getMessage(), 'room' => HomeService::presentRoom($this->room)], 409)
            : null;
    }
}
