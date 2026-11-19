<?php

namespace App\Domain\Insights;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lesender Zugriff auf Ziele (§14.1) und gemeisterte Gewohnheiten (§14.3) für Rückblick und Kennzahlen.
 * Beides entsteht in einem eigenen Modul (app/Domain/Goals); hier wird nur defensiv über das Schema
 * gelesen, damit Rückblick und Kennzahlen auch ohne diese Tabellen bzw. Spalten funktionieren.
 */
class GoalData
{
    /** @var array<string, list<string>> */
    private static array $columns = [];

    public static function available(): bool
    {
        return Schema::hasTable('goals') && self::has('goals', 'user_id') && self::has('goals', 'title') && self::dateColumn() !== null;
    }

    /**
     * Erreichte Ziele einer Person im Zeitraum (lokale Spieltage).
     *
     * @return list<array{title: string, kind: ?string, private: bool}>
     */
    public static function reached(User $user, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if (! self::available()) {
            return [];
        }

        $date = self::dateColumn();
        $kind = collect(['kind', 'type'])->first(fn ($c) => self::has('goals', $c));
        $private = collect(['private', 'is_private'])->first(fn ($c) => self::has('goals', $c));

        return DB::table('goals')->where('user_id', $user->id)
            ->whereNotNull($date)
            ->where($date, '>=', ActivityScores::utcStart($user, $from))
            ->where($date, '<', ActivityScores::utcStart($user, $to->addDay()))
            ->orderBy($date)
            ->get()
            ->map(fn ($g) => [
                'title' => (string) $g->title,
                'kind' => $kind ? ($g->{$kind} ?? null) : null,
                'private' => $private ? (bool) $g->{$private} : false,
            ])->values()->all();
    }

    /** Zahl gemeisterter Gewohnheiten (tasks.mastered_at), null ohne diese Spalte. */
    public static function mastered(User $user): ?int
    {
        if (! self::has('tasks', 'mastered_at')) {
            return null;
        }

        return DB::table('tasks')
            ->join('task_assignments', 'task_assignments.task_id', '=', 'tasks.id')
            ->where('task_assignments.user_id', $user->id)
            ->whereNotNull('tasks.mastered_at')
            ->count();
    }

    public static function hasMasteredColumn(): bool
    {
        return self::has('tasks', 'mastered_at');
    }

    private static function dateColumn(): ?string
    {
        return collect(['achieved_at', 'reached_at', 'completed_at', 'done_at'])->first(fn ($c) => self::has('goals', $c));
    }

    private static function has(string $table, string $column): bool
    {
        if (! isset(self::$columns[$table])) {
            self::$columns[$table] = Schema::hasTable($table) ? Schema::getColumnListing($table) : [];
        }

        return in_array($column, self::$columns[$table], true);
    }

    /** Für Tests: Spalten neu einlesen. */
    public static function flush(): void
    {
        self::$columns = [];
    }
}
