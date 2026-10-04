# ViStud 2 · Phase 6 (Navigation and devices): handoff

Branch `claude/persistent-study-context-zsilo6`. The plan is [docs/specs/vistud-2-blueprint.md](../specs/vistud-2-blueprint.md) (Part 4, Phase 6; §3.4, §3.10). Phase 5 is in [v2-phase-5.md](v2-phase-5.md).

## What was built

**Six doors per course, five tabs on the phone** (`Workspaces::SECTIONS` / `NAV`, `components/workspace/nav`, `tabs`)
- The sidebar of a course: **Home · Modules · Cards · Questions · Assignments · Progress**. *Notes & files* is reached from Modules (*All notes & files*) and the course's *Calendar* from the Home's *Coming up*; both pages still exist at their addresses. Below them an **Everywhere** group: **Courses · Calendar · Settings** (the calendar of every course).
- The phone's tab bar is **Home · Modules · Cards · Ask · More**. *Ask* opens the helper's sheet; *More* opens a sheet with **Questions, Assignments, Progress, Notes & files, Calendar, Settings**, and stays the current tab on those pages.
- **Questions** is a section of its own (it left Progress); Progress is the ring and the tree.
- "Overview" is **Home**, "Flashcards" is **Cards** (the page's own title too), and the way back from a module, folder or note says *Back to Home*.

**Settings, one page with four parts** (`/settings?part=ai|appearance|security|data`)
- **AI engine** (the student's key, models, limits and consent), **Appearance**, **Security** (two-step sign-in), **Your data** (the journal, and notes' export). The **Journal leaves the sidebar**: it is under Your data. The old AI settings address (`/ai-engine`) leads to the first part (301).

**Course pages live at `/courses/…`**
- Every course page is `/courses/{course}/…`; the old `/workspaces/…` addresses answer **301** to the same page, with their query (`?filter=…`, `?module=…`), signed in or not (a signed-out visitor is asked to log in first). Links inside notes, bookmarks and old tabs keep working.

**Project tools are a course setting** (`workspaces.project_tools`, off for a new course)
- Labels, a team, priorities, the person a task is for, milestones and *how an assignment is going* (On track / At risk / Off track) are shown **only in a course that turned them on**: **Edit course → Project tools for assignments**. Off, the plan is sections, tasks, their dates and marking criteria; the assignment's page keeps to its title, deadline, state and plan; the cards and *Coming up* don't judge how it is going; an AI-made plan brings no milestones.
- Turning it off only hides: nothing is deleted. The migration switches it **on** for a course that already uses any of it (a project assignment, a team member, a milestone, a priority, labels or a person on a task) so nothing a student set goes out of sight.

**Every dialog is a bottom sheet under 640 px** (`.modal` in `components.css`, one rule set; the markup didn't change)
- It rises from the foot of the screen, takes the room its content needs up to nearly the full height, scrolls inside, and keeps its buttons at its foot (sticky, above the phone's safe area). From 640 px it is still the panel from the right.
- The session's rail is a sheet below 1024 px (the topic button) and a column beside the conversation above it; a module's tabs scroll sideways on a narrow screen. Badges and status chips wider than their row now wrap instead of pushing the page sideways.

**Tests added or changed**: `Web/NavigationTest` (six doors, the phone's tabs and More, the settings parts, the redirects with their query), `Web/AssignmentScreensTest` (project tools off: nothing of them shown and nothing lost on save; on and off again; an AI reply brings no milestones when off), `ProjectPlanScreensTest`/`AssignmentPlanScreensTest` (a course with tools on), and every test that wrote `/workspaces/…` now writes `/courses/…`. Browser: `devices-v2.spec.js` (every rebuilt page on 320, 390, 768, 1024 and 1440 px, with 200 % text at 320: nothing scrolls sideways, five tabs on one line each, More, Ask, the course dialog, the topic sheet and the session rail are sheets under 640 px / panels above, buttons in view), `workspaces.spec.js`, `journal.spec.js`, `app-shell.spec.js`, `ai-settings.spec.js`, `assignments.spec.js` (the switch in the dialog; the project tests turn it on), `calendar.spec.js`, `capture.spec.js`, `chat.spec.js` (Ask on the phone is in the tab bar) and `support.js` (`openSection`). Previews: every rebuilt screen again, phone and desktop.

## What Abel tests (plain steps)

Use your OS course.

1. **On the computer**: open a course. The left side has six entries: Home, Modules, Cards, Questions, Assignments, Progress; below them *Courses, Calendar, Settings*. The Journal is not there.
2. **Old links**: change `/courses/` in the address bar to `/workspaces/` and press Enter. The same page opens (the address goes back to `/courses/`).
3. **Settings**: open **Settings**. Four parts: *AI engine, Appearance, Security, Your data*. Your data has *Open the journal*; it opens the journal. The old *AI settings* link in the course's ⋯ menu opens the first part.
4. **On the phone**: the bottom bar is **Home · Modules · Cards · Ask · More**. *Ask* opens the helper. *More* opens a sheet from the bottom with Questions, Assignments, Progress, Notes & files, Calendar, Settings. Open **Modules → a module → Study ▾**: it is a sheet from the bottom, not a side panel.
5. **Every pop-up on the phone** (New module, the course's *Edit course*, a card's editor, a topic): it rises from the bottom, its buttons stay at the bottom while the form scrolls.
6. **Project tools**: open an assignment of yours. If you never used a team or milestones it shows only the plan. In the course's ⋯ → **Edit course**, tick **Project tools for assignments** and save: an assignment's plan now offers *Milestones* and *Team*, and shows how it is going. Untick it: they go out of sight and nothing is lost.
7. **Turn the phone sideways, and make the text bigger** (Settings of the phone): nothing slides sideways on Home, Modules, a module, Cards, Questions, Progress or a session.

## What is left or deferred

- Calendar events for milestones still show on the calendars of a course that turned its project tools off again (only the plan hides them).
- Notes & files and the course's own Calendar have no tab: they are one tap away (*More* on the phone, *All notes & files* on Modules, *Coming up* on Home).
- The old stylesheet for the Progress status buttons and the findings list, and the previews of replaced screens, are removed in Phase 7.

## Message for the local tester (Gemini)

```
Please bring your copy up to date with the latest ViStud work. Do exactly these steps and nothing else.
Important: do not open the AI settings or AI engine pages, and do not read, write or paste any key or token anywhere.

1. git fetch origin claude/persistent-study-context-zsilo6
2. git checkout claude-latest
3. git merge --ff-only origin/claude/persistent-study-context-zsilo6
   (if it says it cannot fast-forward, stop and tell me; do not force anything)
4. composer migrate
   (one new migration adds the project_tools column to workspaces; never use migrate:fresh)
5. npm run build
6. Run only these tests, and tell me the pass/fail counts and the text of any failure:
   php artisan test --compact tests/Feature/Web/NavigationTest.php tests/Feature/Web/AssignmentScreensTest.php tests/Feature/Web/ProjectPlanScreensTest.php tests/Feature/Web/AssignmentPlanScreensTest.php tests/Feature/Web/WorkspaceScreensTest.php

Do not push anything. Do not run the whole test suite or the browser tests (the PC is slow).
```
