<?php

namespace App\Engine\Jobs;

use App\Engine\EngineFailed;

/** The JSON object a job asked a model for, found in its answer even inside a code fence or after a word of introduction. */
final class JsonAnswer
{
    /**
     * @return array<string, mixed>
     *
     * @throws EngineFailed when there is no object in it: a failure to try again
     */
    public static function object(string $answer): array
    {
        $start = strpos($answer, '{');
        $end = strrpos($answer, '}');
        $data = $start === false || $end === false || $end < $start ? null : json_decode(substr($answer, $start, $end - $start + 1), true);
        if (! is_array($data) || array_is_list($data)) {
            throw new EngineFailed('engine_unreadable', 'The AI\'s answer couldn\'t be read. Try again.');
        }

        return $data;
    }
}
