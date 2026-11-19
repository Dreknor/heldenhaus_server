<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Events\EventCalendar;
use App\Domain\Events\EventCatalog;
use App\Domain\Events\EventTrigger;
use App\Domain\Events\FunEventService;
use App\Domain\Gameplay\GameException;
use App\Http\Controllers\Controller;
use App\Models\EventOverride;
use App\Models\Family;
use App\Models\FamilyEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin-Backend für Events (§12.6): Inhalte aus events.json und fun_events.json bearbeiten (als Overrides in der
 * Datenbank, die Dateien bleiben unverändert), ein Event für eine Familie starten und den Jahreskalender ansehen.
 */
class EventController extends Controller
{
    public const SUBDIVISIONS = [
        'DE-BW' => 'Baden-Württemberg', 'DE-BY' => 'Bayern', 'DE-BE' => 'Berlin', 'DE-BB' => 'Brandenburg',
        'DE-HB' => 'Bremen', 'DE-HH' => 'Hamburg', 'DE-HE' => 'Hessen', 'DE-MV' => 'Mecklenburg-Vorpommern',
        'DE-NI' => 'Niedersachsen', 'DE-NW' => 'Nordrhein-Westfalen', 'DE-RP' => 'Rheinland-Pfalz', 'DE-SL' => 'Saarland',
        'DE-SN' => 'Sachsen', 'DE-ST' => 'Sachsen-Anhalt', 'DE-SH' => 'Schleswig-Holstein', 'DE-TH' => 'Thüringen',
    ];

    private const CATEGORIES = ['calendar', 'game', 'family', 'world'];

    private const FUN_CONTEXTS = ['score', 'score_household', 'first_task', 'last_task', 'level_up', 'echo', 'monthly_hour',
        'boss_defeated', 'ha_rain', 'ha_wind', ...FunEventService::CONTEXTS];

    public function index(Request $request): Response
    {
        $region = array_key_exists((string) $request->query('region'), self::SUBDIVISIONS) ? (string) $request->query('region') : 'DE-SN';
        $year = (int) CarbonImmutable::now('Europe/Berlin')->year;
        $eventOverrides = EventCatalog::overrides('event');
        $funOverrides = EventCatalog::overrides('fun');
        $running = FamilyEvent::running()->where('ends_at', '>', now())->selectRaw('event_key, count(*) as n')->groupBy('event_key')->pluck('n', 'event_key');

        return Inertia::render('admin/Events', [
            'events' => collect(EventCatalog::all())->map(fn (array $e) => [
                'key' => $e['key'],
                'definition' => $e,
                'base' => EventCatalog::baseAll()[$e['key']],
                'overridden' => array_keys($eventOverrides[$e['key']] ?? []),
                'running' => (int) ($running[$e['key']] ?? 0),
            ])->values(),
            'fun' => collect(EventCatalog::fun())->map(fn (array $f) => [
                'key' => $f['key'],
                'definition' => $f,
                'base' => EventCatalog::baseFun()[$f['key']],
                'overridden' => array_keys($funOverrides[$f['key']] ?? []),
            ])->values(),
            'families' => Family::orderBy('name')->get(['id', 'name'])->map(fn (Family $f) => [
                'id' => $f->id,
                'name' => $f->name,
                'members' => User::where('family_id', $f->id)->orderBy('name')->get(['id', 'name'])->map->only(['id', 'name'])->values(),
            ]),
            'region' => $region,
            'subdivisions' => self::SUBDIVISIONS,
            'calendar' => self::calendar($region, [$year, $year + 1]),
        ]);
    }

    /** Inhalte speichern: nur die Felder, die von der Datei abweichen, landen in event_overrides. */
    public function update(Request $request, string $kind, string $key): RedirectResponse
    {
        abort_unless(in_array($kind, EventOverride::KINDS, true), 404);
        $base = ($kind === 'event' ? EventCatalog::baseAll() : EventCatalog::baseFun())[$key] ?? abort(404);
        $data = $request->validate(['definition' => ['required', 'array']]);
        $definition = ['key' => $key] + $data['definition'];

        $kind === 'event' ? $this->validateEvent($definition, $base) : $this->validateFun($definition, $base);

        // Locker vergleichen: 1 und 1.0 aus dem Formular gelten als unverändert
        $changed = collect($definition)->except('key')->filter(fn ($value, $field) => ! array_key_exists($field, $base) || $base[$field] != $value)->all();
        if ($changed === []) {
            EventOverride::where('kind', $kind)->where('key', $key)->delete();
        } else {
            EventOverride::updateOrCreate(['kind' => $kind, 'key' => $key], ['data' => $changed, 'updated_by' => $request->user()->id]);
        }
        EventCatalog::flush();

        return back()->with('success', "„{$definition['name']}“ gespeichert.");
    }

