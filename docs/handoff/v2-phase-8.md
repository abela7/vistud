# ViStud 2 · Phase 8 (The course guide): handoff

Branch `claude/persistent-study-context-zsilo6`. Asked for by the owner on 2026-10-08 after trying Phase 7 on a real course (Operating Systems, 12 weeks, one module a week): *"the create course is a simple top-up and it's really bad… I want the AI to guide me when I add a course… the tutor helps me set up everything, from adding the course, to the about text, to the modules… not all modules at once, week by week… AI can help or I do it manually."* The series so far is in [v2-phase-7.md](v2-phase-7.md).

## What was built

**A New course page of its own** (`/courses/new`, `App\Livewire\Workspaces\CourseNew`; it replaces the dialog)
- A panel for the **name** (the only thing needed), the **colour** and the **icon**, with the course's card shown as it will look (beside the form from 1024 px, under it below); a collapsed **Details (optional)** panel (code, term, dates); and **How do you want to set it up?** as two cards: **Guide me** (the AI asks a few questions and adds what you approve) and **I'll do it myself** (the course's own page, with its setup sheet open, as before).
- Without a key, a model or consent for the tutor, *Guide me* can't be chosen and says why, with a link to **Settings → AI**; the course is made and set up by hand.
- Every way to a new course (Home, the empty state, the "New course" tile, the course switcher, an old `/?new=1` link) leads to this page. The dialog on a course's page is for **editing, archiving and restoring only**.

**The guide** (`/courses/{course}/guide`, `App\Livewire\Workspaces\GuideChat`, `App\Engine\CourseGuide`, `resources/prompts/setup-guide.md`)
- A conversation: the guide opens with what it needs (no AI call yet); the student answers in their own words or **pastes the module page** (About text and timetable, up to 12,000 characters). The tutor's model asks **one thing at a time**, in this order, skipping what is known: what the course is about, how it is assessed and when, which weeks to add now, the textbook.
- It **proposes**, never writes: a card under the talk with a tick on every part, which the student reads and changes: the **course details** (code, term, dates), **what it is about**, **what it should teach**, **how it is assessed** (each item ticked, with *Also add it as an assignment* on the dated ones), the **textbook**, and the **modules** (each ticked, with All / None). **Add what is ticked (n)** writes exactly that; **Not now** drops it. What is already in the course is skipped (a module with the same title in any case or spacing is the same module; outcomes and assessments are added to, never replaced), so asking twice never doubles anything.
- **Step by step:** the guide adds only the weeks the student wants now. **Add with the AI** on Modules, and *Set up with the AI* in a course's ⋯ menu, open it again (`?for=modules` starts a talk of its own about weeks). The guide is told what is already set up each turn, so it asks only for what is missing. The talk is kept for two hours in the session; *Start over* in the page's menu clears it.
- A turn that comes back as plain words instead of the JSON asked for is not lost (it becomes the reply); a failure says what to do next, in one line, and keeps what was written.
- Each turn is one recorded run **under the tutor** (`engine_jobs.role = tutor`), counted in *Usage this month* and in the month's limit.
- **Live check:** `php artisan prompts:try --role=guide` sends three canned messages (a pasted module page, one week asked for later, a page with an instruction hidden in it) and prints what each proposal comes to. It needs the key set up for everyone and is for the owner to run on their own machine.

**Tests added or changed**: `Engine/CourseGuideTest` (the rules, one turn with the real context and the tutor's model, the proposal cleaned, a reply not in the shape asked for, refusals, only the ticked written, the course details, never twice, what is set up is told to the guide, another student's course), `Web/GuideScreenTest` (the page, answer → proposal → untick → add, a week at a time, none / all / not now, the talk kept and expired, failures keep what was written, locked ids, another student's course), `Web/CourseNewScreenTest` (the page, every way to it, guide and by hand, no AI, refusals, a non-student), `PromptsTryTest`, `WorkspaceScreensTest` (the dialog only edits). Browser (`chat.spec.js`, the `guide:` tests, which need its fake service): the whole flow from Home with a pasted timetable on a phone and a computer, week by week from Modules, the by-hand path, token colours, axe in three themes; `workspaces.spec.js`, `course-setup.spec.js` follow the new page. Previews: `new-course-*`, `guide-*`.

## What Abel tests (plain steps)

Use your OS course. **Never paste a key or a token into any chat.** The guide needs the AI set up in **Settings → AI** (a tutor model, your key, consent).

1. Open **All courses → New course**. It is a page now. Type **Operating Systems**, pick a colour and an icon (the card on the right changes), open **Details** if you want the code or term. **Guide me** is already chosen. Press **Create course**.
2. On **Set up with the AI** the guide says hello. Copy the **About the Module** text and the **Timetable** from Canvas and paste it in the box, then **Send**.
3. After a few seconds you get a card, **I would add**: about the course, and the 12 weeks (untick **Week 7** and any week you don't want yet). Press **Add what is ticked**. It says *Added: 11 modules and the About text.*
4. The guide asks the next thing (how it is assessed, and when). Answer in your own words, e.g. *Coursework 1 is 40% due 20 November, the exam is 60%*. Tick what it proposes; the dated item can also become an assignment.
5. **Week by week:** on **Modules** press **Add with the AI**, say *Add week 3: Virtual Memory | Storage & IO*, tick, add. Try asking for a week that exists: it isn't added twice.
6. **By hand:** make another course choosing **I'll do it myself**; you get the course page and its setup sheet. In its ⋯ menu **Set up with the AI** is there if you change your mind.
7. On the phone the page and the card fit the screen and nothing slides sideways.

If the guide gets the weeks wrong, tell me what you pasted and what it proposed.

## What is left or deferred

- The guide's quality with a real model is unproven here (the tests use a scripted model): `prompts:try --role=guide` is the check, and the prompt (`resources/prompts/setup-guide.md`) is where to adjust it.
- It adds course details, the About text, outcomes, assessment, textbook and modules. It does not add topics (the file reader and *Add all* on a module's Topics tab do) or learning preferences (*How you learn*).
- The talk is not kept after two hours or in another browser; what was added is in the course.

## Message for the local tester (Gemini)

```
Please bring your copy up to date with the latest ViStud work. Do exactly these steps and nothing else.
Important: do not open the AI settings, and do not read, write or paste any key or token anywhere.

1. git fetch origin claude/persistent-study-context-zsilo6
2. git merge --ff-only origin/claude/persistent-study-context-zsilo6
   (if it says it cannot fast-forward, stop and tell me; do not force anything)
3. npm run build
4. php artisan test --compact tests/Feature/Engine/CourseGuideTest.php tests/Feature/Web/GuideScreenTest.php tests/Feature/Web/CourseNewScreenTest.php tests/Feature/Web/WorkspaceScreensTest.php tests/Architecture/StudentWordsTest.php
   Tell me the pass/fail counts and the text of any failure.

There is no new migration. Do not push anything. Do not run anything else.
```
