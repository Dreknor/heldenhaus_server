<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemUpdate extends Model
{
    protected $fillable = [
        'from_version', 'to_version', 'channel', 'status', 'step', 'manifest', 'log', 'package_path',
        'release_path', 'previous_release_path', 'backup_path', 'started_by', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'manifest' => 'array',
            'log' => 'array',
            'finished_at' => 'datetime',
        ];
    }

    public function isFinished(): bool
    {
        return in_array($this->status, ['done', 'failed', 'rolled_back'], true);
    }

    public function addLog(string $message, string $level = 'info'): void
    {
        $log = $this->log ?? [];
        $log[] = ['at' => now()->toIso8601String(), 'level' => $level, 'message' => $message];
        $this->log = $log;
    }
}