    /** Admin-Änderungen verwerfen, es gilt wieder die Datei. */
    public function destroy(string $kind, string $key): RedirectResponse
    {
        abort_unless(in_array($kind, EventOverride::KINDS, true), 404);
        EventOverride::where('kind', $kind)->where('key', $key)->delete();
        EventCatalog::flush();

        return back()->with('success', 'Zurückgesetzt auf den Stand der Inhaltsdatei.');
    }

    /** „Jetzt für Familie X starten“ (zum Testen, z. B. auf Staging). */
    public function start(Request $request, EventTrigger $trigger): RedirectResponse
    {
        $data = $request->validate([
            'family_id' => ['required', 'integer', Rule::exists('families', 'id')],
            'key' => ['required', 'string', Rule::in(array_keys(EventCatalog::all()))],
            'subject_user_id' => ['nullable', 'integer'],
        ], [], ['family_id' => 'Familie', 'key' => 'Event']);

        $def = EventCatalog::get($data['key']);
        $subject = null;
        if (($def['personal'] ?? false) || $data['key'] === 'welcome_party') {
            $subject = User::where('family_id', $data['family_id'])->find($data['subject_user_id'] ?? 0)
                ?? throw ValidationException::withMessages(['subject_user_id' => 'Für wen soll das Event sein? Bitte ein Familienmitglied wählen.']);
        }

        $event = $trigger->consider((int) $data['family_id'], $data['key'], ['manual' => true, 'subject_user_id' => $subject?->id, 'source' => 'admin'])
            ?? throw new GameException('Das Event läuft in dieser Familie schon oder kann gerade nicht starten.');
        $family = Family::find($data['family_id']);

        return back()->with('success', $event->status === 'scheduled'
            ? "{$def['icon']} {$def['name']} wartet bei {$family->name}, bis Platz ist."
            : "{$def['icon']} {$def['name']} läuft jetzt bei {$family->name}.");
    }

    /**
     * Jahreskalender (§12.3): wann welches Kalender-Event im Bundesland liegt, inkl. Osterformel.
     *
     * @param  list<int>  $years
     */
    public static function calendar(string $subdivision, array $years): array
    {
        return collect($years)->map(fn (int $year) => [
            'year' => $year,
            'easter' => EventCalendar::easter($year)->toDateString(),
            'events' => collect(EventCatalog::calendar())->map(function (array $e) use ($year, $subdivision) {
                $window = EventCalendar::window($e, $year, 'DE', $subdivision);

                return [
                    'key' => $e['key'], 'name' => $e['name'], 'icon' => $e['icon'], 'trigger' => $e['trigger']['type'],
                    'default_enabled' => (bool) $e['default_enabled'],
                    'from' => $window ? $window[0]->toDateString() : null, 'to' => $window ? $window[1]->toDateString() : null,
                ];
            })->sortBy(fn ($e) => $e['from'] ?? '9999')->values(),
        ])->all();
    }

