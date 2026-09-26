<?php

namespace App\Brain\Journal;

/**
 * The fixed value lists owned by the core (ADR 0002 §2).
 */
final class Vocabulary
{
    public const FORMS = ['recognise', 'recall', 'apply', 'explain'];

    public const SUPPORT = ['unaided', 'hinted', 'guided', 'with_reference', 'followed_example'];

    public const SETTINGS = ['practice', 'homework', 'lab', 'quiz', 'exam', 'chat'];

    public const OUTCOMES = ['correct', 'partial', 'incorrect', 'unjudged'];

    public const JUDGED_BY = ['auto', 'person', 'ai', 'self', 'none'];

    public const STANCES = ['confused', 'unsure', 'stuck', 'confident', 'clicked', 'forgot'];

    public const UNCERTAIN_STANCES = ['confused', 'unsure', 'stuck'];

    public const CONFIDENT_STANCES = ['confident', 'clicked'];

    public const FORMATS = ['lecture', 'reading', 'video', 'explanation', 'worked_example', 'demonstration', 'feedback'];

    public const APPROACHES = ['definition', 'analogy', 'visual', 'worked_example', 'step_by_step', 'contrast', 'counterexample', 'real_world', 'questioning'];

    public const CLAIM_TYPES = ['defines', 'refers_to', 'same_as', 'splits_into', 'relates', 'judges', 'reviews'];

    public const RELATIONS = ['prerequisite_of', 'part_of', 'contrasts_with', 'example_of', 'related_to', 'covers', 'emphasizes', 'stems_from', 'addresses', 'exercises'];

    public const DECISIONS = ['accept', 'reject', 'dispute', 'withdraw'];

    public const RECORD_TYPES = ['source', 'extraction', 'activity', 'task', 'session', 'profile', 'consent', 'workspace', 'module'];

    public const ACTIVITY_KINDS = ['lecture', 'lab', 'assignment', 'quiz', 'exam', 'problem_set'];

    public const TASK_STATUSES = ['active', 'retired'];

    /**
     * Where a checker's answer key came from (ADR 0003 §9.4). Only the first
     * three can back `judged_by: auto`; a key the learner wrote cannot.
     */
    public const KEY_SOURCES = ['course_material', 'instructor', 'derived_from_material', 'learner'];

    public const TRUSTED_KEY_SOURCES = ['course_material', 'instructor', 'derived_from_material'];

    /** Reference types (ADR 0002 §5), plus the workspace and module records. */
    public const REF_TYPES = ['event', 'claim', 'topic', 'question', 'misconception', 'task', 'activity', 'source', 'mention', 'workspace', 'module'];

    public const LINK_RELS = ['about', 'cites', 'responds_to', 'triggered_by'];

    public const LINK_ROLES = ['primary', 'secondary'];

    public const AMENDMENT_ACTIONS = ['retract', 'amend', 'redact'];

    public const AMENDMENT_REASONS = ['wrong_capture', 'secret', 'personal_information', 'not_learning', 'learner_request', 'other'];

    public const ORIGINS = ['first_hand', 'reported', 'material'];

    public const METHOD_KINDS = ['person', 'auto', 'interpreter', 'chat_ai', 'self', 'rule'];

    public const ACTOR_TYPES = ['learner', 'person', 'chat_client', 'processor', 'system'];

    public const CHANNELS = ['web', 'mcp', 'api', 'job'];

    public const ENTITY_TYPES = ['topic', 'misconception', 'question', 'task'];

    public const CAUSES = ['forgot', 'slip', 'misconception', 'missing_prerequisite', 'harder_variant', 'unclear'];

    public const ADEQUACY = ['full', 'partial', 'none'];

    public const EFFECTS = ['helped', 'no_effect', 'confused'];

    /** Facet keys a judges claim may carry about an attempt, and about an exposure. */
    public const ATTEMPT_FACETS = ['overall', 'topics', 'support', 'own_words', 'misconceptions', 'demonstrates', 'cause'];

    public const EXPOSURE_FACETS = ['answer', 'effect'];
}
