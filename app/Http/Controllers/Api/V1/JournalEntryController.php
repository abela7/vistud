<?php

namespace App\Http\Controllers\Api\V1;

use App\Brain\Journal\Interval;
use App\Brain\Store\JournalReader;
use App\Identity\PrincipalFactory;
use App\Platform\Access\Guard;
use App\Platform\Errors\NotFound;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** GET /api/v1/journal/entries/{id} (docs/api/openapi.json). */
class JournalEntryController
{
    public function show(Request $request, string $id, PrincipalFactory $principals, JournalReader $reader): JsonResponse
    {
        $scope = Guard::learner($principals->fromRequest($request));
        $stored = $reader->find($scope, $id) ?? throw new NotFound;
        $entry = $stored->entry;
        $time = fn (?DateTimeImmutable $t) => $t?->format('Y-m-d\TH:i:s.uP');

        return response()->json([
            'id' => $entry->id,
            'position' => $entry->position,
            'kind' => $entry->kind->value,
            'type' => $entry->type,
            'type_version' => $entry->typeVersion,
            'actor' => $entry->actor->toArray(),
            'origin' => $entry->origin,
            'occurred_at' => $time($entry->occurredAt),
            'occurred_until' => $time($entry->occurredUntil),
            'precision' => $entry->precision->value,
            'tz' => $entry->tz,
            'interval' => [
                'lo' => $time(Interval::toDateTime($entry->interval->lo)),
                'hi' => $time(Interval::toDateTime($entry->interval->hi)),
            ],
            'recorded_at' => $time($entry->recordedAt),
            'received_at' => $time($entry->receivedAt),
            'capture_key' => $entry->captureKey,
            'session' => $entry->sessionId,
            'activity' => $entry->activityId,
            'links' => $entry->links,
            'mentions' => $entry->mentions,
            'body' => (object) $entry->body,
            'content' => (object) $stored->content,
            'blocked' => $stored->blocked,
        ]);
    }
}
