<?php

namespace App\Mcp;

/**
 * The in-app tools ViStud's MCP server does not offer, each with its reason (docs/specs/mcp-connections-plan.md §3.1).
 * `Architecture/McpAdapterTest` fails when a tool is neither offered (App\Mcp\ToolInfo) nor listed here, so a new
 * in-app tool is never left out silently.
 */
final class Excluded
{
    /** @var array<string, string> tool name => why it is not offered */
    public const TOOLS = [
        'plot_graph' => 'The chat apps draw graphs themselves.',
        'calculate' => 'The chat apps calculate themselves.',
        'create_snippet' => 'The chat apps show copyable code themselves.',
        'delete_topic' => 'No deletes over MCP (decision 4).',
        'set_topic' => 'Replaced by set_session_topic, which takes a topic_id and never creates a topic (M6.3).',
        'set_stage' => 'Assignment stages are moved in the assignment companion.',
        'save_assignment_plan' => 'Assignment plans are edited in the assignment companion.',
        'add_assignment_source' => 'Assignment sources are added in the assignment companion.',
        'confirm_assignment_understanding' => 'The student confirms an assignment in the assignment companion.',
        'update_assignment_plan_item' => 'Assignment plans are edited in the assignment companion.',
        'assignment_source' => 'Read in the assignment companion, beside its plan.',
        'edit_note' => 'Only its whole-note replace is offered, as replace_note, confirmed on a ViStud page.',
    ];
}
