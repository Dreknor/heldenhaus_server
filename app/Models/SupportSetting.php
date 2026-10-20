<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupportSetting extends Model
{
    protected $table = 'user_support_settings';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $fillable = [
        'user_id', 'gentle_mode', 'focus_mode_default', 'feedback_level', 'reduce_motion', 'sound', 'haptics',
        'timer_style', 'max_steps', 'low_energy_per_week', 'ai_steps', 'set_by',
        'theme', 'high_contrast', 'text_scale', 'dyslexia_font', 'color_vision',
    ];

    protected $attributes = [
        'focus_mode_default' => false,
        'feedback_level' => 'full',
        'reduce_motion' => false,
        'sound' => true,
        'haptics' => true,
        'timer_style' => 'disc',
        'max_steps' => 7,
        'low_energy_per_week' => 2,
        'ai_steps' => false,
        'high_contrast' => false,
        'text_scale' => 1,
        'dyslexia_font' => false,
        'color_vision' => 'normal',
    ];

    protected function casts(): array
    {
        return [
            'gentle_mode' => 'boolean',
            'focus_mode_default' => 'boolean',
            'reduce_motion' => 'boolean',
            'sound' => 'boolean',
            'haptics' => 'boolean',
            'max_steps' => 'integer',
            'low_energy_per_week' => 'integer',
            'ai_steps' => 'boolean',
            'high_contrast' => 'boolean',
            'text_scale' => 'float',
            'dyslexia_font' => 'boolean',
        ];
    }
}
