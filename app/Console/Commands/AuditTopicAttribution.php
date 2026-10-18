<?php

namespace App\Console\Commands;

use App\Brain\Writer\JournalWriter;
use App\Platform\Access\LearnerScope;
use App\Platform\Database\LearnerTables;
use App\Platform\Ids;
use App\Study\Capture;
use App\Study\Memory;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Lists topics whose status_by is 'student' and whose status matches a status-kind fingerprint in study_sessions.captured
 * (docs/specs/mcp-connections-plan.md §4 M6.0). Confirmed rows get status_by = 'accepted' and a journal amendment
 * re-attributing the observation's origin to 'reported', never rewriting stored journal rows.
 */
#[Signature('vistud:topics:attribution-audit {--confirm-all : Confirm every matched topic without asking} {--skip-all : List matched topics without changing anything}')]
#[Description('Review legacy student topic statuses that match an accepted AI proposal and re-attribute confirmed rows')]
class AuditTopicAttribution extends Command
{
    public function handle(JournalWriter $journal): int
    {
        $confirmAll = (bool) $this->option('confirm-all');
        $skipAll = (bool) $this->option('skip-all');
        $found = 0;
        $confirmed = 0;

        foreach (DB::table('learners')->orderBy('id')->pluck('id') as $learnerId) {
            $scope = LearnerScope::forJob((string) $learnerId);
            $candidates = self::candidates($scope);

            foreach ($candidates as $topic) {
                $found++;
                $this->line("Topic \"{$topic->name}\" ({$topic->id}): status \"{$topic->status}\" matches a captured session proposal.");

                if ($skipAll) {
                    continue;
                }
                if (! $confirmAll && ! $this->confirm("Mark \"{$topic->name}\" ({$topic->id}) as accepted?", true)) {
                    continue;
                }

                self::reattribute($scope, $journal, (string) $topic->id);
                $confirmed++;
            }
        }

        if ($found === 0) {
            $this->info('No topics need re-attribution.');

            return self::SUCCESS;
        }

        $this->info("Confirmed {$confirmed} of {$found} ".($found === 1 ? 'topic' : 'topics').'.');

        return self::SUCCESS;
    }

    /** @return list<object> */
    private static function candidates(LearnerScope $scope): array
    {
        $topics = LearnerTables::query($scope, 'topics')
            ->whereNull('retired_at')
            ->where('status_by', 'student')
            ->whereIn('status', Capture::STATUSES)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'workspace_id', 'name', 'status']);

        if ($topics->isEmpty()) {
            return [];
        }

        $capturedByWorkspace = [];
        foreach (LearnerTables::query($scope, 'study_sessions')->whereNotNull('captured')->get(['workspace_id', 'captured']) as $session) {
            $list = is_string($session->captured) ? json_decode($session->captured, true) : null;
            if (is_array($list)) {
                foreach ($list as $fp) {
                    if (is_string($fp) && $fp !== '') {
                        $capturedByWorkspace[(string) $session->workspace_id][$fp] = true;
                    }
                }
            }
        }

        $matched = [];
        foreach ($topics as $topic) {
            $fp = Capture::fingerprint(['kind' => 'status', 'topic' => (string) $topic->name, 'proposed' => (string) $topic->status]);
            if (isset($capturedByWorkspace[(string) $topic->workspace_id][$fp])) {
                $matched[] = $topic;
            }
        }

        return $matched;
    }

    private static function reattribute(LearnerScope $scope, JournalWriter $journal, string $topicId): void
    {
        $entryIds = LearnerTables::query($scope, 'journal_refs')
            ->where('role', 'link:about')
            ->where('ref_type', 'topic')
            ->where('ref_id', $topicId)
            ->pluck('entry_id')
            ->all();

        $entryId = $entryIds === [] ? null : LearnerTables::query($scope, 'journal_entries')
            ->whereIn('id', $entryIds)
            ->whereIn('kind', ['self_report', 'exposure'])
            ->where('origin', 'first_hand')
            ->orderByDesc('position')
            ->value('id');

        if (is_string($entryId) && $entryId !== '') {
            $journal->append($scope, [
                'id' => Ids::new(),
                'kind' => 'amendment',
                'actor' => ['type' => 'system', 'id' => 'attribution-audit', 'channel' => 'job'],
                'occurred_at' => Memory::now(),
                'body' => [
                    'action' => 'amend',
                    'reason' => 'wrong_capture',
                    'targets' => ["event:{$entryId}"],
                    'replacement' => ['origin' => 'reported'],
                ],
            ]);
        }

        LearnerTables::query($scope, 'topics')->where('id', $topicId)->update([
            'status_by' => 'accepted',
            'updated_at' => now(),
        ]);
    }
}
