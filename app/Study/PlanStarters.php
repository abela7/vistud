<?php

namespace App\Study;

/**
 * Starting points for an assignment's plan (the owner's review, 2026-10-02): a few parts with the steps they
 * usually hold, and criteria to check against. A student picks one, then changes whatever doesn't fit: nothing
 * here is fixed, and every list can be added to, renamed, reordered and deleted. Part marks are never given, since
 * only the brief knows them.
 */
final class PlanStarters
{
    /**
     * @var array<string, array{label: string, icon: string, hint: string, parts: list<array{0: string, 1: list<string>}>, steps: list<string>, criteria: list<string>, milestones?: list<string>}>
     */
    public const ALL = [
        'essay' => [
            'label' => 'Essay or report',
            'icon' => 'file-text',
            'hint' => 'Research, plan, draft, finish',
            'parts' => [
                ['Research', ['Read the brief and the marking criteria', 'Find and read the sources', 'Note the key points']],
                ['Plan', ['Decide the argument', 'Outline the structure']],
                ['Draft', ['Introduction', 'Main body', 'Conclusion']],
                ['Finish', ['Add the references', 'Proofread', 'Check it against the criteria', 'Submit']],
            ],
            'steps' => [],
            'criteria' => ['Argument and analysis', 'Use of sources', 'Structure and clarity', 'Referencing and presentation'],
        ],
        'problems' => [
            'label' => 'Problem set',
            'icon' => 'calculator',
            'hint' => 'Question by question',
            'parts' => [['Question 1', []], ['Question 2', []], ['Question 3', []], ['Question 4', []]],
            'steps' => ['Review the lecture notes', 'Check the answers', 'Submit'],
            'criteria' => ['Correct method', 'Correct answers', 'Clear working'],
        ],
        'presentation' => [
            'label' => 'Presentation',
            'icon' => 'presentation',
            'hint' => 'Content, slides, delivery',
            'parts' => [
                ['Content', ['Research the topic', 'Outline the story']],
                ['Slides', ['Make the slides', 'Add visuals and sources']],
                ['Delivery', ['Write the script', 'Rehearse out loud', 'Time it']],
            ],
            'steps' => ['Send the slides'],
            'criteria' => ['Content', 'Structure', 'Delivery', 'Visuals', 'Answering questions'],
        ],
        'coding' => [
            'label' => 'Coding project',
            'icon' => 'code',
            'hint' => 'Plan, build, test, hand in',
            'parts' => [
                ['Plan', ['Read the spec', 'Break it into features']],
                ['Build', ['Set the project up', 'The core feature', 'The remaining features']],
                ['Test', ['Write the tests', 'Fix the bugs']],
                ['Hand in', ['Write the README', 'Final check', 'Submit']],
            ],
            'steps' => [],
            'criteria' => ['Works as specified', 'Code quality', 'Testing', 'Documentation'],
        ],
        'lab' => [
            'label' => 'Lab report',
            'icon' => 'microscope',
            'hint' => 'Data, then the write-up',
            'parts' => [
                ['Before the lab', ['Read the lab sheet']],
                ['Data', ['Run the experiment', 'Record the data', 'Process the results']],
                ['Report', ['Introduction', 'Method', 'Results', 'Discussion', 'Conclusion', 'References']],
                ['Finish', ['Proofread', 'Submit']],
            ],
            'steps' => [],
            'criteria' => ['Method', 'Results and analysis', 'Discussion', 'Presentation'],
        ],
        'project' => [
            'label' => 'Project',
            'icon' => 'layers',
            'hint' => 'Stages, milestones, a final hand-in',
            'parts' => [
                ['Initiate', ['Define the goal and the scope', 'List what is needed', 'Plan the stages']],
                ['Research', ['Review what already exists', 'Decide the approach']],
                ['Build', ['Set it up', 'The core of the work', 'The remaining work']],
                ['Test and review', ['Check it against the goal', 'Fix what is found']],
                ['Present and hand in', ['Write the report', 'Prepare the presentation', 'Submit']],
            ],
            'steps' => [],
            'criteria' => ['Outcome', 'Process and planning', 'Quality', 'Reflection'],
            'milestones' => ['Proposal agreed', 'Halfway review', 'Final hand-in'],
        ],
        'group' => [
            'label' => 'Group project',
            'icon' => 'users',
            'hint' => 'Team set-up, shared work, hand-in',
            'parts' => [
                ['Team set-up', ['Agree who does what', 'Agree how to keep in touch', 'Split the work']],
                ['Research', ['Everyone finds their sources', 'Share what was found']],
                ['Build', ['Each does their part', 'Check in as a team']],
                ['Bring it together', ['Combine the parts', 'Review it as a team']],
                ['Present and hand in', ['Rehearse together', 'Submit']],
            ],
            'steps' => [],
            'criteria' => ['Outcome', 'Teamwork and contribution', 'Quality', 'Presentation'],
            'milestones' => ['Roles agreed', 'First draft together', 'Final hand-in'],
        ],
        'exam' => [
            'label' => 'Exam revision',
            'icon' => 'graduation-cap',
            'hint' => 'Gather, revise, practise',
            'parts' => [
                ['Gather', ['List the topics', 'Collect the notes and slides']],
                ['Revise', ['Summarise each topic', 'Make flashcards for what is hard', 'Do a past paper']],
                ['Final days', ['Fix the weak spots', 'Rest before the exam']],
            ],
            'steps' => [],
            'criteria' => [],
        ],
    ];

    /** @return array{label: string, icon: string, hint: string, parts: list<array{0: string, 1: list<string>}>, steps: list<string>, criteria: list<string>, milestones?: list<string>}|null */
    public static function get(string $key): ?array
    {
        return self::ALL[$key] ?? null;
    }
}
