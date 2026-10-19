<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class AppRelease extends Model
{
    protected $fillable = ['version_name', 'version_code', 'channel', 'apk_path', 'sha256', 'size', 'changelog', 'min_supported_code', 'published_at'];

    protected function casts(): array
    {
        return [
            'version_code' => 'integer',
            'size' => 'integer',
            'min_supported_code' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    /** Veröffentlichte Releases eines Kanals; „beta“ sieht auch stabile Versionen. */
    public function scopeForChannel(Builder $query, string $channel): Builder
    {
        return $query->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->whereIn('channel', $channel === 'beta' ? ['stable', 'beta'] : ['stable']);
    }

    public static function latestFor(string $channel): ?self
    {
        return static::forChannel($channel)->orderByDesc('version_code')->first();
    }

    /** Kleinste noch unterstützte App-Version (über alle stabilen Releases). */
    public static function minSupportedCode(): int
    {
        return (int) (static::forChannel('stable')->max('min_supported_code') ?? 1);
    }
}
