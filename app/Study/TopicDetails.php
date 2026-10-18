<?php

namespace App\Study;

/**
 * A topic, as the screens see it: where it sits, the student's own status,
 * and what the rules derive from the evidence (ADR 0002 §7).
 */
final readonly class TopicDetails
{
    /**
     * Where a topic stands, in the words every screen uses for it (the topic page, its sheet, Progress, a module's page,
     * a session), and the icon beside each. "covered" reads Studying and "confused" Not understood.
     */
    public const STATUS_WORDS = ['not_started' => 'Not started', 'covered' => 'Studying', 'understood' => 'Understood', 'confused' => 'Not understood', 'mastered' => 'Mastered'];

    public const STATUS_ICONS = ['not_started' => 'circle-dashed', 'covered' => 'book-open', 'understood' => 'circle-check', 'confused' => 'circle-alert', 'mastered' => 'shield-check'];

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
        /** When the student last got it right (a qualifying success), from the evidence; null before any. */
        public ?string $lastSuccess = null,
    ) {}

    /** Whether it is in one of these folders (Folders::within gives a folder and those inside it). @param list<string> $folderIds */
    public function in(array $folderIds): bool
    {
        return $this->folderId !== null && in_array($this->folderId, $folderIds, true);
    }

    /**
     * The one word shown (the owner's ask, 2026-10-12: tracking that follows every change of mind). The newest word holds
     * until newer evidence: "not started" until the topic is studied again, "not understood" until the student gets it
     * right again, whatever was earned before; so a student who takes a topic back, or says they've lost it, is believed.
     * Otherwise mastered is earned from the evidence, then the student's (or the tutor's) word, then "covered" once the
     * topic has been met at all.
     */
    public function shown(): string
    {
        return match (true) {
            $this->status === 'not_started' && ! self::newer($this->lastContact, $this->statusAt) => 'not_started',
            $this->status === 'confused' && ! self::newer($this->lastSuccess, $this->statusAt) => 'confused',
            in_array($this->label, ['secure', 'durable'], true) => 'mastered',
            in_array($this->status, ['covered', 'understood'], true) => $this->status,
            $this->label !== 'not_started' => 'covered',
            default => 'not_started',
        };
    }

    public const STUDENT_WORDS = ['stated', 'accepted', 'student'];

    public const AI_MARKS = ['tutor', 'chat'];

    /**
     * Whether the word shown is the one somebody said. When newer evidence has overtaken it ("understood" said, mastered
     * since; "not started" said, studied since), the word shown is the evidence's, and nobody said it.
     */
    public function saidShown(): bool
    {
        return $this->status !== null && $this->statusBy !== null && $this->status === $this->shown();
    }

    /** Whether the status on the topic is the student's word (stated directly, accepted from an AI, or legacy student). */
    public function studentsWord(): bool
    {
        return $this->status !== null && in_array($this->statusBy, self::STUDENT_WORDS, true);
    }

    /** Whether an AI (ViStud's tutor or a connected chat app) set the status on the topic: undoable, never the student's word. */
    public function byAi(): bool
    {
        return $this->status !== null && in_array($this->statusBy, self::AI_MARKS, true);
    }

    /** Whether the tutor (or a connected chat app) set the status shown, rather than the student: it says so, and can be undone. */
    public function byTutor(): bool
    {
        return $this->byAi();
    }

    /**
     * Where the shown status came from: stated | stated_unverified | accepted | tutor | chat | derived | none.
     */
    public function statusSource(): string
    {
        if (! $this->saidShown()) {
            return $this->shown() === 'not_started' ? 'none' : 'derived';
        }

        return match ($this->statusBy) {
            'stated' => 'stated',
            'student' => 'stated_unverified',
            'accepted' => 'accepted',
            'tutor' => 'tutor',
            'chat' => 'chat',
            default => $this->shown() === 'not_started' ? 'none' : 'derived',
        };
    }

    /** Whether evidence at $evidence came after the word said at $said (to the second: a tie goes to the word). */
    private static function newer(?string $evidence, ?string $said): bool
    {
        if ($evidence === null) {
            return false;
        }
        if ($said === null) {
            return true;
        }

        return strtotime($evidence) > strtotime($said.(preg_match('/[zZ]|[+-]\d\d:?\d\d$/', $said) === 1 ? '' : ' UTC'));
    }

    /** "Evidence: seen, not practised · not practised yet" */
    public function evidence(): string
    {
        $words = array_values(array_filter(array_map(fn ($flag) => self::FLAGS[$flag] ?? null, $this->flags)));

        return implode(' · ', [self::LABELS[$this->label] ?? $this->label, ...$words]);
    }
}
