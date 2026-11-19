<?php

namespace App\Domain\Council;

use App\Models\User;

/**
 * Aufgaben-Vorlagen nach Alter (§14.9) aus database/content/task_templates.json:
 * Bereich, Altersstufen (9-10 … 15-16), Typ, Schwierigkeit, Kategorie, Sterne-Vorschlag, Schritte, Anker.
 */
class TaskTemplates
{
    public const AGES = ['9-10', '11-12', '13-14', '15-16'];

    public const AREAS = [
        'room' => 'Zimmer',
        'kitchen' => 'Küche',
        'laundry' => 'Wäsche',
        'pets' => 'Tiere',
        'school' => 'Schule',
        'selfcare' => 'Selbstfürsorge',
    ];

    /** @return array<string, array> */
    public static function all(): array
    {
        static $templates;

        return $templates ??= collect(json_decode(file_get_contents(database_path('content/task_templates.json')), true, flags: JSON_THROW_ON_ERROR))
            ->keyBy('key')->all();
    }

    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /** Altersstufe der Vorlagen für eine Person (Erwachsene: keine Einschränkung). */
    public static function ageBand(?User $user): ?string
    {
        $age = $user?->age();

        return match (true) {
            $age === null || $age > 17 => null,
            $age <= 10 => '9-10',
            $age <= 12 => '11-12',
            $age <= 14 => '13-14',
            default => '15-16',
        };
    }

    /** @return list<array> gefiltert nach Altersstufe und Bereich */
    public static function filter(?string $age = null, ?string $area = null): array
    {
        return collect(self::all())
            ->when($age, fn ($c) => $c->filter(fn ($t) => in_array($age, $t['ages'], true)))
            ->when($area, fn ($c) => $c->where('area', $area))
            ->values()->all();
    }

    /** Daten für TaskService::create aus einer Vorlage. */
    public static function taskData(array $template): array
    {
        $type = $template['type'];

        return array_filter([
            'type' => $type,
            'title' => $template['title'],
            'category' => $template['category'],
            'difficulty' => (float) $template['difficulty'],
            'notes' => ! empty($template['anchor']) ? 'Wann: '.$template['anchor'] : null,
            'checklist' => in_array($type, ['daily', 'todo'], true) && ! empty($template['steps']) ? array_values($template['steps']) : null,
            'repeat' => $type === 'daily' ? ['type' => 'daily', 'every' => 1] : null,
            'up' => $type === 'habit' ? true : null,
            'down' => $type === 'habit' ? false : null,
        ], fn ($v) => $v !== null);
    }

    public static function present(array $template): array
    {
        return [
            'key' => $template['key'],
            'area' => $template['area'],
            'area_label' => self::AREAS[$template['area']] ?? $template['area'],
            'ages' => $template['ages'],
            'title' => $template['title'],
            'type' => $template['type'],
            'difficulty' => (float) $template['difficulty'],
            'category' => $template['category'],
            'stars' => (int) $template['stars'],
            'icon' => $template['icon'],
            'steps' => $template['steps'] ?? [],
            'anchor' => $template['anchor'] ?? null,
        ];
    }
}
