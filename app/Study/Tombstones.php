<?php

namespace App\Study;

use App\Platform\Access\Guard;
use App\Platform\Access\Principal;
use App\Platform\Database\LearnerTables;
use Illuminate\Support\Carbon;

/**
 * Deletion records for the learner's browsers (ADR 0003 §5.4): which notes
 * were trashed, restored or deleted, after a cursor. A browser reads them
 * before it replays any draft, so a draft can never bring a deleted note
 * back. The learner's own records only.
 */
final class Tombstones
{
    public const PAGE = 500;

    /**
     * The records after cursor $since, oldest first, at most PAGE of them.
     *
     * @return array{data: list<array{cursor: int, entity_type: string, entity_id: string, kind: string, at: string}>, next_since: int, more: bool}
     */
    public function since(Principal $by, int $since): array
    {
        $rows = LearnerTables::query(Guard::learner($by), 'content_tombstones')
            ->where('id', '>', max(0, $since))->orderBy('id')->limit(self::PAGE)->get();

        return [
            'data' => $rows->map(fn ($row) => [
                'cursor' => (int) $row->id,
                'entity_type' => $row->entity_type,
                'entity_id' => $row->entity_id,
                'kind' => $row->kind,
                'at' => Carbon::parse($row->at)->utc()->format('Y-m-d\TH:i:s.up'),
            ])->all(),
            'next_since' => (int) ($rows->last()->id ?? max(0, $since)),
            'more' => $rows->count() === self::PAGE,
        ];
    }
}
