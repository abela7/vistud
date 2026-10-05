# ViStud 2 · Phase 8 (The course guide): handoff

Branch `claude/persistent-study-context-zsilo6`. Asked for by the owner on 2026-10-04 after trying Phase 7 on a real course (Operating Systems, 12 weeks, one module a week): *"the create course is a simple top-up and it's really bad… I want the AI to guide me when I add a course… the tutor helps me set up everything, from adding the course, to the about text, to the modules… not all modules at once, week by week… AI can help or I do it manually."* The series so far is in [v2-phase-7.md](v2-phase-7.md).

**Reshaped on 2026-10-04** after the owner tried it: *"I was adding a course but the model asks me to add modules. I don't need that. The first job is adding the course. The modules should be handled by the module page: the AI reads the about course and helps on the module page."* The guide is now two talks, each with its own job and its own prompt: setting the **course** up (never modules), and adding **modules** from Modules (having read the course). The sections below describe the result.

## What was built

**A New course page of its own** (`/courses/new`, `App\Livewire\Workspaces\CourseNew`; it replaces the dialog)
- A panel for the **name** (the only thing needed), the **colour** and the **icon**, with the course's card shown as it will look (beside the form from 1024 px, under it below); a collapsed **Details (optional)** panel (code, term, dates); and **How do you want to set it up?** as two cards: **Guide me** (the AI asks a few questions and adds what you approve) and **I'll do it myself** (the course's own page, with its setup sheet open, as before).
- Without a key, a model or consent for the tutor, *Guide me* can't be chosen and says why, with a link to **Settings → AI**; the course is made and set up by hand.
- Every way to a new course (Home, the empty state, the "New course" tile, the course switcher, an old `/?new=1` link) leads to this page. The dialog on a course's page is for **editing, archiving and restoring only**.

**The guide** (`/courses/{course}/guide`, `App\Livewire\Workspaces\GuideChat`, `App\Engine\CourseGuide`) has two talks. Each is a conversation that **proposes, never writes**: a card under the talk with a tick on every part; *Add what is ticked (n)* writes exactly that; *Not now* drops it. What is already in the course is skipped (a module with the same title in any case or spacing is the same module; outcomes and assessment are added to, never replaced), so asking twice never doubles anything. Each turn is one recorded run **under the tutor** (`engine_jobs.role = tutor`, kind `setup_guide` or `module_guide`), counted in *Usage this month* and in the month's limit. The talk is kept for two hours in the session (one per talk); *Start over* in the page's menu clears it.

- **Setting the course up** (`resources/prompts/setup-guide.md`; the page *Guide me* opens, and *Set up with the AI* in a course's ⋯ menu): the guide opens with what it needs (no AI call yet); the student answers in their own words or **pastes the module page** (up to 12,000 characters). It asks **one thing at a time**, in this order, skipping what is known: what the course is about, how it is assessed and when, the textbook. It proposes the **course details** (code, term, dates), **what it is about**, **what it should teach**, **how it is assessed** (each item ticked, with *Also add it as an assignment* on the dated ones) and the **textbook**. **It never proposes modules**: if the pasted page has a timetable it leaves the weeks out and says Modules adds them next, and anything it offers about modules is dropped by the app anyway. When the About text is in, the guide says the course is set up and the weeks come next, and *Go to Modules* appears beside *I'm done for now*.
- **Adding modules** (`resources/prompts/module-guide.md`; `?for=modules`, from **Add with the AI** on Modules and the first button of Modules when it is empty; its back link goes to Modules): the guide **has read the course**: what it is about (up to 1,500 characters), what it should teach, how it is assessed with its dates, the course's dates and the modules already there. It asks which weeks or chapters to add now: the student pastes the timetable, tells the weeks, or asks it to suggest some (it says they are its suggestions). It proposes **only modules** (anything else it offers is dropped), each ticked, with All / None. Weeks can be added a few at a time, whenever; **Week 7 (a reading week) is left out** unless asked.
- **Pictures** (added the same day, after the owner couldn't attach one): in either talk the student can attach up to four pictures to a message (a screenshot of the Canvas page or timetable): *Attach pictures* beside Send, or paste (Ctrl + V) or drop a screenshot into the box. They show beside the box with a remove button; anything that is not a PNG, JPG, GIF or WebP, or over 10 MB, is refused at once. The model is shown them with that message only, shrunk to what it needs (`Attachments::shrink`); **they are not stored anywhere** (the temporary copy is deleted once sent), only what the student ticks is added to the course. A tutor model that can't see pictures says so (the model list marks those that can) and the pictures stay where they are. The same turn can carry words and pictures, or only pictures.
- A turn that comes back as plain words instead of the JSON asked for is not lost (it becomes the reply); a failure says what to do next, in one line, and keeps what was written.
- **Live check:** `php artisan prompts:try --role=guide` sends five canned messages across both talks (a pasted module page with a timetable, a page with an instruction hidden in it, weeks told one at a time, a request for suggestions, a timetable with an instruction hidden in it) and prints what each proposal comes to. It needs the key set up for everyone and is for the owner to run on their own machine.

**Tests added or changed**: `Engine/CourseGuideTest` (the rules of each talk, a turn with the real context and the tutor's model, the modules talk reading the course, each talk keeping to its own job, the proposal cleaned, a reply not in the shape asked for, refusals, only the ticked written, the course details, never twice, what is set up is told to the guide, another student's course), `Web/GuideScreenTest` (the page in both talks, answer → proposal → untick → add, a week at a time, none / all / not now, the talk kept and expired, failures keep what was written, locked ids, another student's course), `Web/CourseNewScreenTest`, `PromptsTryTest`, `WorkspaceScreensTest`; pictures: shown to the model with that message only, only pictures, too many / not a picture / a model that can't see, the talk starting with the student's turn. Browser (`chat.spec.js`, the `guide:` tests, which need its fake service): the whole flow from Home (set the course up, then Modules, week by week, never twice), the by-hand path, token colours, axe in three themes for both talks; `workspaces.spec.js`, `course-setup.spec.js`. Previews: `new-course-*`, `guide-*`.

## What Abel tests (plain steps)

Use your OS course. **Never paste a key or a token into any chat.** The guide needs the AI set up in **Settings → AI** (a tutor model, your key, consent).

**Part 1: the course**
1. Open **All courses → New course**. It is a page now. Type **Operating Systems**, pick a colour and an icon (the card on the right changes), open **Details** if you want the code or term. **Guide me** is already chosen. Press **Create course**.
2. On **Set up with the AI** the guide says hello. Copy the **About the Module** text from Canvas (the Timetable can be in it too, the guide leaves the weeks for later) and paste it in the box, then **Send**.
3. After a few seconds you get a card, **I would add**: what the course is about, and maybe what it should teach and how it is assessed. **There are no weeks in it.** Untick anything you don't want, press **Add what is ticked**. It says *Added: the About text…*
4. The guide asks the next thing (how it is assessed, and when). Answer in your own words, e.g. *Coursework 1 is 40% due 20 November, the exam is 60%*. Tick what it proposes; the dated item can also become an assignment.
5. When it says the course is set up, press **Go to Modules**.

**Part 2: the weeks (on the Modules page)**
6. On **Modules**, press **Add with the AI** (an empty Modules page offers it first). The guide says it has read what your course is about. Paste the **Timetable** (or say *Add week 1 and week 2*), **Send**, untick what is not for now (Week 7 is a reading week), press **Add what is ticked**.
6a. **Pictures:** instead of pasting, take a screenshot of the Canvas page (Windows: Win + Shift + S), click in the message box and press **Ctrl + V**: it appears under the box; or press **Attach pictures** and choose a picture file. Send it with a few words or none, and see what it proposes. Try a PDF: it says it isn't a picture.
7. Another day: **Add with the AI** again, say *Add week 3: Virtual Memory | Storage & IO*. Ask for a week that exists: it isn't added twice. Or say *I have no timetable, suggest some weeks* and see what it proposes.

**Part 3: by hand**
8. Make another course choosing **I'll do it myself**; you get the course page and its setup sheet. In its ⋯ menu **Set up with the AI** is there if you change your mind. On Modules, **Add one myself** is the manual way.
9. On the phone the page and the card fit the screen and nothing slides sideways.

If the guide gets the weeks or the course text wrong, tell me what you pasted and what it proposed.

## What is left or deferred

- The guide's quality with a real model is unproven here (the tests use a scripted model): `prompts:try --role=guide` is the check, and the prompt (`resources/prompts/setup-guide.md`) is where to adjust it.
- The course talk adds course details, the About text, outcomes, assessment and textbook; the modules talk adds modules. Neither adds topics (the file reader and *Add all* on a module's Topics tab do) or learning preferences (*How you learn*).
- The talk is not kept after two hours or in another browser; what was added is in the course.
- A timetable pasted while setting the course up is not kept for the Modules talk: it is pasted again there (the same Canvas page does for both).

## Message for the local tester (Gemini)

```
Please bring your copy up to date with the latest ViStud work. Do exactly these steps and nothing else.
Important: do not open the AI settings, and do not read, write or paste any key or token anywhere.

1. git fetch origin claude/persistent-study-context-zsilo6
2. git merge --ff-only origin/claude/persistent-study-context-zsilo6
   (if it says it cannot fast-forward, stop and tell me; do not force anything)
3. npm run build
4. php artisan test --compact tests/Feature/Engine/CourseGuideTest.php tests/Feature/Engine/PromptsTryTest.php tests/Feature/Web/GuideScreenTest.php tests/Feature/Web/CourseNewScreenTest.php tests/Feature/Web/WorkspaceScreensTest.php tests/Architecture/StudentWordsTest.php
   Tell me the pass/fail counts and the text of any failure.

There is no new migration. Do not push anything. Do not run anything else.
```
