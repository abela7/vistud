<?php

declare(strict_types=1);

namespace App\Study;

use App\Platform\Access\Guard;
use App\Platform\Access\Principal;
use App\Platform\Database\LearnerTables;
use App\Platform\Errors\NotFound;
use App\Platform\Errors\Unprocessable;
use Illuminate\Support\Facades\DB;

/**
 * The one note for a class. A mark is a section inside it: from this minute
 * to that minute, then what the student wrote. The times live in the heading
 * text and in its id, because a note cannot keep a time of its own.
 */
final class ClassNote
{
    public const MAX = 40;

    public const MAX_ABOUT = 120;

    public const MAX_BODY = 8000;

    public function __construct(private ClassSessions $classes, private Notes $notes) {}

    /**
     * The marks in the class note, in the order they were written.
     *
     * @return list<array{from: string, until: string, about: string, body: string}>
     */
    public function read(Principal $by, string $classId): array
    {
        $class = $this->classes->find($by, $classId);
        if ($class->noteId === null) {
            return [];
        }
        try {
            $note = $this->notes->open($by, $class->noteId);
        } catch (NotFound) {
            return [];
        }
        if ($note->trashedAt !== null || ! is_array($note->doc)) {
            return [];
        }

        return self::marks($note->doc);
    }

    /**
     * Replaces the class note with these marks. The same note is kept.
     *
     * @param  array<mixed>  $marks
     * @return list<array{from: string, until: string, about: string, body: string}>
     */
    public function write(Principal $by, string $classId, array $marks): array
    {
        $class = $this->classes->find($by, $classId);
        $clean = $this->checked($class, $marks);
        if ($clean === [] && $class->noteId === null) {
            return [];
        }

        $noteId = $this->ensure($by, $class);
        $existing = $this->content($by, $noteId);
        $blocks = $this->blocks($clean, self::split($existing));
        if ($blocks === []) {
            $blocks = [['type' => 'paragraph']];
        }
        if ($blocks !== $existing) {
            $this->notes->replace($by, $noteId, $blocks, 'class');
        }

        return $this->read($by, $classId);
    }

    /** @return list<array<string, mixed>> the blocks of the note as they are now */
    private function content(Principal $by, string $noteId): array
    {
        $doc = $this->notes->open($by, $noteId)->doc;
        $content = is_array($doc) ? ($doc['content'] ?? []) : [];

        return array_values(array_filter($content, 'is_array'));
    }

