# ViStud 2 · Phase 4 (Progress that rolls up): handoff

Branch `claude/persistent-study-context-zsilo6`. The plan is [docs/specs/vistud-2-blueprint.md](../specs/vistud-2-blueprint.md) (Part 4, Phase 4; §3.5.5, §3.8). Phase 3 is in [v2-phase-3.md](v2-phase-3.md).

## What was built

**One set of numbers** (`App\Study\Rollups`, `CourseRoll`, `ModuleRoll`, `TopicRoll`)
- A topic is *understood* when the student says so or the evidence has earned it *mastered*. A module is those out of its topics; the course is the sum over its modules, so a 2-topic module weighs what its 2 topics do, not what a 10-topic one does. Topics in no module make a group of their own, **No module**.
- Built once per page: a handful of queries however big the course (a test checks the count does not grow), and the journal is read once for the topics and the questions together. Nothing is kept between requests, so a number is never behind what was just saved.
- **Needs attention** (feeds the Next line and the Progress filter): a topic **confusing for 7 or more days**; a **stuck question** about it; its **latest test under 50 %** (the student's own word after the test clears it); **cards 7 or more days overdue** (`Flashcards::counts` now returns `overdue` for the course, each topic and each module). A module's **tested %** is the score of its latest quiz or test.
- The course home (the ring, the module rows with *tested 90 %*, *N topics need another look* linking to Progress's **Needs attention**), the Modules page cards and Progress all read the same roll, so their numbers agree. `NextStep` now gets the attention list (*"Go over Paging again"*) and the topics the reader found (*"Add the topics found in …"*), which had no feeder before.

**Progress is a tree** (`App\Livewire\Workspaces\Progress`, `progress.blade.php`)
- The course ring and **"40 % · 2 of 5 topics"**; filters **All · Needs attention · Not started · Mastered** with counts (`?filter=`, kept in the address).
- Modules as cards with a bar, "1/2", *tested …* and *N to look at*; the module the student is in (where they last studied, else the one running today, else the first) is open, the rest folded (a tap opens one; Select opens all). **No module** comes last.
- A topic row: its name (opens the topic sheet), where it stands (icon and one word; a tooltip says what the practice shows), *since 2 Oct · 2 cards, 1 due · 1 session*, what needs another look and why, **Study**, and a ⋯ menu (New flashcard, New question, Move to module…, Move up/down in its module, Remove). Select still sets a status for several topics, moves or removes them.
- Gone: the three status buttons per row, the *Evidence:* line, the findings list and its dialog. The questions line and board stay below the tree until the Questions section (Phase 6); the week bars and study time stay at the bottom.

**The topic sheet** (shared with the module page)
- Says **"Set by the tutor, 5 Oct. Pick another to change it."** when the tutor set the status; the student's pick replaces it.
- **Key points**: add one, remove one (each says where it came from, if it did); **Remove topic** asks first and says what stays in the journal.

**Tests added or changed**: `Study/RollupsTest` (weights, no-module group, mastered, every attention rule and its order, the test cleared by the student's word, current module, one pass: query count), `Study/FlashcardsTest` (overdue), `Web/ProgressScreenTest` (tree, current module open, filters, tutor-set chip, Study, add/move/reorder/remove), `Web/TopicSheetScreenTest` (tutor-set, key points, remove, locked ids, another student's key point), `Web/CourseHomeScreenTest` (attention row, topics found, tested %), `Web/ModulesScreenTest` (tested %), `Web/TrackerScreensTest` and `Web/FlashcardScreensTest` follow the page. Browser: `progress.spec.js` rewritten (tree, folding, filters, home agrees, sheet, Study, add/select/move, colours from tokens on desktop and phone, axe in three themes, 320 px, phone sheet), `capture.spec.js` and `tracker.spec.js` follow the page. Previews: `progress-*` regenerated.

## What Abel tests (plain steps)

Use your OS course. Never paste a key or a token into a chat with any AI.

1. Open **Progress**. At the top: the ring and **"N % · X of Y topics"**. Open the course **Overview**: the ring there says the same number.
2. The module you last studied is open; the others are folded. Tap one: its topics appear. Each topic says where it stands in one word.
3. Press **Needs attention**: only topics that need another look are left (a topic still confusing after a week, one with a stuck question, one you scored under 50 % in a test, one with cards a week late). The Overview's *"N topics need another look"* leads to the same list, and the **Next** line can say *"Go over … again"*.
4. Tap a topic's name: the sheet shows where you stand, cards, open questions and key points. Add a key point and remove it. If the tutor marked the topic it says so; pick another status and it becomes yours.
5. **Study** on a row starts a session on that topic. **⋯** moves a topic up or down in its module, to another module, or removes it.
6. **Select** (top right) lets you tick several topics and mark them Covered, Understood or Still confusing, or move or remove them.
7. **Modules** page: each card shows *"Topics understood 3/5"* and, after a quiz or test, *"tested 80 %"*.
8. On the phone: the tree is one column; a topic's sheet fits the screen; nothing slides sideways.

## What is left or deferred

- *Mastered* needs real practice (it is earned from the evidence), so it stays at 0 until the journal has enough; the filter and the count are there.
- A stuck question with no topic is counted on the course but is not a topic row, so it does not lead the Next line.
- The questions line and board are still on the Progress page; the Questions section (Phase 6) takes them.
- Old CSS for the three-button status control and the findings list is unused and goes in Phase 7.

## Message for the local tester (Gemini)

```
Please bring your copy up to date with the latest ViStud work. Do exactly these steps and nothing else.
Important: do not open the AI settings or AI engine pages, and do not read, write or paste any key or token anywhere.

1. git fetch origin claude/persistent-study-context-zsilo6
2. git checkout claude-latest
3. git merge --ff-only origin/claude/persistent-study-context-zsilo6
   (if it says it cannot fast-forward, stop and tell me; do not force anything)
4. composer migrate
   (no new migration in this step; never use migrate:fresh)
5. npm run build
6. Run only these tests, and tell me the pass/fail counts and the text of any failure:
   php artisan test --compact tests/Feature/Study/RollupsTest.php tests/Feature/Study/FlashcardsTest.php tests/Feature/Web/ProgressScreenTest.php tests/Feature/Web/TopicSheetScreenTest.php tests/Feature/Web/CourseHomeScreenTest.php tests/Feature/Web/ModulesScreenTest.php tests/Feature/Web/TrackerScreensTest.php

Do not push anything. Do not run the whole test suite or the browser tests (the PC is slow).
```
