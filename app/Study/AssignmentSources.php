<?php

namespace App\Study;

use App\Platform\Access\Guard;
use App\Platform\Access\LearnerScope;
use App\Platform\Access\Principal;
use App\Platform\Database\LearnerTables;
use App\Platform\Errors\NotFound;
use App\Platform\Ids;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Everything the student is given about an assignment (the owner's ask, 2026-10-13: "the student first gives all the
 * available documents, all the text and email"): the files in its folder (and the folders inside it, a section's), and
 * what is written or pasted in here: the brief's text, the marking criteria, an email, an announcement, what the
 * teacher said, the student's own note, or a correction to the AI's understanding. The AI understands the assignment
 * from all of them (App\Engine\Jobs\UnderstandAssignment). A source keeps its lines: an email or a rubric is read as
 * it was written. The student's own stream only.
 */
final class AssignmentSources
{
    /** The kinds, as the screens name them. A correction is the student telling the AI what it got wrong. */
    public const KINDS = [
        'brief' => 'Brief',
        'rubric' => 'Marking criteria',
        'email' => 'Email',
        'announcement' => 'Announcement',
        'feedback' => 'Teacher\'s comment',
        'note' => 'Your note',
        'correction' => 'Your correction',
        'other' => 'Other',
    ];

    public const MAX_TITLE = 200;

    /** The longest source, in characters: a long brief fits. */
    public const MAX_TEXT = 60_000;

    /** The most written sources one assignment keeps. */
    public const MAX_SOURCES = 40;

    public function __construct(private Activities $activities, private Files $files, private Folders $folders) {}

    /** @return list<AssignmentSource> oldest first, as they came */
    public function list(Principal $by, string $activityId): array
    {
        $scope = Guard::learner($by);
        $this->activities->find($by, $activityId);

        return LearnerTables::query($scope, 'activity_sources')->where('activity_id', $activityId)
            ->orderBy('created_at')->orderBy('id')->get()->map(fn ($row) => self::details($row))->all();
    }

    /**
     * The files in the assignment's folder and the folders inside it (its sections'), oldest first; none when it has no
     * folder, or its folder is gone.
     *
     * @return list<FileDetails>
     */
    public function files(Principal $by, string $activityId): array
    {
        $assignment = $this->activities->find($by, $activityId);
        if ($assignment->folderId === null) {
            return [];
        }
        try {
            $inside = $this->folders->within($by, $assignment->folderId);
        } catch (NotFound) {
            return [];
        }
        $files = array_values(array_filter($this->files->list($by, $assignment->workspaceId), fn (FileDetails $file) => $file->trashedAt === null && in_array($file->folderId, $inside, true)));
        usort($files, fn (FileDetails $a, FileDetails $b) => [$a->uploadedAt, $a->id] <=> [$b->uploadedAt, $b->id]);

        return $files;
    }

    /** @param array{kind?: mixed, title?: mixed, text?: mixed, received_on?: mixed} $input */
    public function add(Principal $by, string $activityId, array $input): AssignmentSource
    {
        $scope = Guard::learner($by);
        $fields = self::validated($input);
        $id = Ids::new();

        DB::transaction(function () use ($scope, $activityId, $fields, $id) {
            $assignment = LearnerTables::query($scope, 'activities')->where('id', $activityId)->lockForUpdate()->first() ?? throw new NotFound;
            $count = LearnerTables::query($scope, 'activity_sources')->where('activity_id', $activityId)->count();
            Input::refuse($count >= self::MAX_SOURCES ? ['text' => 'An assignment keeps up to '.self::MAX_SOURCES.' of these. Delete one you no longer need.'] : []);
            LearnerTables::insert($scope, 'activity_sources', $fields + [
                'id' => $id, 'workspace_id' => $assignment->workspace_id, 'activity_id' => $activityId, 'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        return self::details($this->row($scope, $id));
    }

    /** @param array{kind?: mixed, title?: mixed, text?: mixed, received_on?: mixed} $input */
    public function update(Principal $by, string $id, array $input): AssignmentSource
    {
        $scope = Guard::learner($by);
        $fields = self::validated($input);
        $this->row($scope, $id);
        LearnerTables::query($scope, 'activity_sources')->where('id', $id)->update($fields + ['updated_at' => now()]);

        return self::details($this->row($scope, $id));
    }

    public function delete(Principal $by, string $id): void
    {
        $scope = Guard::learner($by);
        $this->row($scope, $id);
        LearnerTables::query($scope, 'activity_sources')->where('id', $id)->delete();
    }

    /**
     * Every source with its label, the same wherever it is said (the reading, the tutor's context, its look-ups): the
     * label the understanding gave it, so a citation in it ("S2") always means that source; a source added since takes
     * the next free number, and a label whose source is gone is never given to another. Written sources are S…, files
     * F…, in label order.
     *
     * @return array<string, AssignmentSource|FileDetails>
     */
    public function labelled(Principal $by, string $activityId, ?AssignmentUnderstanding $understanding = null): array
    {
        $given = [];
        $highest = ['S' => 0, 'F' => 0];
        foreach ($understanding->sources ?? [] as $label => $source) {
            if (preg_match('/^([SF])(\d+)$/', (string) $label, $match) === 1) {
                $highest[$match[1]] = max($highest[$match[1]], (int) $match[2]);
                $given["{$source['type']}:{$source['id']}"] = (string) $label;
            }
        }

        $labelled = [];
        foreach ([['S', 'source', $this->list($by, $activityId)], ['F', 'file', $this->files($by, $activityId)]] as [$prefix, $type, $items]) {
            foreach ($items as $item) {
                $labelled[$given["{$type}:{$item->id}"] ?? $prefix.(++$highest[$prefix])] = $item;
            }
        }
        uksort($labelled, fn (string $a, string $b) => [$a[0] === 'F', (int) substr($a, 1)] <=> [$b[0] === 'F', (int) substr($b, 1)]);

        return $labelled;
    }

    /**
     * What the assignment is understood from, as one fingerprint: its written sources as they are now, and its files.
     * An understanding made from another one is out of date.
     */
    public function fingerprint(Principal $by, string $activityId): string
    {
        $written = array_map(fn (AssignmentSource $source) => [$source->id, $source->updatedAt], $this->list($by, $activityId));
        $files = array_map(fn (FileDetails $file) => [$file->id, $file->sha256 ?? $file->uploadedAt], $this->files($by, $activityId));

        return sha1((string) json_encode([$written, $files]));
    }

    /**
     * Whether an understanding no longer matches what the assignment is understood from: a source added, changed or
     * deleted since, or a file that was still being converted when the AI read (so it read nothing of it) and can be
     * read now. Every screen and the tutor's context ask this one question.
     */
    public function outOfDate(Principal $by, string $activityId, AssignmentUnderstanding $understanding): bool
    {
        if ($this->fingerprint($by, $activityId) !== $understanding->fingerprint) {
            return true;
        }
        foreach ($understanding->sources as $source) {
            if (($source['unread'] ?? false) !== true) {
                continue;
            }
            try {
                if (app(FileTexts::class)->of($by, $source['id'])->state !== FileText::PREPARING) {
                    return true;
                }
            } catch (NotFound) {
                return true;
            }
        }

        return false;
    }

    private function row(LearnerScope $scope, string $id): object
    {
        return LearnerTables::query($scope, 'activity_sources')->where('id', $id)->first() ?? throw new NotFound;
    }

    /**
     * The fields, checked. The text keeps its lines (an email, a rubric's rows): only the spaces at the ends of each line
     * and runs of empty lines go.
     *
     * @return array{kind: string, title: ?string, text: string, received_on: ?string}
     */
    private static function validated(array $input): array
    {
        $kind = $input['kind'] ?? 'brief';
        $title = Input::text($input, 'title');
        $raw = is_string($input['text'] ?? null) ? str_replace(["\r\n", "\r"], "\n", $input['text']) : '';
        $text = trim((string) preg_replace("/\n{3,}/", "\n\n", implode("\n", array_map('rtrim', explode("\n", $raw)))));
        $date = $input['received_on'] ?? null;
        $date = $date === '' ? null : $date;
        $parsed = is_string($date) ? DateTimeImmutable::createFromFormat('!Y-m-d', $date) : false;

        Input::refuse(array_filter([
            'kind' => isset(self::KINDS[$kind]) ? null : 'Choose what it is.',
            'title' => $title !== null && mb_strlen($title) > self::MAX_TITLE ? 'Keep the title to '.self::MAX_TITLE.' characters.' : null,
            'text' => match (true) {
                $text === '' => 'Paste or write what it says.',
                mb_strlen($text) > self::MAX_TEXT => 'Keep it to '.number_format(self::MAX_TEXT).' characters: put a long document in the folder as a file instead.',
                default => null,
            },
            'received_on' => $date === null || ($parsed !== false && $parsed->format('Y-m-d') === $date) ? null : 'Enter a date.',
        ]));

        return ['kind' => $kind, 'title' => $title, 'text' => $text, 'received_on' => $date];
    }

    private static function details(object $row): AssignmentSource
    {
        return new AssignmentSource(
            (string) $row->id, (string) $row->activity_id, (string) $row->kind, $row->title === null ? null : (string) $row->title, (string) $row->text,
            $row->received_on === null ? null : substr((string) $row->received_on, 0, 10), (string) $row->created_at, (string) $row->updated_at,
        );
    }
}
