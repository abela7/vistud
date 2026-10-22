<?php

namespace App\Engine\Context;

/**
 * What the tutor's standing context is written from (docs/specs/vistud-2-blueprint.md §3.6.2): plain values, so
 * that the Stack's composing is pure and a prompt can be tried without a database (php artisan prompts:try). The
 * Stack gathers them from the services for a session.
 */
final readonly class Facts
{
    /**
     * @param  list<string>  $courseOutcomes  what the course should teach, a line each
     * @param  list<string>  $courseAssessment  how it is assessed, a line each ("Midterm 30 % (12 Oct)")
     * @param  list<string>  $preferences  how the student likes to learn in this course, a short phrase each
     * @param  list<string>  $moduleFiles  the module's files, a line each ("Lecture 3.pdf (18 pages: scheduling)")
     * @param  list<string>  $moduleQuestions  its open questions, stuck first
     * @param  list<string>  $moduleKeyPoints  what the student keeps on its topics
     * @param  list<array{name: string, status: string}>  $topics  the module's topics (the folder's, in a folder's session), with the student's word on each
     * @param  list<string>  $otherFolders  in a folder's session, the module's other folders (docs/specs/vistud-2-blueprint.md, Phase 9)
     * @param  list<string>  $teaching  how to teach in this session, one instruction a line
     * @param  list<string>  $material  the session's chosen material, a line each
     * @param  list<string>  $materialText  for a model that can't look things up: the chosen notes' text, a block each
     */
    public function __construct(
        // 2 · the course
        public string $courseName,
        public ?string $courseLine = null,
        public string $courseInstructions = '',
        public string $courseAbout = '',
        public array $courseOutcomes = [],
        public array $courseAssessment = [],
        public string $courseTextbook = '',
        /** @var list<string> the kind of course, its level, language, tools and exams (CourseTypes::lines) */
        public array $courseKind = [],
        // 3 · the student
        public string $aboutYou = '',
        public array $preferences = [],
        /** What the tutor remembers about the student (App\Study\TutorMemories), in its numbered order, each with " (all courses)" when it is for every course. */
        public array $memories = [],
        /** Whether the student lets the tutor remember things about them. */
        public bool $remembers = true,
        public ?string $language = null,
        public bool $askTopics = false,
        /** Whether what the tutor sets with set_topic_status is applied at once (else only proposed). */
        public bool $marksTopics = true,
        /** Whether the model has tools (the session layer names them only then). */
        public bool $withTools = true,
        // 4 · the module
        public ?string $moduleTitle = null,
        public ?string $moduleDates = null,
        // What the module is about, and its parts with their kinds ("Lab session (lab)"): the owner's ask, 2026-10-12.
        public ?string $moduleAbout = null,
        public array $moduleParts = [],
        public string $moduleInstructions = '',
        public array $topics = [],
        /** In a folder's session, the module's topics in no folder, with where each stands. */
        public array $moduleOwnTopics = [],
        public array $moduleFiles = [],
        public array $moduleQuestions = [],
        public array $moduleKeyPoints = [],
        public ?string $moduleLast = null,
        /** The course's open assignments, soonest due first, a line each: what, when, its stage. */
        public array $assignmentsDue = [],
        /**
         * What the assignments the session's topic or module feeds ask for, from their understanding, as lines with
         * whether each is always kept (list<array{0: string, 1: bool}>).
         */
        public array $assignmentsLinked = [],
        // 4 · the folder the session studies in, when it has one: the files, topics, questions, key points and last stop
        // above are then the folder's
        public ?string $folderName = null,
        public array $otherFolders = [],
        // 5 · the session
        public string $mode = 'topic',
        public ?string $topicNow = null,
        public ?string $topicPractice = null,
        /** Whether the topic now was chosen after the chat's first turn, and whether the student took the topic off. */
        public bool $topicChangedInChat = false,
        public bool $topicTakenOff = false,
        public string $clock = 'free: the student pauses and takes breaks when they like',
        public array $teaching = [],
        public array $material = [],
        public ?string $checkpoint = null,
        public ?string $summary = null,
        public ?string $studied = null,
        public ?string $openedNoteTitle = null,
        public array $materialText = [],
        // 6 · the chat so far, when its oldest turns were folded
        public ?string $folded = null,
        // 3 · the student's first name, from their account (App\Engine\Context\StudentName); null when unknown
        public ?string $studentName = null,
    ) {}

    /**
     * The same facts with what the tutor remembers about the student.
     *
     * @param  list<string>  $memories
     */
    public function withMemories(array $memories): self
    {
        return new self(...[...get_object_vars($this), 'memories' => $memories]);
    }

    /** The same facts with the student's first name (null when unknown). */
    public function withName(?string $name): self
    {
        return new self(...[...get_object_vars($this), 'studentName' => $name]);
    }
}
