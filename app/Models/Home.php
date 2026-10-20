<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Familienhaus (Konzept §2.6). */
class Home extends Model
{
    public const STAGES = ['hut' => 'Hütte', 'timber' => 'Fachwerkhaus', 'manor' => 'Gutshof', 'castle' => 'Burg'];

    protected $fillable = ['family_id', 'stage', 'stations'];

    protected function casts(): array
    {
        return ['stations' => 'array'];
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }

    public function station(string $key): int
    {
        return (int) ($this->stations[$key] ?? 0);
    }

    public function stageAtLeast(string $stage): bool
    {
        $order = array_keys(self::STAGES);

        return array_search($this->stage, $order, true) >= array_search($stage, $order, true);
    }
}
