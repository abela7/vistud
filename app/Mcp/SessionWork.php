<?php

namespace App\Mcp;

use App\Platform\Access\Guard;
use App\Platform\Access\Principal;
use App\Platform\Database\LearnerTables;
use Carbon\CarbonImmutable;

/**
 * What the student's AI apps did inside one study session, read back from `mcp_calls` (docs/specs/mcp-connections-plan.md
 * §3.7): the "saved" rail, the end screen's marks and the "connected" chip of the session page. The in-app tutor's
 * work is read from its chat (SessionChat::activity); this is the same shape for the apps, so the page can add them.
 * Only ids, counts and status codes are stored in a call, so what is counted here is looked up live: something the
 * student deleted since no longer counts.
 */
final class SessionWork
{
    /** An app that called into the session this recently is "connected" to it. */
    public const LIVE_MINUTES = 30;

    /** The table each kind a call saved is kept in, and whether a deleted one is left marked (retired_at). */
    private const SAVED_IN = [
        'finding' => ['findings', false],
        'question' => ['questions', true],
        'topic' => ['topics', true],
    ];

    /**
     * @return array{
     *     saved: array<string, int>,
     *     topics: list<string>,
     *     statuses: array<string, array<string, mixed>>,
     *     quizzes: int,
     *     quizApps: array<string, string>,
     *     apps: list<string>,
     *     live: ?string,
     *     calls: int
     * }
     */
    public function for(Principal $by, string $sessionId): array
    {
        $scope = Guard::learner($by);
        $out = ['saved' => [], 'topics' => [], 'statuses' => [], 'quizzes' => 0, 'quizApps' => [], 'apps' => [], 'live' => null, 'calls' => 0];
        $calls = LearnerTables::query($scope, 'mcp_calls')
            ->where('target_type', 'session')->where('target_id', $sessionId)->where('outcome', Calls::OK)
            ->orderBy('created_at')->orderBy('id')
            ->get(['connection_id', 'effects', 'created_at']);
        if ($calls->isEmpty()) {
            return $out;
        }
        $companies = [];
        foreach (LearnerTables::query($scope, 'mcp_connections')->whereIn('id', $calls->pluck('connection_id')->unique()->all())->get(['id', 'provider']) as $row) {
            $companies[$row->id] = Provider::company((string) $row->provider);
        }

        $made = [];
        foreach ($calls as $call) {
            $company = $companies[$call->connection_id] ?? Provider::company(Provider::OTHER);
            $out['calls']++;
            $out['apps'][] = $company;
            $effects = json_decode((string) $call->effects, true) ?: [];
            foreach ((array) ($effects['saved_ids'] ?? []) as $kind => $ids) {
                $made[$kind] = [...$made[$kind] ?? [], ...array_map('strval', (array) $ids)];
            }
            if (is_string($effects['topic_id'] ?? null)) {
                $out['topics'][] = $effects['topic_id'];
            }
            foreach ((array) ($effects['statuses'] ?? []) as $status) {
                if (is_array($status) && is_string($status['topic_id'] ?? null)) {
                    $out['topics'][] = $status['topic_id'];
                    $out['statuses'][$status['topic_id']] = $status + ['app' => $company];
                }
            }
            foreach ((array) ($effects['quizzes'] ?? []) as $quiz) {
                if (is_array($quiz) && is_string($quiz['id'] ?? null)) {
                    $out['quizApps'][$quiz['id']] = $company;
                }
            }
        }
        // Cards are counted from the deck by the page (a session's AI cards, whoever made them), so they are not added twice.
        foreach (array_intersect_key($made, self::SAVED_IN) as $kind => $ids) {
            [$table, $marked] = self::SAVED_IN[$kind];
            $query = LearnerTables::query($scope, $table)->whereIn('id', array_values(array_unique($ids)));
            $live = ($marked ? $query->whereNull('retired_at') : $query)->count();
            if ($live > 0) {
                $out['saved'][$kind] = $live;
            }
        }
        // A quiz the student deleted since no longer counts.
        $kept = $out['quizApps'] === [] ? [] : LearnerTables::query($scope, 'quizzes')->whereIn('id', array_keys($out['quizApps']))->pluck('id')->map(fn ($id) => (string) $id)->all();
        $out['quizApps'] = array_intersect_key($out['quizApps'], array_flip($kept));
        $out['quizzes'] = count($out['quizApps']);
        $out['topics'] = array_values(array_unique($out['topics']));
        $out['apps'] = array_values(array_unique($out['apps']));
        // elapsed: "connected" means an app was in the session in the last half hour of real time.
        $last = CarbonImmutable::parse((string) $calls->last()->created_at, 'UTC');
        if ($last->greaterThanOrEqualTo(now()->subMinutes(self::LIVE_MINUTES))) {
            $out['live'] = $companies[$calls->last()->connection_id] ?? Provider::company(Provider::OTHER);
        }

        return $out;
    }

    /**
     * The in-app activity (SessionChat::activity) with the apps' work added to it.
     *
     * @param  array{saved: array<string, int>, topics: list<string>, statuses: array<string, mixed>, quizzes: int}  $activity
     * @param  array<string, mixed>  $work
     * @return array<string, mixed>
     */
    public static function merge(array $activity, array $work): array
    {
        foreach ($work['saved'] as $kind => $count) {
            $activity['saved'][$kind] = ($activity['saved'][$kind] ?? 0) + $count;
        }
        $activity['topics'] = array_values(array_unique([...$activity['topics'], ...$work['topics']]));
        $activity['statuses'] += $work['statuses'];
        $activity['quizzes'] += $work['quizzes'];

        return $activity;
    }
}
