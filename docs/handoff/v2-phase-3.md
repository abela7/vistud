# ViStud 2 · Phase 3 (the session, chat first): handoff

Branch `claude/persistent-study-context-zsilo6`. The plan is [docs/specs/vistud-2-blueprint.md](../specs/vistud-2-blueprint.md) (Part 4, Phase 3; §3.5.4, §3.8, §3.9, Appendix B). Phase 2 is in [v2-phase-2.md](v2-phase-2.md).

## What was built

**A session has a mode** (migration `2026_10_07_120000_add_session_modes_topic_status_by_and_quizzes`; `Sessions::MODES`)
- `study_sessions.mode`: `module`, `topic`, `quiz`, `test` or `free`. Not given, a module alone means `module` and anything else `topic` (`free` is only ever asked for). **Study this ▾** passes the mode (Whole module, Quiz me, Test me), and a quiz or a test opens with its ask waiting in the message box (the tutor says nothing until the student sends it). The tutor is told the mode in one line of the session layer, and its rules have a short paragraph for each (`resources/prompts/tutor-2.md`, still within its 2,500-token budget).

**Topics say who set their status** (`topics.status_by`, `status_at`; `Topics::mark`, `unmark`)
- The student's word always wins: the tutor can't change a status the student set, only suggest. A status the tutor sets is shown as the tutor's, can be undone, and is only the row (never journal evidence).

**Two tutor tools** (`set_topic_status`, `record_quiz`; `App\Study\Quizzes`, table `quizzes`)
- `set_topic_status` (covered, understood, confusing, with the reason): with *Let the tutor mark topics* on it is set at once and the chat shows **"Joins: understood, marked by the tutor" · Undo**; with it off it is a suggestion with **Mark it**.
- `record_quiz`: once, after the score is told: each question, the answer, how it went. Kept as a readable record with its score (correct counts one, partial half: worked out from the results, there is no `score` input) and each answer is also a journal attempt. A **test** proposes each topic's status from its score: 80 % or more understood, 50 % or less still confusing, otherwise covered, set or proposed the same way.

**The session is the conversation** (`StudySession`, `TutorChat`, `study-session.blade.php`, `partials/session-rail.blade.php`)
- Header (the page template): the module above the title (the mode word or the topic), the clock with **Pause** and **End** in the action slot, and the ⋯ menu (How the AI teaches, Pomodoro, Tell the tutor about this module, Take a break, Delete session; **Briefing for another AI** and **Save from another AI** only when the student turned on *I use another AI by copy-paste*). A Pomodoro session keeps its clock bar under the header.
- The chat fills the page; under it the **chips** (Quiz me ▾ with Test me, Cards, Note this, Where are we) and the box, whose **+** menu holds *Attach a note, file or picture*, *Ask a question*, *New flashcard* and *Write a note*. The six tiles, the questions board and the timeline are gone (the module's Questions and Sessions tabs have them).
- The **rail** beside it (a sheet from the topic name on a phone): the module's topics (tap to switch the session's topic; the session's is marked **Now**; a tutor-set status is said to be the tutor's), the material (open it, or let the tutor read it), and *This session* (what was saved: cards, key points, questions, quizzes).
- **End** is one screen: the time, each topic the session touched with where the student stands (Leave it · Covered · Understood · Still confusing, starting on what the tutor marked or suggested), what was saved; then *Session ended* with the tutor's summary and **Done** (back to the module). What the student picks becomes their own word.

**Tests added or changed**: `Study/QuizzesTest`, `Study/TopicsTest` (mark/undo/the student's word), `Study/SessionsTest` (modes), `Engine/ToolboxTest` (both tools, both settings), `Engine/SessionChatTest` (statuses and quizzes in the transcript and the session's activity), `Engine/StackTest` (the mode line, who set the status, the budgets), `Web/SessionChatFirstTest` (modes and header, chips, rail, status chips with undo and accept, end screen, + menu), `Web/SessionScreensTest`, `BriefingScreensTest`, `QuestionBoardTest`, `SessionCaptureScreenTest`, `TutorChatScreenTest` follow the new page. Browser: `chat.spec.js` (chips, + menu, status chip and the end screen, phone rail sheet and the box in view), `sessions.spec.js` (header, rail, end screen, token colours, axe in three themes, 320 px); the fake engine in `tests/Browser/fixtures/fake-engine.php` can mark a topic ("mark it").

