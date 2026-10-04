# ViStud 2 · Phase 2 (the module page as the working surface): handoff

Branch `claude/persistent-study-context-zsilo6`. The plan is [docs/specs/vistud-2-blueprint.md](../specs/vistud-2-blueprint.md) (Part 4, Phase 2; §3.5.3, §3.6.6, §3.7). Phase 1 is in [v2-phase-1.md](v2-phase-1.md).

## What was built

**The reader reads files** (migration `2026_10_07_110000_create_file_digests_topic_suggestions_and_module_briefs`; `App\Engine\Jobs\ReadFile`, `resources/prompts/reader-file.md`)
- A file in a module is read by the reader: the first 30,000 characters of its text (page by page; the rest becomes an outline) go in, and a summary (600 characters at most), an outline (40 headings), up to 8 topics and the language come out, cleaned to what the app knows. Kept in `file_digests` for the file's content (its sha256): the same bytes are read once (a copy in another file costs nothing), a changed file is read again. A picture is never sent. A file with no words is noted as skipped and not tried again. An unreadable answer, no key, no model or no consent is recorded on the run with a code, never silently.
- `App\Study\FileReading`: after an upload the file is read at once when **Read my files automatically** is on and the AI is set up; otherwise it waits for **Read now**. `FileDigests::states` says whether a file is *read*, *reading* (a run is queued or going and is not older than 15 minutes), *unread* or *skipped*.

**Topics found, waiting for the student** (`App\Study\TopicSuggestions`)
- What the reader found waits as suggestions on the module. **Add all** makes them topics of the module (in order); **Pick** lets the student choose; **Not these** dismisses them for good. A name the module has already is never suggested, a dismissed one is not suggested again.

**The tutor knows the module** (`App\Study\ModuleBriefs`, `Context\Stack` layer 4; tool `module_files`)
- Layer 4 now also says *Last time:* (the last session's checkpoint), *Files:* (each with its one line), *Open questions:* (5 at most, stuck ones first) and *Key points:* (8 at most). It is built once and kept in `module_briefs`, rebuilt whenever what it is built from changes. The tutor's and the helper's `module_files` look-up gives a module's files with summary, length, topics and outline, so they know what a file holds before opening it with `read_file`.

**The module page** (`App\Livewire\Workspaces\Contents` in module view, `place-page.blade.php`, `<x-module.tabs>`, `TopicSheet`)
- Title row with **Study this ▾** (Whole module · Pick a topic · Quiz me · Test me), one context line (dates · *1 of 5 understood*) and five tabs: **Topics · Files · Notes · Questions · Sessions** (`?tab=`; Questions and Sessions are pages of their own behind the same strip).
- **Topics**: the *"N new topics found in …: A, B, C"* line with Add all / Pick / Not these, then the topics, each with where it stands, its cards, and **Study**; the name opens the **topic sheet** (say where you stand, cards, open questions, key points, sessions, rename, move, Study). An add-a-topic box.
- **Files**: each file says whether the AI has read it: **Read** (tap to see the summary, the topics and the outline), **Reading…**, or **Read now** (no chip on a picture). Upload, folders and links as before. **Notes**: the module's notes only.
- **Study this**: no dialog: it starts at once in this module with the clock and teaching the student used last. **Quiz me** and **Test me** start the session with the ask waiting in the chat box (`?ask=`); the real modes arrive in Phase 3.
- The file's page has **Read by the AI** above the file: summary, topics, outline, or *Reading…*, or **Read now**.
- **All notes & files** (on Modules): one list with a search box across the course. Notes & files is no longer a tab or a sidebar entry of its own; the module pages hold them.

**Tests added or changed**: `Engine/ReadFileJobTest`, `Study/TopicSuggestionsTest`, `Web/ModulePageScreenTest` (11 tests, including the file page), `Engine/ToolboxTest`, `Engine/HelperTest`, `Engine/StackTest` (layer 4); old module, notes, questions, sessions, tracker and workspace screen tests follow the new page. Browser: `module-page.spec.js` (desktop and phone, axe in three themes, token colours); `modules.spec.js`, `tracker.spec.js`, `sessions.spec.js`, `questions.spec.js`, `files.spec.js`, `workspaces.spec.js`, `navigation.spec.js` follow the new page.

## What Abel tests (plain steps)

Use your OS course. Never paste a key or a token into a chat with any AI. Reading needs your key, a model and consent in **AI settings** (and *Read my files automatically* if you want files read when you drop them).

1. Open a module of your OS course → **Files** tab → drop one lecture PDF. With automatic reading on, the file shows **Reading…** for a few seconds, then **Read**. With it off, it shows **Read now**: press it.
2. Tap **Read** on the file: a short summary, the topics it covers and an outline appear. Open the file itself: **Read by the AI** is above it.
3. **Topics** tab: a line says *"N new topics found in …"*. Press **Pick**, add one, press the × on another, then **Add all**. The topics appear below with **Study** buttons. Nothing is added until you press one.
4. Press a topic's name: a sheet opens where you say whether it is *Covered*, *Understood* or *Still confusing*. Change it and close it: the context line (*… of … understood*) follows.
5. **Study this ▾**: try *Whole module*, then come back and try *Pick a topic* and *Quiz me* (the quiz ask is waiting in the chat box; you press send).
6. In the session ask the tutor *"What files does this module have?"*: it should know what each file holds.
7. Modules page → **All notes & files** → type part of a file or note name: the list narrows across the whole course.
8. On the phone: the same page and the same tabs; nothing slides sideways.

## What is left or deferred

- **Quiz me** and **Test me** only put the ask in the chat box until Phase 3 builds the modes.
- *Questions* and *Sessions* are their own pages behind the shared tab strip, not panes inside one page.
- The reader reads a file's text only (a scanned PDF is skipped as "no words"); the first 30,000 characters are read and the rest is outlined.
- The Next line's *needs attention* row has no feeder yet (Phases 3 and 4); the progress ring is still understood over all topics (Phase 4).
- `topic_suggestions.name_key` and the `module_briefs` table's fingerprint are small additions beyond the blueprint's table (documented in schema.md).

## Message for the local tester (Gemini)

```
Please bring your copy up to date with the latest ViStud work. Do exactly these steps and nothing else.
Important: do not open the AI settings or AI engine pages, and do not read, write or paste any key or token anywhere.

1. git fetch origin claude/persistent-study-context-zsilo6
2. git checkout claude-latest
3. git merge --ff-only origin/claude/persistent-study-context-zsilo6
   (if it says it cannot fast-forward, stop and tell me; do not force anything)
4. composer migrate
   (one new migration: file_digests, topic_suggestions and module_briefs; never use migrate:fresh)
5. npm run build
6. Run only these tests, and tell me the pass/fail counts and the text of any failure:
   php artisan test --compact tests/Feature/Engine/ReadFileJobTest.php tests/Feature/Study/TopicSuggestionsTest.php tests/Feature/Engine/StackTest.php tests/Feature/Engine/ToolboxTest.php tests/Feature/Engine/HelperTest.php tests/Feature/Web/ModulePageScreenTest.php tests/Feature/Web/ModulesScreenTest.php tests/Feature/Web/WorkspaceScreensTest.php tests/Feature/Web/TrackerScreensTest.php

Do not push anything. Do not run the whole test suite or the browser tests (the PC is slow).
```
