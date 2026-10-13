<?php

namespace App\Study;

use App\Platform\Access\Guard;
use App\Platform\Access\LearnerScope;
use App\Platform\Access\Principal;
use App\Platform\Database\LearnerTables;
use App\Platform\Errors\NotFound;
use App\Platform\Ids;

/**
 * What the tutor remembers about its student: short lines it keeps from a chat (the `remember` tool), or the student
 * writes, so what they said about themselves isn't lost when the chat ends. For one course, or for every course. The
 * tutor sees them at the start of every session and topic chat (App\Engine\Context\Stack), numbered, so it can replace
 * one; the student sees, changes and deletes them in "Instructions for the AI", and can turn the tutor's keeping them
 * off (App\Engine\Choices::$tutorRemembers).
 *
 * Kept small on purpose: a line each, and a few for all courses and a few for each course, so they always fit in what
 * the tutor reads and stay worth reading; when there is no room, one has to go or be replaced. The student's own only.
 */
final class TutorMemories
{
    public const MAX_TEXT = 160;

    /** For all courses, and again for each course. */
    public const MAX_EACH = 8;

    public function __construct(private Workspaces $workspaces) {}

    /**
     * What the tutor remembers in a course: those for every course first, then the course's, each oldest first. The
     * tutor's numbers (1, 2, …) are places in this list.
     *
     * @return list<TutorMemory>
     */
    public function list(Principal $by, ?string $workspaceId): array
    {
        $scope = Guard::learner($by);
        $rows = LearnerTables::query($scope, 'tutor_memories')
            ->where(fn ($q) => $workspaceId === null ? $q->whereNull('workspace_id') : $q->whereNull('workspace_id')->orWhere('workspace_id', $workspaceId))
            ->orderByRaw('workspace_id is not null')->orderBy('created_at')->orderBy('id')
            ->get();

        return $rows->map(fn ($row) => TutorMemory::fromRow($row))->all();
    }

    /**
     * Everything the tutor remembers about the student, in every course: those for all courses first, then by course,
     * each oldest first (for the place the student manages them, App\Livewire\Study\Memories).
     *
     * @return list<TutorMemory>
     */
    public function all(Principal $by): array
    {
        return LearnerTables::query(Guard::learner($by), 'tutor_memories')
            ->orderByRaw('workspace_id is not null')->orderBy('workspace_id')->orderBy('created_at')->orderBy('id')
            ->get()->map(fn ($row) => TutorMemory::fromRow($row))->all();
    }

    /**
     * Remembers a line, for the course ($workspaceId) or for all courses (null); in place of $replacesId when given.
     * Refused when the text is empty or too long, when it is already remembered, and when there is no room.
     */
    public function add(Principal $by, ?string $workspaceId, mixed $text, string $who, ?string $sessionId = null, ?string $topicId = null, ?string $replacesId = null): TutorMemory
    {
        $scope = Guard::learner($by);
        if ($workspaceId !== null) {
            $this->workspaces->find($by, $workspaceId);
        }
        $text = self::clean($text);
        $replaced = $replacesId === null ? null : $this->row($scope, $replacesId);
        $same = array_values(array_filter($this->list($by, $workspaceId), fn (TutorMemory $m) => $m->id !== $replacesId && mb_strtolower($m->text) === mb_strtolower($text)));
        $room = LearnerTables::query($scope, 'tutor_memories')->where(fn ($q) => $workspaceId === null ? $q->whereNull('workspace_id') : $q->where('workspace_id', $workspaceId))->count();
        Input::refuse(match (true) {
            $text === '' => ['text' => 'Write what to remember.'],
            mb_strlen($text) > self::MAX_TEXT => ['text' => 'Keep it to '.self::MAX_TEXT.' characters: one short line.'],
            $same !== [] => ['text' => 'That is remembered already.'],
            // A replacement in the same place takes its room; anything else needs a free place.
            $room >= self::MAX_EACH && ($replaced === null || $replaced->workspace_id !== $workspaceId) => ['text' => 'There is no room: '.self::MAX_EACH.' are kept '.($workspaceId === null ? 'for all courses' : 'for this course').'. Delete or change one first.'],
            default => [],
        });

        $values = ['workspace_id' => $workspaceId, 'text' => $text, 'by' => $who === 'tutor' ? 'tutor' : 'student', 'session_id' => $sessionId, 'topic_id' => $topicId, 'updated_at' => now()];
        if ($replaced !== null) {
            LearnerTables::query($scope, 'tutor_memories')->where('id', $replaced->id)->update($values);

            return TutorMemory::fromRow($this->row($scope, $replaced->id));
        }
        $id = Ids::new();
        LearnerTables::insert($scope, 'tutor_memories', $values + ['id' => $id, 'created_at' => now()]);

        return TutorMemory::fromRow($this->row($scope, $id));
    }

    /** The student changes a line's words, or whether it is for all courses ($workspaceId null) or this one. */
    public function update(Principal $by, string $id, mixed $text, ?string $workspaceId): TutorMemory
    {
        $scope = Guard::learner($by);
        $row = $this->row($scope, $id);

        return $this->add($by, $workspaceId, $text, 'student', $row->session_id, $row->topic_id, $id);
    }

    public function find(Principal $by, string $id): TutorMemory
    {
        return TutorMemory::fromRow($this->row(Guard::learner($by), $id));
    }

    public function forget(Principal $by, string $id): void
    {
        $scope = Guard::learner($by);
        $this->row($scope, $id);
        LearnerTables::query($scope, 'tutor_memories')->where('id', $id)->delete();
    }

    /**
     * Whether the student lets the tutor remember things (their AI settings' `tutor_remembers`, on until they turn it
     * off): when off, nothing new is kept and nothing kept is shown to any AI.
     */
    public function enabled(Principal $by): bool
    {
        $value = LearnerTables::query(Guard::learner($by), 'engine_settings')->value('tutor_remembers');

        return $value === null || (bool) $value;
    }

    /**
     * What any AI working for the student is told they remember, a line each: those for all courses, and the course's
     * when one is given ("Learns best from worked examples (all courses)"). Nothing when it is turned off.
     *
     * @return list<string>
     */
    public function lines(Principal $by, ?string $workspaceId): array
    {
        if (! $this->enabled($by)) {
            return [];
        }

        return array_map(fn (TutorMemory $m) => $m->text.($m->everywhere() && $workspaceId !== null ? ' (all courses)' : ''), $this->list($by, $workspaceId));
    }

    /** A course is deleted: what was remembered for it goes with it (what is for all courses stays). */
    public static function forgetCourse(LearnerScope $scope, string $workspaceId): void
    {
        LearnerTables::query($scope, 'tutor_memories')->where('workspace_id', $workspaceId)->delete();
    }

    /** One line: spaces folded, no line breaks. */
    private static function clean(mixed $text): string
    {
        return is_string($text) ? trim((string) preg_replace('/\s+/u', ' ', $text)) : '';
    }

    private function row(LearnerScope $scope, string $id): object
    {
        return LearnerTables::query($scope, 'tutor_memories')->where('id', $id)->first() ?? throw new NotFound;
    }
}
