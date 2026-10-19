<?php

namespace App\Models;

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
        'drop_weight', 'drop_categories', 'min_level', 'active',
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

    /** Aktive Items mit Drop-Gewicht bis zu diesem Level (Kategorie-Filter danach mit dropsFor()). */
    public function scopeDroppable(Builder $query, int $level): Builder
    {
        return $query->where('active', true)->where('drop_weight', '>', 0)->where('min_level', '<=', $level);
    }

    public function dropsFor(?string $category): bool
    {
        return empty($this->drop_categories) || ($category !== null && in_array($category, $this->drop_categories, true));
    }
}
