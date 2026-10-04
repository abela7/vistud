# ViStud 2 · Phase 7 (Retire and tidy): handoff, and the end of the series

Branch `claude/persistent-study-context-zsilo6`. The plan is [docs/specs/vistud-2-blueprint.md](../specs/vistud-2-blueprint.md) (Part 4, Phase 7). Phase 6 is in [v2-phase-6.md](v2-phase-6.md). The whole series: [0](v2-phase-0.md) · [1](v2-phase-1.md) · [2](v2-phase-2.md) · [3](v2-phase-3.md) · [4](v2-phase-4.md) · [5](v2-phase-5.md) · [6](v2-phase-6.md) · 7.

## What was done

**One way to do each thing**
- **Words:** no student screen says an internal word any more. The journal is the **study record** (Settings → Your data → *Open your study record*), what a student pastes into another AI is a **prompt** (*Prompt for another AI*, *Prompt text*), the tutor's tags are found with **Find what to save**, and Settings' first part is **AI** (not "AI engine"). The owner's admin pages keep their own words.
- **A guard for it:** `tests/Architecture/StudentWordsTest.php` fails when a student-facing Blade view (its text, titles, hints, labels, and the capitalised labels in its PHP) or a notice or error a screen's component gives contains *workspace, evidence, write-back, briefing, journal, engine, finding, overview* or *the tutor's marks*. Admin pages and the owner's labels in the shared layout are listed as exempt; a test checks the guard itself sees what it should.
- **Removed:** the unused styles for the old Progress status buttons (`.topic-row .segmented*`), the findings list (`.disclosure`, `.findings`, `.finding-row`), the session tiles (`.action-grid`, `.action-tile*`) and `.place-badge`; the design mockups of the Workspaces plan (`/_mockups/…`, their views, their spec and their screenshots; the real screens replaced them); the layout's `sidebar` and `tabbar` slots that only the mockups used; every preview screenshot of a screen that no longer exists. A scan found no view, Livewire component, public Livewire method or PHP class that nothing uses, and none of the old Overview panels, the six session tiles or the flat Progress is left.
- **Kept on purpose:** the data and the services (the journal, evidence, findings); the `Evidence:` line of the prompt for another AI (it is for the AI, not a student screen); the copy-paste card maker's sheet behind the *I use another AI by copy-paste* setting.

**Docs match the product**
- `docs/specs/study-memory.md` and `docs/specs/workspaces.md` say at the top that the blueprint supersedes them where they differ; `docs/architecture/schema.md` lists every table and every column added since (it was missing `workspaces.project_tools`; a check found nothing else missing).
- `DESIGN.md`: the ✦ menu (§5.9), the writing rules with the new words and the guard (§5.10), a course's six doors, the phone's tab bar and Settings (§6.1), dialogs as bottom sheets.
- README and PROJECT already point to the blueprint; this note closes the series.

**Browser specs brought up to date** (they had followed the old screens): the 2FA helper goes through Settings → Security; note specs follow `/courses/…`; the pinned-notes spec opens the module's Notes tab; Progress and the helper's question test use the Questions page; `plan-extras.spec.js` turns project tools on; the setup sheet's axe check scrolls its new row clear of the sheet's pinned buttons.

## Tests run

Full PHP suite and the whole browser suite on the final state (numbers in the commit that closes this phase and in the answer to Abel). Previews regenerated: `docs/design/previews/`.

## What Abel tests (the whole of ViStud 2, plain steps)

Use your OS course. **Never paste a key or a token into a chat with any AI.** The AI parts need your key and consent in **Settings → AI**, and a model for each of the tutor, the reader and the helper.

1. **Set up a course.** *New course* → name it → the setup sheet: upload the syllabus (or *Write it myself*) → tick the modules and the dated assessments → *Add*. Home says what to do next.
2. **Modules.** Open a module → *Files* → drop in a lecture file. After a few seconds it says it was read; *Topics* offers the topics found → *Add all*.
3. **Study.** On Home or a module tap **Study** → *Study this ▾* offers *Learn it · Whole module · Test me · Practise · Free chat*. The session is a conversation: say hello, ask for a quiz, answer. The tutor marks topics as you go (*set by the tutor*, with an undo). End it: one screen says what you did and what is next.
4. **Progress.** One number for the course, then every module with its topics; the module you are in is open. *Needs attention* shows what to look at. Tap a topic: its sheet has the note the tutor set, key points, and *Remove topic*.
5. **The ✦.** On a card *Improve*; on a file *Summarise*, *Make a note*, *Make cards*; on a question *Clarify*, *Answer from my notes*; on a note select words → ✦ → *Shorten*. Each shows before and after; nothing changes until **Keep**. **Ask** (top bar; a tab on the phone) answers a quick question about the course.
6. **Cards.** *Make cards from…* → pick a topic, a note or a file → untick any → *Add*. *Review* runs the ladder.
7. **Questions** has its own page (and a ✦ for the AI help). **Assignments**: the plan; in the course's ⋯ → *Edit course* → *Project tools for assignments* adds milestones, a team and *how it is going*.
8. **Settings**: AI · Appearance · Security · Your data (*Open your study record*).
9. **Phone**: Home · Modules · Cards · Ask · More; every pop-up is a sheet from the bottom with its buttons in view; text larger and the screen turned sideways: nothing slides sideways.
10. **Old links**: change `/courses/` to `/workspaces/` in the address: the same page opens.

## What is left or deferred (the whole series)

- Voice input (a separate decision; the helper never writes anything on its own).
- *Where should these go?* only advises; files and notes made by the AI from a file go straight to their place (reviewed on the sheet), not through the session write-back.
- Calendar events for milestones still show on a course that turned project tools off again.
- Models: Abel's new idea about them is not built; the three roles and their settings are the place to add it.
- `prompts:try` (a live check of the prompts) is for Abel to run on his own machine with his key; it was never run live here.

## Message for the local tester (Gemini)

```
Please bring your copy up to date with the latest ViStud work (the whole of ViStud 2, phases 1 to 7). Do exactly these steps and nothing else.
Important: do not open the AI settings, and do not read, write or paste any key or token anywhere.

1. git fetch origin claude/persistent-study-context-zsilo6
2. git checkout claude-latest
3. git merge --ff-only origin/claude/persistent-study-context-zsilo6
   (if it says it cannot fast-forward, stop and tell me; do not force anything)
4. composer migrate
   (new migrations add columns and tables; never use migrate:fresh and never delete any data)
5. npm run build
6. Run only these tests, and tell me the pass/fail counts and the text of any failure:
   php artisan test --compact tests/Architecture/StudentWordsTest.php tests/Feature/Web/NavigationTest.php tests/Feature/Web/AssignmentScreensTest.php tests/Feature/Web/ProgressScreenTest.php tests/Feature/Web/AiMenuScreenTest.php tests/Feature/Web/CourseHomeScreenTest.php tests/Feature/Web/SessionChatFirstTest.php

Do not push anything. Do not run the whole test suite or the browser tests (the PC is slow).
```
