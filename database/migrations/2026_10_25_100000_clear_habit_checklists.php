<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Gewohnheiten haben keine Schritte (§11.2) – alte Checklisten entfernen. */
    public function up(): void
    {
        $ids = DB::table('tasks')->where('type', 'habit')->whereNotNull('checklist')->pluck('id');
        DB::table('tasks')->whereIn('id', $ids)->update(['checklist' => null]);
        DB::table('task_assignments')->whereIn('task_id', $ids)->update(['checklist_done' => null]);
    }

    public function down(): void {}
};
