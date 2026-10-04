<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Project tools (docs/specs/vistud-2-blueprint.md Phase 6): labels, a team, priorities, milestones and how an assignment is
 * going are for a big or group assignment, and a student rarely needs them, so a course shows them only when it says so. Off
 * for a new course. A course that already uses any of them (a project assignment, a team, a milestone, a priority, labels or
 * a person on an item of a plan) is switched on here, so nothing a student has set goes out of sight.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->boolean('project_tools')->default(false)->after('type');
        });

        $using = DB::table('activities')->where('kind', 'project')->pluck('workspace_id')
            ->merge(DB::table('activity_members')->pluck('workspace_id'))
            ->merge(DB::table('activity_items')->where('kind', 'milestone')->pluck('workspace_id'))
            ->merge(DB::table('activity_items')->where(fn ($query) => $query->whereNotNull('priority')->orWhereNotNull('member_id'))->pluck('workspace_id'))
            // Labels are a JSON list; an empty one doesn't count.
            ->merge(DB::table('activity_items')->whereNotNull('labels')->get(['workspace_id', 'labels'])
                ->filter(fn ($row) => (json_decode($row->labels, true) ?: []) !== [])->pluck('workspace_id'))
            ->unique()->values();

        foreach ($using->chunk(500) as $ids) {
            DB::table('workspaces')->whereIn('id', $ids->all())->update(['project_tools' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn('project_tools');
        });
    }
};
