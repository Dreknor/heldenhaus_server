<?php

namespace App\Http\Controllers;

use App\Domain\Crafting\Catalog;
use App\Domain\Housing\BuildService;
use App\Domain\Housing\GardenService;
use App\Domain\Housing\GuestbookService;
use App\Domain\Housing\HomeService;
use App\Domain\Social\MessageService;
use App\Models\BuildProject;
use App\Models\Family;
use App\Models\GuestbookEntry;
use App\Models\Home;
use App\Models\Room;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Familienhaus, Zimmer-Editor, Bauprojekte, Garten, Besuche und Gästebuch (Konzept §2.6, API §5.4). */
class HomeController extends Controller
{
    public function __construct(
        private readonly HomeService $homes,
        private readonly BuildService $builds,
        private readonly GardenService $garden,
        private readonly GuestbookService $guestbook,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $home = HomeService::home($user->family_id);

        return response()->json(HomeService::present($home) + [
            'can_start_projects' => $user->isParent(),
            'presets' => MessageService::PRESETS,
            'emojis' => MessageService::EMOJIS,
        ]);
    }

    /** Besuch bei einer Bündnis-Familie: nur ansehen. */
    public function visit(Request $request, Family $family): JsonResponse
    {
        $alliance = GuestbookService::visitAlliance($request->user(), $family->id);
        $home = Home::where('family_id', $family->id)->first() ?? HomeService::home($family->id);

        return response()->json(HomeService::present($home, visitor: $alliance !== null) + [
            'can_write_text' => MessageService::canUseText($request->user(), $alliance),
        ]);
    }

    public function room(Request $request, Room $room): JsonResponse
    {
        $room->loadMissing('home');
        GuestbookService::visitAlliance($request->user(), $room->home->family_id);

        return response()->json(HomeService::presentRoom($room) + ['can_edit' => HomeService::canEdit($request->user(), $room)]);
    }

    public function layout(Request $request, Room $room): JsonResponse
    {
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:0'],
            'items' => ['present', 'array'],
            'items.*.id' => ['sometimes', 'integer'],
            'items.*.item_id' => ['required_without:items.*.id', 'integer'],
            'items.*.x' => ['required', 'integer', 'min:0', 'max:63'],
            'items.*.y' => ['required', 'integer', 'min:0', 'max:63'],
            'items.*.layer' => ['sometimes', Rule::in(HomeService::LAYERS)],
            'items.*.rotation' => ['sometimes', 'integer', Rule::in(HomeService::ROTATIONS)],
            'items.*.mirrored' => ['sometimes', 'boolean'],
            'items.*.master' => ['sometimes', 'boolean'],
        ]);
        $room = $this->homes->saveLayout($request->user(), $room, $data['version'], $data['items']);

        return response()->json(HomeService::presentRoom($room));
    }

    // ---------- Bauprojekte ----------

    public function startProject(Request $request): JsonResponse
    {
        $data = $request->validate([
            'project' => ['required', 'string', Rule::in(array_keys(Catalog::projects()))],
            'reward_text' => ['nullable', 'string', 'max:120'],
        ]);
        $build = $this->builds->start($request->user(), $data['project'], $data['reward_text'] ?? null);

        return response()->json(['id' => $build->id, 'status' => $build->status], 201);
    }

    public function contribute(Request $request, BuildProject $project): JsonResponse
    {
        $data = $request->validate([
            'item' => ['nullable', 'string'],          // null/fehlend = Gold
            'amount' => ['required', 'integer', 'min:1', 'max:10000'],
            'from_storage' => ['sometimes', 'boolean'],
        ]);
        $build = $this->builds->contribute($request->user(), $project, $data['item'] ?? null, $data['amount'], (bool) ($data['from_storage'] ?? false));

        return response()->json(['id' => $build->id, 'status' => $build->status, 'progress' => $build->progress, 'finishes_at' => $build->finishes_at?->toIso8601String()]);
    }

    public function cancelProject(Request $request, BuildProject $project): JsonResponse
    {
        $this->builds->cancel($request->user(), $project);

        return response()->json(['ok' => true]);
    }

    // ---------- Garten ----------

    public function gardenAction(Request $request, int $slot, string $action): JsonResponse
    {
        $user = $request->user();
        $result = match ($action) {
            'sow' => $this->garden->sow($user, $slot, $request->validate(['plant' => ['required', 'string']])['plant']),
            'water' => $this->garden->water((int) $user->family_id, $slot, $user->gameDate()->toDateString()),
            'harvest' => ['harvest' => $this->garden->harvest($user, $slot)],
        };

        return response()->json(is_array($result) ? $result : ['slot' => $result->slot, 'stage' => GardenService::stage($result)]);
    }

    // ---------- Gästebuch ----------

    public function writeGuestbook(Request $request, Family $family): JsonResponse
    {
        $data = $request->validate(['kind' => ['required', Rule::in(['text', 'preset', 'emoji'])], 'body' => ['required', 'string', 'max:300']]);
        $entry = $this->guestbook->write($request->user(), $family->id, $data['kind'], $data['body']);

        return response()->json(GuestbookService::presentEntry($entry->load('user')), 201);
    }

    public function deleteGuestbook(Request $request, GuestbookEntry $entry): JsonResponse
    {
        $this->guestbook->delete($request->user(), $entry);

        return response()->json(['ok' => true]);
    }
}
