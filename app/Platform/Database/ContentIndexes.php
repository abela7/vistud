<?php

namespace App\Platform\Database;

use App\Platform\Access\LearnerScope;

/** The existing suggestion uniqueness index; no readable normalized name is stored. */
final class ContentIndexes
{
    public static function normalizedSuggestion(string $name): string
    {
        return mb_substr(mb_strtolower($name), 0, 120);
    }

    public static function suggestion(LearnerScope $scope, string $name): string
    {
        return hash_hmac('sha256', 'topic_suggestions.name:'.self::normalizedSuggestion($name), $scope->key('search'));
    }
}
