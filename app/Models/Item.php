<?php

namespace App\Models;

use App\Domain\Events\EventEngine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Item extends Model
{
    public const TYPES = [
        'egg' => 'Ei',
        'hatching_potion' => 'Schlüpfpulver',
        'food' => 'Futter',
        'material' => 'Material',
        'potion' => 'Trank',
        'equipment' => 'Ausrüstung',
        'furniture' => 'Möbel',
    ];

    public const SLOTS = ['head' => 'Kopf', 'armor' => 'Rüstung', 'weapon' => 'Waffe', 'shield' => 'Schild', 'accessory' => 'Accessoire'];

    public const RARITIES = ['common' => 'Gewöhnlich', 'uncommon' => 'Ungewöhnlich', 'rare' => 'Selten', 'epic' => 'Episch', 'legendary' => 'Legendär'];

    protected $fillable = [
        'key', 'name', 'description', 'type', 'slot', 'rarity', 'icon', 'stats', 'price_gold',
        'drop_weight', 'drop_categories', 'min_level', 'event_key', 'active',
    ];

    protected $attributes = ['active' => true, 'rarity' => 'common', 'drop_weight' => 0, 'min_level' => 1, 'icon' => '📦'];

    protected function casts(): array
    {
        return [
            'stats' => 'array',
            'drop_categories' => 'array',
            'price_gold' => 'integer',
            'drop_weight' => 'integer',
            'min_level' => 'integer',
            'active' => 'boolean',
        ];
    }

    /** Aktive Items mit Drop-Gewicht bis zu diesem Level (Kategorie-Filter danach mit dropsFor()), Event-Items nur im laufenden Event. */
    public function scopeDroppable(Builder $query, int $level, ?int $familyId = null): Builder
    {
        return $query->where('active', true)->where('drop_weight', '>', 0)->where('min_level', '<=', $level)->availableFor($familyId);
    }

    /** Ohne Event oder ihr Event läuft gerade in dieser Familie (§12.2). */
    public function scopeAvailableFor(Builder $query, ?int $familyId): Builder
    {
        return $query->where(fn (Builder $q) => $q->whereNull('event_key')->orWhereIn('event_key', EventEngine::activeKeys($familyId)));
    }

    public function availableFor(?int $familyId): bool
    {
        return $this->event_key === null || EventEngine::isRunning($familyId, $this->event_key);
    }

    public function dropsFor(?string $category): bool
    {
        return empty($this->drop_categories) || ($category !== null && in_array($category, $this->drop_categories, true));
    }
}
