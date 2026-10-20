<?php

namespace App\Console\Commands;

use App\Mcp\G1b;
use App\Platform\Access\LearnerScope;
use App\Platform\Database\LearnerTables;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Reports G1b and decides nothing (docs/specs/mcp-connections-plan.md M6.7, ADR 0001).
 */
#[Signature('vistud:measure:g1b')]
#[Description('Report chat-app attempts against in-app attempts. Inconclusive until the sample is large enough.')]
class MeasureG1b extends Command
{
    public function handle(): int
    {
        $mcp = [];
        $chat = [];
        // Decrypt one learner's rows in PHP; SQL cannot inspect the content envelope.
        foreach (DB::table('learners')->pluck('id') as $learnerId) {
            $rows = LearnerTables::query(LearnerScope::forJob((string) $learnerId), 'journal_entries')
                ->get(['recorded_at', 'body']);
            foreach ($rows as $row) {
                $via = json_decode($row->body, true, flags: JSON_THROW_ON_ERROR)['judge']['via'] ?? null;
                if ($via === 'mcp') {
                    $mcp[] = (string) $row->recorded_at;
                } elseif ($via === 'chat') {
                    $chat[] = (string) $row->recorded_at;
                }
            }
        }
        $report = G1b::judge($mcp, $chat);
        $this->line('mcp attempts: '.$report['mcp']);
        $this->line('chat attempts: '.$report['chat']);
        $this->line('span: '.$report['days'].' days');
        $this->line('result: '.$report['result']);

        return self::SUCCESS;
    }
}
