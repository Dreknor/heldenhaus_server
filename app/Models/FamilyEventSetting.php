<?php

namespace App\Models;

use App\Domain\Events\EventCatalog;
use Illuminate\Database\Eloquent\Model;

/** Event-Einstellungen der Familie (§12.6): Region, abgeschaltete Events, Wetterquelle, Zufallsereignisse. */
class FamilyEventSetting extends Model
{
    protected $primaryKey = 'family_id';

    public $incrementing = false;

    protected $fillable = ['family_id', 'country', 'subdivision', 'disabled_events', 'enabled_events', 'fun_events', 'disabled_fun_events', 'enabled_fun_events',
        'weather_source', 'weather_lat', 'weather_lon'];

    protected $attributes = ['country' => 'DE', 'subdivision' => 'DE-SN', 'fun_events' => true, 'weather_source' => 'none'];

    protected function casts(): array
    {
        return [
            'disabled_events' => 'array', 'enabled_events' => 'array', 'fun_events' => 'boolean',
            'disabled_fun_events' => 'array', 'enabled_fun_events' => 'array', 'weather_lat' => 'float', 'weather_lon' => 'float',
        ];
    }

    public static function for(int $familyId): self
    {
        return self::find($familyId) ?? new self(['family_id' => $familyId]);
    }

    public function enabled(string $key): bool
    {
        $event = EventCatalog::get($key);
        if ($event === null) {
            return false;
        }

        return $event['default_enabled']
            ? ! in_array($key, $this->disabled_events ?? [], true)
            : in_array($key, $this->enabled_events ?? [], true);
    }

    public function funEnabled(string $key, bool $default = true): bool
    {
        if (! $this->fun_events) {
            return false;
        }

        return $default ? ! in_array($key, $this->disabled_fun_events ?? [], true) : in_array($key, $this->enabled_fun_events ?? [], true);
    }
}
