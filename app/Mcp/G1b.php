<?php

namespace App\Mcp;

use Carbon\CarbonImmutable;

/**
 * G1b (ADR 0001 G1, ADR 0003 D9): how many attempts a chat app recorded, against how many the in-app tutor recorded.
 * It reports. It does not decide. Below the minimums the result is inconclusive and collection continues.
 * The embedding comparison needs labelled retrieval cases, which this store does not hold.
 */
final class G1b
{
    public const MIN_CASES = 150;

    public const MIN_DAYS = 28;

    /**
     * @param  list<string>  $mcpTimes
     * @param  list<string>  $chatTimes
     * @return array{mcp: int, chat: int, days: int, result: string}
     */
    public static function judge(array $mcpTimes, array $chatTimes): array
    {
        $times = [...$mcpTimes, ...$chatTimes];
        $days = 0;
        if ($times !== []) {
            $oldest = CarbonImmutable::parse(min($times));
            $newest = CarbonImmutable::parse(max($times));
            $days = (int) $oldest->diffInDays($newest);
        }
        $enough = count($mcpTimes) >= self::MIN_CASES && count($chatTimes) >= self::MIN_CASES && $days >= self::MIN_DAYS;

        return [
            'mcp' => count($mcpTimes),
            'chat' => count($chatTimes),
            'days' => $days,
            'result' => $enough ? 'sample met; no embedding comparison is stored, so nothing is decided' : 'inconclusive',
        ];
    }
}
