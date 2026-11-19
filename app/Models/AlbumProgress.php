<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Fortschritt eines Sammelalbums (§14.7): gesammelte Einträge und ausgezahlte Prozent-Stufen. */
class AlbumProgress extends Model
{
    protected $table = 'album_progress';

    protected $fillable = ['user_id', 'album', 'collected', 'percent', 'rewarded'];

    protected $attributes = ['collected' => '[]', 'rewarded' => '[]', 'percent' => 0];

    protected function casts(): array
    {
        return [
            'collected' => 'array',
            'rewarded' => 'array',
            'percent' => 'integer',
        ];
    }
}
