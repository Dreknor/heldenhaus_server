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
use App\Models\FamilyAlliance;
use App\Models\GuestbookEntry;
use App\Models\Home;
use App\Models\Room;
use App\Models\UserItem;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class HousingController extends Controller
{
    public function __construct(
        private readonly HomeService $homes,
        private readonly BuildService $builds,
        private readonly GardenService $garden,
        private readonly GuestbookService $guestbook,
    ) {}

    public function index(Request $request): Response
    {
        $home = HomeService::home((int) $request->user()->family_id);
        $data = HomeService::present($home);
        $rooms = $home->rooms->keyBy('id');
        $data['rooms'] = collect($data['rooms'])->map(fn (array $room) => $room + [
            'can_edit' => HomeService::canEdit($request->user(), $rooms[$room['id']]),
        ])->values();
        $data['guestbook'] = collect($data['guestbook'])->map(fn (array $entry) => $entry + [
            'can_delete' => $entry['author']['id'] === $request->user()->id || $request->user()->isParent(),
        ])->values();
        $furniture = UserItem::with('item')->where('user_id', $request->user()->id)->where('quantity', '>', 0)->get()
            ->filter(fn (UserItem $row) => $row->item->type === 'furniture')
            ->map(fn (UserItem $row) => [
                'id' => $row->item->id,
                'key' => $row->item->key,
                'name' => $row->item->name,
                'icon' => $row->item->icon,
                'w' => (int) ($row->item->stats['w'] ?? 1),
                'h' => (int) ($row->item->stats['h'] ?? 1),
                'layers' => $row->item->stats['layers'] ?? ['floor'],
                'quantity' => $row->quantity - $row->master_quantity,
                'master_quantity' => $row->master_quantity,
            ])->values();

        return Inertia::render('Housing', [
            'home' => $data + [
                'can_start_projects' => $request->user()->isParent(),
                'can_write_text' => true,
                'presets' => MessageService::PRESETS,
                'emojis' => MessageService::EMOJIS,
            ],
            'furniture' => $furniture,
            'visitor' => false,
            'alliedFamilies' => $this->alliedFamilies((int) $request->user()->family_id),
        ]);
    }

    public function visit(Request $request, Family $family): Response
    {
        $alliance = GuestbookService::visitAlliance($request->user(), $family->id);
        $home = Home::where('family_id', $family->id)->first() ?? HomeService::home($family->id);
        $data = HomeService::present($home, visitor: true);
        $rooms = $home->rooms->keyBy('id');
        $data['rooms'] = collect($data['rooms'])->map(fn (array $room) => $room + [
            'can_edit' => HomeService::canEdit($request->user(), $rooms[$room['id']]),
        ])->values();
        $data['guestbook'] = collect($data['guestbook'])->map(fn (array $entry) => $entry + [
            'can_delete' => $entry['author']['id'] === $request->user()->id,
        ])->values();
        $data += [
            'projects' => ['active' => [], 'available' => []],
            'garden' => null,
            'storage' => [],
            'can_start_projects' => false,
            'can_write_text' => MessageService::canUseText($request->user(), $alliance),
            'presets' => MessageService::PRESETS,
            'emojis' => MessageService::EMOJIS,
        ];

        return Inertia::render('Housing', [
            'home' => $data,
            'furniture' => [],
            'visitor' => true,
            'alliedFamilies' => $this->alliedFamilies((int) $request->user()->family_id),
        ]);
    }

    public function customize(Request $request, Room $room)
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:40'],
            'wall' => ['required', Rule::in(array_keys(config('game.housing.walls')))],
            'floor' => ['required', Rule::in(array_keys(config('game.housing.floors')))],
        ]);
        $this->homes->customize($request->user(), $room, $data);

        return back()->with('success', 'Zimmergestaltung gespeichert.');
    }

    public function layout(Request $request, Room $room)
    {
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:0'],
            'items' => ['present', 'array', 'max:'.config('game.housing.max_items_per_room')],
            'items.*.id' => ['sometimes', 'integer'],
            'items.*.item_id' => ['required_without:items.*.id', 'integer'],
            'items.*.x' => ['required', 'integer', 'min:0', 'max:63'],
            'items.*.y' => ['required', 'integer', 'min:0', 'max:63'],
            'items.*.layer' => ['sometimes', Rule::in(HomeService::LAYERS)],
            'items.*.rotation' => ['sometimes', 'integer', Rule::in(HomeService::ROTATIONS)],
            'items.*.mirrored' => ['sometimes', 'boolean'],
            'items.*.master' => ['sometimes', 'boolean'],
        ]);
        $this->homes->saveLayout($request->user(), $room, $data['version'], $data['items']);

        return back()->with('success', 'Zimmer eingerichtet.');
    }

    public function startProject(Request $request)
    {
        $data = $request->validate([
            'project' => ['required', 'string', Rule::in(array_keys(Catalog::projects()))],
            'reward_text' => ['nullable', 'string', 'max:120'],
        ]);
        $this->builds->start($request->user(), $data['project'], $data['reward_text'] ?? null);

        return back()->with('success', 'Bauprojekt gestartet.');
    }

    public function contribute(Request $request, BuildProject $project)
    {
        $data = $request->validate([
            'item' => ['nullable', 'string'],
            'amount' => ['required', 'integer', 'min:1', 'max:10000'],
            'from_storage' => ['sometimes', 'boolean'],
        ]);
        $this->builds->contribute($request->user(), $project, $data['item'] ?? null, $data['amount'], (bool) ($data['from_storage'] ?? false));

        return back()->with('success', 'Beitrag zum Bauprojekt gespeichert.');
    }

    public function cancelProject(Request $request, BuildProject $project)
    {
        $this->builds->cancel($request->user(), $project);

        return back()->with('success', 'Bauprojekt abgebrochen.');
    }

    public function gardenAction(Request $request, int $slot, string $action)
    {
        $user = $request->user();
        match ($action) {
            'sow' => $this->garden->sow($user, $slot, $request->validate([
                'plant' => ['required', 'string', Rule::in(array_keys(Catalog::plants()))],
            ])['plant']),
            'water' => $this->garden->water((int) $user->family_id, $slot, $user->gameDate()->toDateString()),
            'harvest' => $this->garden->harvest($user, $slot),
            default => abort(404),
        };

        return back()->with('success', match ($action) {
            'sow' => 'Saatgut ausgesät.',
            'water' => 'Beet gegossen.',
            'harvest' => 'Ernte eingesammelt.',
        });
    }

    public function writeGuestbook(Request $request)
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(['text', 'preset', 'emoji'])],
            'body' => ['required', 'string', 'max:300'],
        ]);
        $this->guestbook->write($request->user(), (int) $request->user()->family_id, $data['kind'], $data['body']);

        return back()->with('success', 'Eintrag im Gästebuch gespeichert.');
    }

    public function writeGuestbookTo(Request $request, Family $family)
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(['text', 'preset', 'emoji'])],
            'body' => ['required', 'string', 'max:300'],
        ]);
        $this->guestbook->write($request->user(), $family->id, $data['kind'], $data['body']);

        return back()->with('success', 'Gruß im Gästebuch hinterlassen.');
    }

    public function deleteGuestbook(Request $request, GuestbookEntry $entry)
    {
        $this->guestbook->delete($request->user(), $entry);

        return back()->with('success', 'Gästebucheintrag gelöscht.');
    }

    private function alliedFamilies(int $familyId): array
    {
        return FamilyAlliance::active()->of($familyId)->with(['family:id,name', 'partnerFamily:id,name'])->get()
            ->map(fn (FamilyAlliance $alliance) => $alliance->family_id === $familyId ? $alliance->partnerFamily : $alliance->family)
            ->filter()
            ->map(fn (Family $family) => ['id' => $family->id, 'name' => $family->name])
            ->values()->all();
    }
}
