<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

class NotificationSetting extends Model
{
    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $fillable = ['user_id', 'push_enabled', 'quiet_start', 'quiet_end', 'muted'];

    protected $attributes = ['push_enabled' => true];

    protected function casts(): array
    {
        return ['push_enabled' => 'boolean', 'muted' => 'array'];
    }

    /** Ruhezeit in der Zeitzone der Person – auch über Mitternacht (20:00–07:00). */
    public function isQuiet(CarbonImmutable $localNow): bool
    {
        if (! $this->quiet_start || ! $this->quiet_end || $this->quiet_start === $this->quiet_end) {
            return false;
        }

        $now = $localNow->format('H:i');

        return $this->quiet_start < $this->quiet_end
            ? $now >= $this->quiet_start && $now < $this->quiet_end
            : $now >= $this->quiet_start || $now < $this->quiet_end;
    }

    public function allows(string $type): bool
    {
        return $this->push_enabled && ! in_array($type, $this->muted ?? [], true);
    }
}
