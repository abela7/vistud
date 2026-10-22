<?php

namespace App\Engine\Context;

use App\Platform\Access\Principal;
use Illuminate\Support\Facades\DB;

/**
 * Who the student is, for every model that talks with them (the owner's ask, 2026-10-22): their first name from their
 * account, and how to use it (now and then, where it fits), or, without one, to ask what to call them; and to ask
 * where and what they study when no one knows yet. What they asked to be called, and where and what they study, are
 * kept as lines their tutors remember for all courses (App\Study\TutorMemories), which every chat already reads.
 * Only the first name is sent, never the whole name or the email address.
 */
final class StudentName
{
    /** The first name on the student's account, or null when it isn't one (empty, or a single letter). */
    public static function of(Principal $by): ?string
    {
        return $by->userId === null ? null : self::first((string) DB::table('users')->where('id', $by->userId)->value('name'));
    }

    public static function first(string $accountName): ?string
    {
        // The first word that is a name: a title before it ("Dr.", "Miss") is not what they are called.
        $words = preg_split('/\s+/u', trim($accountName)) ?: [];
        while (count($words) > 1 && preg_match('/^(mr|mrs|ms|miss|mx|dr|prof|sir|madam|rev)\.?$/i', $words[0]) === 1) {
            array_shift($words);
        }
        $first = $words[0] ?? '';

        return mb_strlen($first) >= 2 && preg_match('/^\p{L}[\p{L}\p{M}\'’.-]*$/u', $first) === 1 ? mb_substr($first, 0, 40) : null;
    }

    /**
     * What a model is told about the student's name, and about asking who they are.
     *
     * @param  bool  $canRemember  whether it has the remember tool and the student lets it keep things
     * @return list<string>
     */
    public static function lines(?string $name, bool $canRemember): array
    {
        $keep = $canRemember ? ' and keep it with remember, for all courses' : '';
        $lines = $name !== null
            ? ["Their name: {$name} (from their account). Call them by it now and then, where it fits (a greeting, praise, encouragement), not in every message. If they asked to be called something else (in what you remember about them, or in this chat), use that{$keep}."]
            : ["You don't know their name yet. Early in the chat, in a word or two, ask what they would like to be called{$keep}; then use it now and then, where it fits."];
        $lines[] = "If you don't know where they study and what (their school or university, their programme or subject, their year), ask once, when it helps you help them{$keep}. Don't ask again for what you already know.";

        return $lines;
    }
}