## What Abel tests (plain steps)

Use your OS course. Never paste a key or a token into a chat with any AI. The tutor needs your key, a model and consent in **AI settings**.

1. Open a module → **Study this ▾** → **Whole module**. The page is now the chat, with the clock, **Pause** and **End** at the top and a rail on the right (topics, material, *This session*). On a phone, tap the topic name to see the rail.
2. Say hello. Say "next" a few times. Tap a different topic in the rail: the header and the tutor follow it.
3. Tap the chips: **Cards** (the tutor makes cards of what you covered), **Note this**, **Where are we**. The **+** menu has *Ask a question* (it goes to the module's Questions tab), *New flashcard*, *Write a note*, *Attach*.
4. Ask the tutor to quiz you ("quiz me"): five questions, one at a time. At the end it tells you the score and a chip shows what it kept. Check **Settings → AI settings → Let the tutor mark topics**: on, the chat shows *"…marked by the tutor" · Undo*; off, it shows *"…?" · Mark it*.
5. **Study this ▾ → Test me**: ten or more questions over the module; the topics get a status from the score (understood at 80 % or more, still confusing at 50 % or less).
6. Press **End**: the time, each topic with where you stand (the tutor's choice is already ticked; change it if you disagree), what was saved. **End session** shows the tutor's summary; **Done** goes back to the module, whose topics now show the statuses.
7. Open the ⋯ menu: there is no *Briefing for another AI* until you turn on *I use another AI by copy-paste* in AI settings.
8. On the phone: the box stays above the tab bar while you type, and the page does not slide sideways.

## What is left or deferred

- The progress ring and the course's needs-attention row still use the old numbers: the roll-ups and the tree are Phase 4 (`quizzes` has what "tested 90 %" needs).
- *Material* in the rail lets the tutor read a note or file for the whole session; attaching one to a single message is still the **+** menu's picker.
- A started Quiz or Test shows its ask in the box for the student to send; the tutor does not begin by itself.
- The tutor's *Cards*, *Note this* and *Where are we* chips are plain asks; the tutor decides how.
- The old paste-in "Save from the chat" screen and the briefing remain, behind the copy-paste setting; the standalone card maker goes in Phase 5/7.

## Message for the local tester (Gemini)

```
Please bring your copy up to date with the latest ViStud work. Do exactly these steps and nothing else.
Important: do not open the AI settings or AI engine pages, and do not read, write or paste any key or token anywhere.

1. git fetch origin claude/persistent-study-context-zsilo6
2. git checkout claude-latest
3. git merge --ff-only origin/claude/persistent-study-context-zsilo6
   (if it says it cannot fast-forward, stop and tell me; do not force anything)
4. composer migrate
   (two new migrations since last time: file_digests, topic_suggestions and module_briefs; then session modes, topic status_by and quizzes; never use migrate:fresh)
5. npm run build
6. Run only these tests, and tell me the pass/fail counts and the text of any failure:
   php artisan test --compact tests/Feature/Study/QuizzesTest.php tests/Feature/Study/TopicsTest.php tests/Feature/Study/SessionsTest.php tests/Feature/Engine/ToolboxTest.php tests/Feature/Engine/SessionChatTest.php tests/Feature/Engine/StackTest.php tests/Feature/Web/SessionChatFirstTest.php tests/Feature/Web/SessionScreensTest.php tests/Feature/Web/TutorChatScreenTest.php tests/Feature/Web/ModulePageScreenTest.php

Do not push anything. Do not run the whole test suite or the browser tests (the PC is slow).
```
