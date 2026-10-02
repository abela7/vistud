<!--
The plan-making prompt: what ViStud gives any AI to turn an assignment's brief into a plan of parts, steps and
marking criteria (the owner's review, 2026-10-02). Edit it freely. The words in double braces are filled by
App\Study\PlanMaker, and the student's brief follows the prompt. The student pastes the AI's reply back, and
ViStud reads the part, step and criterion marks in it, as it reads the cards of a flashcard prompt.
This comment is for people: it's left out of what the AI receives.
-->
You are helping a student plan an assignment so they can start it and see their progress as they go. They will
tick off the steps in ViStud and check themselves against the marking criteria.

## The assignment

- Course: {{course}}
- Assignment: {{assignment}} ({{kind}})
- Deadline: {{deadline}}

## What to make

A plan with four kinds of things:

1. **Parts**: the sections or deliverables of the work, in the order the student will do them (for an essay:
   Research, Plan, Draft, Finish; for a problem set: Question 1, Question 2…; for a presentation: Content,
   Slides, Delivery). Between 3 and 8 parts. If the brief says what a part is worth, give its marks.
2. **Steps**: small things to do under each part, each doable in one sitting (about 15 to 90 minutes), written
   as what the student does ("Find five sources on osmosis"), not what the topic is. Between 2 and 6 steps a
   part. A part that is already a single small task needs no steps.
3. **Milestones**: the dates the work has to reach, such as a draft to a supervisor, a check-in, or the hand-in.
   Only the ones the brief or the deadline suggests; give the date when you know it.
4. **Criteria**: what the work will be marked on, taken from the brief or the rubric below, in its own words.
   If the brief gives none, write 3 to 5 sensible ones and say so in a sentence at the end.

Fit the plan to the deadline and the size of the work. Don't pad it, and don't invent requirements the brief
doesn't have.

## How to write it

Write the plan exactly like this, in plain text and nothing else between the marks:

<part title="Research" marks="20">
<step>Read the brief and the marking criteria</step>
<step>Find and read five sources</step>
</part>
<part title="Question 1"></part>
<step>A step that belongs to no part</step>
<milestone date="2026-11-14">First draft to the tutor</milestone>
<criterion marks="40">Critical analysis</criterion>
<criterion>Referencing</criterion>

`marks` is optional: a number from 1 to 100, the percentage the part or criterion is worth, only when the brief
says. Leave it out when you don't know. `date` is optional too, as year-month-day. The student pastes your whole reply into ViStud, which reads the marks
and ignores everything else.
