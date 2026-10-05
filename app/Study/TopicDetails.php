<?php

namespace App\Study;

/**
 * A topic, as the screens see it: where it sits, the student's own status,
 * and what the rules derive from the evidence (ADR 0002 §7).
 */
final readonly class TopicDetails
{
    /** The rules' labels, in plain words. */
    public const LABELS = [
        'not_started' => 'no contact yet',
        'introduced' => 'seen, not practised',
        'developing' => 'practising',
        'working' => 'working',
        'secure' => 'secure',
        'durable' => 'durable',
    ];

    /** The flags worth a word on the screen. */
    public const FLAGS = [
        'claimed_only' => 'not practised yet',
        'overconfident' => 'missed after feeling confident',
        'underconfident' => 'doing better than you think',
        'regressed' => 'slipped recently',
        'needs_review' => 'due for review',
        'exam_relevant' => 'exam relevant',
        'weak_part' => 'a part of it is weak',
        'practised' => 'practised',
    ];

    public function __construct(
        public string $id,
        public string $workspaceId,
        public ?string $moduleId,
        public string $name,
        public ?string $status,
        public int $position,
        public string $label,
        public array $flags,
        public ?string $lastContact,
        public ?string $statusBy = null,
        public ?string $statusAt = null,
        /** The folder of its module it belongs to (docs/specs/vistud-2-blueprint.md, Phase 9), or null. */
        public ?string $folderId = null,
    ) {}

    /** Whether it is in one of these folders (Folders::within gives a folder and those inside it). @param list<string> $folderIds */
    public function in(array $folderIds): bool
    {
        return $this->folderId !== null && in_array($this->folderId, $folderIds, true);
    }

    /** The one word shown: mastered is earned, the rest is the student's word. */
    public function shown(): string
    {
        return match (true) {
            in_array($this->label, ['secure', 'durable'], true) => 'mastered',
            $this->status !== null => $this->status,
            $this->label !== 'not_started' => 'covered',
            default => 'not_started',
        };
    }

    /** Whether the tutor set the status shown, rather than the student: it says so, and can be undone. */
    public function byTutor(): bool
    {
        return $this->status !== null && $this->statusBy === 'tutor';
    }

    /** "Evidence: seen, not practised · not practised yet" */
    public function evidence(): string
    {
        $words = array_values(array_filter(array_map(fn ($flag) => self::FLAGS[$flag] ?? null, $this->flags)));

        return implode(' · ', [self::LABELS[$this->label] ?? $this->label, ...$words]);
    }
}