    private function validateEvent(array $d, array $base): void
    {
        $validator = Validator::make($d, [
            'name' => ['required', 'string', 'max:60'],
            'icon' => ['required', 'string', 'max:16'],
            'category' => ['required', Rule::in([$base['category']])],
            'description' => ['required', 'string', 'max:500'],
            'variants' => ['nullable', 'array'],
            'variants.child' => ['nullable', 'string', 'max:500'],
            'variants.teen' => ['nullable', 'string', 'max:500'],
            'trigger' => ['required', 'array'],
            'trigger.type' => ['required', Rule::in([$base['trigger']['type']])],
            'duration_days' => ['required', 'integer', 'between:1,60'],
            'cooldown_days' => ['required', 'integer', 'between:0,365'],
            'priority' => ['required', 'integer', 'between:0,1000'],
            'default_enabled' => ['required', 'boolean'],
            'requires_feature' => ['nullable', 'string', 'max:32'],
            'requires_ha' => ['nullable', 'boolean'],
            'goal' => ['nullable', 'array'],
            'goal.tiers' => ['nullable', 'array', 'max:6'],
            'goal.tiers.*.percent' => ['required', 'integer', 'between:1,100'],
            'goal.tiers.*.rewards' => ['nullable', 'array'],
            'goal.tiers.*.rewards.stars' => ['prohibited'],
            'modifiers' => ['nullable', 'array'],
            'drops' => ['nullable', 'array'],
            'drops.*' => ['integer', 'min:0'],
            'recipes' => ['nullable', 'array'],
            'recipes.*' => ['string'],
            'decor' => ['nullable', 'array'],
            'decor.colors' => ['nullable', 'array', 'max:4'],
            'decor.colors.*' => ['string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'decor.items' => ['nullable', 'array', 'max:6'],
            'decor.items.*' => ['string', 'max:60'],
            'returns_in_years' => ['nullable', 'integer', 'between:0,10'],
        ], [
            'category.in' => 'Die Kategorie lässt sich nicht ändern.',
            'trigger.type.in' => 'Die Art des Auslösers lässt sich nicht ändern.',
            'goal.tiers.*.rewards.stars.prohibited' => 'Events vergeben keine Sterne (§12.1).',
        ], ['name' => 'Name', 'icon' => 'Symbol', 'description' => 'Beschreibung', 'duration_days' => 'Dauer', 'cooldown_days' => 'Abklingzeit']);
        $validator->after(function ($v) use ($d) {
            if (($d['category'] ?? null) !== 'calendar' || $v->errors()->isNotEmpty()) {
                return;
            }
            try {
                EventCalendar::window($d, (int) now()->year);
            } catch (\Throwable) {
                $v->errors()->add('trigger', 'Mit diesem Auslöser lässt sich kein Datum berechnen.');
            }
        });
        $this->throwFor($validator);
    }

    private function validateFun(array $d, array $base): void
    {
        $validator = Validator::make($d, [
            'name' => ['required', 'string', 'max:60'],
            'icon' => ['required', 'string', 'max:16'],
            'scope' => ['required', Rule::in([$base['scope'] ?? 'user'])],
            'action' => ['required', Rule::in([$base['action'] ?? 'none'])],
            'contexts' => ['required', 'array', 'min:1'],
            'contexts.*' => [Rule::in(self::FUN_CONTEXTS)],
            'chance' => ['required', 'numeric', 'between:0,1'],
            'cooldown_hours' => ['required', 'integer', 'between:0,2000'],
            'minutes' => ['required', 'integer', 'between:1,2880'],
            'calm' => ['required', 'boolean'],
            'default_enabled' => ['required', 'boolean'],
            'first_wins' => ['required', 'boolean'],
            'hours' => ['nullable', 'array', 'size:2'],
            'hours.*' => ['integer', 'between:0,24'],
            'text' => ['required', 'array'],
            'text.child' => ['required', 'string', 'max:300'],
            'text.teen' => ['nullable', 'string', 'max:300'],
            'text.adult' => ['nullable', 'string', 'max:300'],
            'reward' => ['nullable', 'array'],
            'reward.stars' => ['prohibited'],
            'reward.gold' => ['nullable', 'integer', 'between:0,10'],
            'reward.items' => ['nullable', 'array', 'max:3'],
            'reward.items.*' => ['integer', 'between:1,3'],
        ], [
            'scope.in' => 'Für wen das Ereignis gilt, lässt sich nicht ändern.',
            'action.in' => 'Die Aktion lässt sich nicht ändern.',
            'reward.stars.prohibited' => 'Zufallsereignisse geben nie Sterne (§12.5).',
            'reward.gold.between' => 'Höchstens 10 Gold – die Belohnung bleibt winzig.',
        ], ['name' => 'Name', 'icon' => 'Symbol', 'chance' => 'Chance', 'cooldown_hours' => 'Abklingzeit', 'minutes' => 'Dauer']);
        $this->throwFor($validator);
    }

    /** Fehler unter definition.* zurückgeben, damit das Formular sie den Feldern zuordnet. */
    private function throwFor(\Illuminate\Validation\Validator $validator): void
    {
        if ($validator->fails()) {
            throw ValidationException::withMessages(collect($validator->errors()->messages())
                ->mapWithKeys(fn ($messages, $field) => ["definition.{$field}" => $messages])->all());
        }
    }
}
