# ViStud 2 · Phase 1 (course setup and the course home): handoff

Branch `claude/persistent-study-context-zsilo6`. The plan is [docs/specs/vistud-2-blueprint.md](../specs/vistud-2-blueprint.md) (Part 4, Phase 1; §3.3, §3.5.1, §3.5.2). Phase 0 is in [v2-phase-0.md](v2-phase-0.md).

## What was built

**The course profile and how the student likes to learn** (migration `2026_10_07_100000_create_course_and_learner_profiles`)
- `course_profiles`: what the course is about, what it should teach, how it is assessed, its textbook, and the modules the reader found until the student ticks them. `learner_profiles`: four short answers per course (how you like things explained, pace, how often to be checked, your goal) and one line of your own. Both are learner tables, one row per course, made when first written, deleted with the course. Services `App\Study\CourseProfiles` and `LearnerProfiles` (checked, learner-isolated).
- The answers become **how a session teaches** by default (`LearnerProfiles::teaching` → the four teaching choices); a session can still override them, and what the student chose last is remembered, unless they answered "How you learn" again since.

**The reader reads a syllabus** (`App\Engine\Jobs\ProfileCourse`, `resources/prompts/reader-course.md`)
- A syllabus file of the course, or pasted text, goes in; JSON comes out and is cleaned to what the app knows (kinds, dates, limits) into the profile and a proposed module list. Nothing is added to the course until the student ticks it. An unreadable answer, no key, no consent or no words are recorded on the run with a code, never silently.

**The setup sheet** (`App\Livewire\Workspaces\CourseSetup`)
- After **New course** the course opens with a sheet: *What is this course?* (drop a file or paste text → **Read it**; or **Write it myself**) with the modules found as ticks (**Add 12 modules**), the assessment as rows with dates (each dated row can become an assignment), then *How do you like to learn?* (chips). Every step can be skipped. The same two steps are in the course's ⋯ menu: **About this course** and **How you learn**.

**The tutor knows the course** (`Context\Stack`, layers 2 and 3)
- The course layer now carries about, textbook, outcomes, assessment with dates and the student's course instructions; the student layer carries one line of how they like to learn. Both are in the stable front of the prompt and cut a line at a time within their budgets (600 and 250 tokens).

**`NextStep` and the course home** (`App\Study\NextStep`, `CourseHome`; `<x-page>` from Phase 0)
- The pure rule from blueprint §3.3 (every row, one test each) decides one line: *Next: Study Module 1 · Threads: 1 topic left in Module 3 · Review 12 cards (5 min) · ER diagram is due on Friday…*. An open session and a deadline within 24 hours always come first.
- The home: title row with **Study** (no dialog: the module the student is in, the next topic, the clock and teaching they used last), the ⋯ menu (Edit course, About this course, How you learn, Instructions for the AI, Study with options, AI settings, Log time), the Next line, a progress ring (for now: topics understood out of all topics), the modules around where the student is (one marked "where you are"), Coming up and Continue. The streak, the week bars and "cards to review" tiles moved to the bottom of **Progress**; **Study with options** opens the old start dialog.
- `Livewire` event `study-next`; "New course" redirects to `?setup=1`, which opens the sheet.

**Tests added or changed**: `Study/CourseProfilesTest`, `LearnerProfilesTest`, `Engine/ProfileCourseJobTest`, `Unit/Study/NextStepTest`, `Web/CourseSetupScreenTest`, `Web/CourseHomeScreenTest`, `Engine/StackTest` (profile layers); old overview assertions updated in `FlashcardScreensTest`, `ModulesScreenTest`, `SessionScreensTest`, `TrackerScreensTest`, `WorkspaceScreensTest`. Browser: `course-setup.spec.js` (desktop and phone, axe in three themes, token colours); `workspaces.spec.js`, `briefing.spec.js`, `sessions.spec.js`, `tracker.spec.js`, `previews.spec.js` follow the new home.

## What Abel tests (plain steps)

Use your OS course. Never paste a key or a token into a chat with any AI. The AI part needs your key and consent in **AI settings**.

1. **My courses → New course** → name it "Operating Systems" → **Create course**. The course opens with a sheet: *Step 2 of 3 · What is this course?*
2. Drop your syllabus (PDF, Word or PowerPoint) on **Drop the syllabus**, press **Read it**. "Reading the syllabus…" shows for a few seconds. Then you see *About*, *What it should teach*, the *textbook*, *How it is assessed* (with dates) and *Modules found (N)*, all ticked. Check them; untick any you don't want; the button says **Add N modules**. Press it.
3. *Step 3 of 3 · How do you like to learn?* Tap a few answers (all optional). **Done**.
4. The course home says **Next: Add Module 1's files**. The modules are listed with numbers, in order, with their dates. The assignments from your assessment are under **Coming up**.
5. Try the other way too: make another course and press **Write it myself**, or **Skip** both steps. Nothing is required.
6. ⋯ menu → **About this course** and **How you learn** open the same sheet later; what you chose is there.
7. Open a module, add a note, add a topic (as before), come back: the Next line moves on (*Study Module 1*, then *Processes: 3 topics left in Module 1*).
8. Press **Study** at the top (or the button on the Next line): a session opens at once, on the topic named, with no questions asked. In the chat, the tutor already knows the course (try "What is this course about?" and "How will I be assessed?").
9. **Progress** now ends with the streak and the week; the course home has no streak tiles.
10. On the phone: the same home, stacked: Next first, Study under the title. Nothing slides sideways.

## What is left or deferred

- Reading a module's files into topics is Phase 2. Until then, rows 3 of the Next rule (*Add the topics found in …*) can't appear, and the Next line after adding files says *Study Module N* only once topics exist.
- The Next rule's *needs attention* row (a topic confusing for 7+ days, a test under 50 %) has its place and its test, but nothing feeds it yet: the tutor's statuses and test scores arrive in Phase 3, the roll-ups in Phase 4. Likewise the progress ring is simply understood topics over all topics until Phase 4.
- A syllabus is read as text only (a scanned PDF with no words is skipped with "No words found in it"). The first 40,000 characters are used.
- The "New course" dialog keeps its colour, icon, code, term and dates fields; trimming it is part of Phase 6.
- `Workspaces::delete` now also removes the course's profiles and the reader's and helper's runs for it.

## Message for the local tester (Gemini)

```
Please bring your copy up to date with the latest ViStud work. Do exactly these steps and nothing else.
Important: do not open the AI settings or AI engine pages, and do not read, write or paste any key or token anywhere.

1. git fetch origin claude/persistent-study-context-zsilo6
2. git checkout claude-latest
3. git merge --ff-only origin/claude/persistent-study-context-zsilo6
   (if it says it cannot fast-forward, stop and tell me; do not force anything)
4. composer migrate
   (one new migration: course_profiles and learner_profiles; never use migrate:fresh)
5. npm run build
6. Run only these tests, and tell me the pass/fail counts and the text of any failure:
   php artisan test --compact tests/Feature/Study/CourseProfilesTest.php tests/Feature/Study/LearnerProfilesTest.php tests/Feature/Engine/ProfileCourseJobTest.php tests/Feature/Engine/StackTest.php tests/Unit/Study/NextStepTest.php tests/Feature/Web/CourseSetupScreenTest.php tests/Feature/Web/CourseHomeScreenTest.php tests/Feature/Web/WorkspaceScreensTest.php tests/Feature/Web/TrackerScreensTest.php

Do not push anything. Do not run the whole test suite or the browser tests (the PC is slow).
```
