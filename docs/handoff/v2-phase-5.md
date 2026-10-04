# ViStud 2 · Phase 5 (The helper everywhere): handoff

Branch `claude/persistent-study-context-zsilo6`. The plan is [docs/specs/vistud-2-blueprint.md](../specs/vistud-2-blueprint.md) (Part 4, Phase 5; §3.6.1, §3.6.5). Phase 4 is in [v2-phase-4.md](v2-phase-4.md).

## What was built

**A ✦ menu on the thing itself** (`<x-ai-menu>`, `App\Livewire\Workspaces\AiAssist`, `App\Engine\Assist`)
- One tap on a ✦ next to the ⋯ on:
  - a **card** (Deck): *Improve · Shorter · Fix the wording · Two more like this*;
  - a **question** (the board, the question's page): *Clarify · Split into two · Answer from my notes · Ask the tutor*;
  - a **file** (module Files tab, the file's page): *Summarise · Make a note from it · Make cards from it · Find topics*;
  - a **note** (list, the note's page): *Make cards from it*; a **topic** (its sheet): *Make cards from it*;
  - a **folder**: *Where should these go?*;
  - a **selection in a note** (the toolbar's ✦ button): *Explain · Shorten · Fix the text*.
- The answer comes back on a sheet as a **proposal**: before and after, the cards ticked, or what was made, with **Keep** and **Discard**. Nothing is applied silently and nothing is kept of what isn't kept. A failure says what to do (and the AI settings link) with **Try again**.
- Card jobs, question clarify/split, selections and the folder question are the **helper** (the cheapest model, `Helper::quick`); reading a file, a note from a file, cards from a file/note/topic and answering from notes are the **reader**. Both are recorded in `engine_jobs` under their role, so *Usage this month* counts them.
- **Ask the tutor** on a question starts a **free** session in the question's module (or says another session is open) with the question waiting in the message box.
- *Answer from my notes* keeps the answer on the question as **Answered**, saying *(From your notes: Lecture 3 notes.)*; when the notes don't say it says so, and doesn't make one up.
- *Where should these go?* is advice only: it lists "name → module" and moves nothing.

**Reader jobs** (`App\Engine\Jobs\NoteFromFile`, `CardsFrom`, `AnswerFromNotes`; prompts `reader-note.md`, `reader-cards.md`, `reader-answer.md`, each under 600 tokens)
- `NoteFromFile`: the file's text in, Markdown out, kept as a note in the file's folder or module titled *Notes · name*, marked in its history as written by the AI.
- `CardsFrom` (file, note or topic): up to 5/8/10/15 cards, front ≤ 500 and back ≤ 1,000 characters, no front twice, each on a topic of the module when it clearly belongs to one. **Nothing is added until the student keeps them**: the sheet shows them ticked, editable, and adds only the ticked ones (as the AI's, due now).
- `AnswerFromNotes`: the question and the module's notes and file summaries in; an answer out, only from them.
- These return their result to a waiting screen (`Jobs\Answering`, `Runner::answer`); `Runner::now` runs a plain job at once (*Summarise* reads a file that hasn't been read).

**Ask** (`App\Livewire\Workspaces\Ask`; `Helper::quick(..., workspaceId:)`)
- An **Ask** button in the top bar of every course page opens a sheet: a short talk with the helper about the course, which can look at topics, questions, key points, notes and files, and changes nothing. Not stored: leaving the page ends it (the last 12 lines are kept while you stay). One line at the bottom: **Need teaching? Start a session** (goes back to the open session, or starts one). The phone tab bar gets Ask in Phase 6.

**The deck's card maker** (`Deck`, `deck.blade.php`)
- **Make cards from…** (a topic, a note or a file, and how many) replaces *Make cards with an AI*. For those who turned on *I use another AI by copy-paste*, **Make cards with another AI** (the old prompt-and-paste dialog, with the flashcards prompt) stays beside it; others don't see it.

**Tests added or changed**: `Engine/CardsFromJobTest`, `NoteFromFileJobTest`, `AnswerFromNotesJobTest`, `AssistTest` (each action, parser, refusals, only the student's own), `HelperTest` (course-level), `Web/AiMenuScreenTest` (each ✦ path with a fake reply; Keep and Discard; locked ids; the pages that offer it), `Web/AskScreenTest`, `Web/FlashcardScreensTest`. Browser: the `helper:` tests in `chat.spec.js` (a card's ✦ before/after/Keep, Discard and two more, Make cards from…, a question clarified, a file summarised and made into cards and a note, a note's selection shortened, Ask on a phone, token colours on desktop and phone, axe in three themes), `flashcards.spec.js` (copy-paste way behind its setting, the new sheet). They are in `chat.spec.js` because that file owns the fake service: only one spec at a time may point the app at it. Previews: `flashcards-*` (the ✦ menu, Make cards from…), `ask-*`.

## What Abel tests (plain steps)

Use your OS course. Never paste a key or a token into a chat with any AI. The ✦ jobs need your key and consent in **AI settings**, and models for the *reader* and the *helper* (cheap ones are fine).

1. **Flashcards** → on a card tap **✦** → **Improve**. After a few seconds the sheet shows the card *Before* and *After*. **Keep** changes it; **Discard** leaves it. Try **Two more like this**.
2. **Flashcards → Make cards from…** → choose a topic, a note or a file and how many → untick one you don't want → **Add**. They appear as *Made with an AI*.
3. **Modules** → a module → **Files** → on a lecture file tap **✦** → **Summarise**; then **Make a note from it** (the note appears next to the file, and says the AI wrote it); then **Make cards from it**; then **Find topics** (they wait on the module's Topics tab).
4. Open a **question** → **✦** → **Clarify**, **Split into two**, **Answer from my notes** (only works when your notes say it), and **Ask the tutor** (opens a session with the question in the box).
5. Open a **note**, select a few words, tap the **✦** in its toolbar → **Shorten** → **Replace the text**. Try **Explain**: it adds the explanation below.
6. Tap **Ask** at the top (a phone shows only its icon). Ask *"What did I find hard in Module 3?"*; you get an answer in a few seconds. The line at the bottom starts a session.
7. If you turned on *I use another AI by copy-paste*, the deck also has **Make cards with another AI**; if not, it does not.
8. On the phone: the sheets fit the screen and nothing slides sideways.

## What is left or deferred

- *Where should these go?* only advises: moving the files stays your job.
- The cards from a file or note are not run through the session write-back (there is no session): they are reviewed on the sheet and added directly, as the AI's.
- A ✦ on notes in lists offers only *Make cards from it*; *Explain/Shorten/Fix* work on a selection inside a note.
- Ask is on course pages; there is no Ask on the all-courses pages. The phone tab bar with Ask comes with the navigation work (Phase 6).
- Voice input is a separate decision, and the helper never writes anything on its own.

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
   php artisan test --compact tests/Feature/Engine/CardsFromJobTest.php tests/Feature/Engine/NoteFromFileJobTest.php tests/Feature/Engine/AnswerFromNotesJobTest.php tests/Feature/Engine/AssistTest.php tests/Feature/Engine/HelperTest.php tests/Feature/Web/AiMenuScreenTest.php tests/Feature/Web/AskScreenTest.php tests/Feature/Web/FlashcardScreensTest.php

Do not push anything. Do not run the whole test suite or the browser tests (the PC is slow).
```