    /**
     * @param  array<mixed>  $marks
     * @return list<array{from: int, until: int, about: string, body: string}>
     */
    private function checked(ClassSessionDetails $class, array $marks): array
    {
        if (count($marks) > self::MAX) {
            Input::refuse(['mark' => 'Keep it to '.self::MAX.' marks.']);
        }
        if ($marks !== [] && $class->durationSeconds < 1) {
            Input::refuse(['mark' => 'Record the class before you mark it.']);
        }

        $clean = [];
        foreach ($marks as $mark) {
            if (! is_array($mark)) {
                Input::refuse(['mark' => 'That mark is not valid.']);
            }
            $clean[] = $this->one($class, $mark);
        }

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $mark
     * @return array{from: int, until: int, about: string, body: string}
     */
    private function one(ClassSessionDetails $class, array $mark): array
    {
        $from = self::seconds($mark['from'] ?? null);
        $until = self::seconds($mark['until'] ?? null);
        $about = is_string($mark['about'] ?? null) ? trim($mark['about']) : '';
        $body = is_string($mark['body'] ?? null) ? trim($mark['body']) : '';
        Input::refuse(array_filter([
            'from' => $from === null ? 'Use a time like 10:00.' : null,
            'until' => $until === null
                ? 'Use a time like 20:00.'
                : ($from !== null && $until <= $from
                    ? 'The end has to be after the start.'
                    : ($until > $class->durationSeconds ? 'That is past the end of the recording.' : null)),
            'about' => $about === ''
                ? 'Say what this stretch is about.'
                : (mb_strlen($about) > self::MAX_ABOUT ? 'Use '.self::MAX_ABOUT.' characters or fewer.' : null),
            'body' => mb_strlen($body) > self::MAX_BODY ? 'Use '.self::MAX_BODY.' characters or fewer.' : null,
        ]));

        if ($from === null || $until === null) {
            throw new Unprocessable('validation_failed', 'Some fields need attention.');
        }

        return ['from' => $from, 'until' => $until, 'about' => $about, 'body' => $body];
    }

    /** 10:00 is ten minutes. 1:02:03 is one hour, two minutes and three seconds. */
    public static function seconds(mixed $value): ?int
    {
        if (! is_string($value) || preg_match('/^(\d+):(\d{2})(?::(\d{2}))?$/', trim($value), $match) !== 1) {
            return null;
        }
        $withHours = array_key_exists(3, $match);
        $hours = $withHours ? (int) $match[1] : 0;
        $minutes = $withHours ? (int) $match[2] : (int) $match[1];
        $seconds = $withHours ? (int) $match[3] : (int) $match[2];
        if ($seconds > 59 || ($withHours && ($minutes > 59 || $hours > 4))) {
            return null;
        }
        $total = ($hours * 3600) + ($minutes * 60) + $seconds;

        return $total <= ClassRecordings::MAX_SECONDS ? $total : null;
    }

    private function ensure(Principal $by, ClassSessionDetails $class): string
    {
        $scope = Guard::learner($by);

        return DB::transaction(function () use ($scope, $by, $class) {
            $row = LearnerTables::query($scope, 'class_sessions')->where('id', $class->id)->lockForUpdate()->first() ?? throw new NotFound;
            $noteId = $row->note_id !== null ? (string) $row->note_id : null;
            if ($noteId !== null) {
                try {
                    $note = $this->notes->find($by, $noteId);
                    if ($note->trashedAt === null && $note->workspaceId === $class->workspaceId) {
                        return $noteId;
                    }
                } catch (NotFound) {
                    $noteId = null;
                }
            }
            if ($class->moduleId === null) {
                throw new Unprocessable('validation_failed', 'Some fields need attention.', ['fields' => ['mark' => ['This class is not in a module.']]]);
            }
            $created = $this->notes->create($by, 'module', $class->moduleId, $class->title);
            LearnerTables::query($scope, 'class_sessions')->where('id', $class->id)->update([
                'note_id' => $created->id,
                'updated_at' => now(),
            ]);

            return $created->id;
        });
    }

    /**
     * What the note holds once these marks are written. Anything the student
     * wrote outside the marks stays. A mark whose words did not change keeps
     * its blocks as they are, so lists and pictures under it survive.
     *
     * @param  list<array{from: int, until: int, about: string, body: string}>  $marks
     * @param  array{before: list<array<string, mixed>>, sections: array<string, list<array{heading: array<string, mixed>, nodes: list<array<string, mixed>>, about: string, body: string}>>}  $kept
     * @return list<array<string, mixed>>
     */
    private function blocks(array $marks, array $kept): array
    {
        $blocks = $kept['before'];
        $sections = $kept['sections'];
        foreach ($marks as $mark) {
            $id = 'm-'.$mark['from'].'-'.$mark['until'];
            $old = isset($sections[$id]) ? array_shift($sections[$id]) : null;
            $from = ClassRecordingDetails::clock($mark['from']);
            $until = ClassRecordingDetails::clock($mark['until']);
            $heading = $old !== null && $old['about'] === $mark['about']
                ? $old['heading']
                : [
                    'type' => 'heading',
                    'attrs' => ['id' => $id, 'level' => 2],
                    'content' => [['type' => 'text', 'text' => $from.'–'.$until.' '.$mark['about']]],
                ];
            $blocks[] = $heading;
            if ($old !== null && $old['body'] === $mark['body']) {
                array_push($blocks, ...$old['nodes']);

                continue;
            }
            $lines = $mark['body'] === '' ? [''] : (preg_split('/\R/', $mark['body']) ?: ['']);
            foreach ($lines as $line) {
                $line = trim((string) $line);
                $blocks[] = $line === ''
                    ? ['type' => 'paragraph']
                    : ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $line]]];
            }
        }

        return $blocks;
    }

    /**
     * The blocks before the first mark, and each mark's own blocks.
     *
     * @param  list<array<string, mixed>>  $content
     * @return array{before: list<array<string, mixed>>, sections: array<string, list<array{heading: array<string, mixed>, nodes: list<array<string, mixed>>, about: string, body: string}>>}
     */
    private static function split(array $content): array
    {
        $before = [];
        $sections = [];
        $id = null;
        $index = 0;
        foreach ($content as $node) {
            $nodeId = $node['attrs']['id'] ?? '';
            if (($node['type'] ?? '') === 'heading' && is_string($nodeId) && preg_match('/^m-(\d+)-(\d+)$/', $nodeId) === 1) {
                $id = $nodeId;
                $sections[$id][] = ['heading' => $node, 'nodes' => [], 'about' => self::about($node), 'body' => ''];
                $index = array_key_last($sections[$id]);

                continue;
            }
            if ($id === null) {
                // A new note starts with one blank line; it says nothing.
                if (($node['type'] ?? '') !== 'paragraph' || ($node['content'] ?? []) !== []) {
                    $before[] = $node;
                }

                continue;
            }
            $sections[$id][$index]['nodes'][] = $node;
            $line = self::plain($node);
            if ($line !== '') {
                $body = $sections[$id][$index]['body'];
                $sections[$id][$index]['body'] = $body === '' ? $line : $body.'
'.$line;
            }
        }

        return ['before' => $before, 'sections' => $sections];
    }

    /** @param  array<string, mixed>  $heading */
    private static function about(array $heading): string
    {
        $text = self::plain($heading);
        $about = preg_replace('/^\d+:\d{2}(?::\d{2})?[–\-]\d+:\d{2}(?::\d{2})?\s+/u', '', $text);

        return is_string($about) ? $about : $text;
    }

    /**
     * @param  array<string, mixed>  $doc
     * @return list<array{from: string, until: string, about: string, body: string}>
     */
    private static function marks(array $doc): array
    {
        $marks = [];
        $current = null;
        foreach ($doc['content'] ?? [] as $node) {
            if (! is_array($node)) {
                continue;
            }
            $id = $node['attrs']['id'] ?? '';
            if (($node['type'] ?? '') === 'heading' && is_string($id) && preg_match('/^m-(\d+)-(\d+)$/', $id, $found) === 1) {
                if ($current !== null) {
                    $marks[] = $current;
                }
                $heading = self::plain($node);
                $about = preg_replace('/^\d+:\d{2}(?::\d{2})?[–\-]\d+:\d{2}(?::\d{2})?\s+/u', '', $heading);
                $current = [
                    'from' => ClassRecordingDetails::clock((int) $found[1]),
                    'until' => ClassRecordingDetails::clock((int) $found[2]),
                    'about' => is_string($about) ? $about : $heading,
                    'body' => '',
                ];

                continue;
            }
            if ($current === null) {
                continue;
            }
            $line = self::plain($node);
            if ($line === '') {
                continue;
            }
            $current['body'] = $current['body'] === '' ? $line : $current['body']."\n".$line;
        }
        if ($current !== null) {
            $marks[] = $current;
        }

        return $marks;
    }

    /** @param  array<string, mixed>  $node */
    private static function plain(array $node): string
    {
        $words = [];
        $walk = function (array $item) use (&$walk, &$words): void {
            if (($item['type'] ?? '') === 'text' && is_string($item['text'] ?? null)) {
                $words[] = $item['text'];
            }
            foreach ($item['content'] ?? [] as $child) {
                if (is_array($child)) {
                    $walk($child);
                }
            }
        };
        $walk($node);

        return trim(implode('', $words));
    }
}
