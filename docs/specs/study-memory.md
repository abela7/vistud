# Study memory: the tracker, the session and the engine

**Status:** decided by the owner on 2026-09-26. Built: step 1, the tracker:
topics, statuses and questions (1a); findings, web links, assignments and
tasks, instructions and the Overview (1b). Step 2 has begun: the study
session, its clock (§4.1), the Pomodoro clock (§4.2), and the teaching
options, tutoring prompt and briefing (§4.3), and the write-back (§4.4) are
built. The owner put the MCP server off on 2026-09-27: for now any AI works
with ViStud through the briefing and Save from the chat (§5).

**The idea in one line.** ViStud is the body; the language model is the engine.
The body keeps a living copy of what the student understands about each
course, built up session by session, so that any engine can be plugged in and
every conversation starts warm.

## 1. The problem this solves

An AI tutor has no memory of the student. Every chat starts cold (upload the
slides again, explain again how to teach), one long chat degrades, and the AI
knows the material but not the student: not what they understood in the last
lecture, where they got stuck, what they asked. Learning is scattered across
chats, notes and the student's head, so they can't tell which topic they're on,
whether they've covered it, whether they get it, or whether an assignment is
still open. At exam time nothing has accumulated.

## 2. Decisions

| # | Decision | Instead of |
|---|---|---|
| S1 | **No server-side reading of files.** The AI reads a file itself during a session (PDF and images directly; Word and PowerPoint through their own PDF export or their text, S7). What is kept is what the student learned, not the file's text | Extracting text from every upload, OCR, a vector index |
| S2 | **The journal is the memory** (ADR 0001, ADR 0002). The tracker's clicks are journal events; the screens show the state the rules derive from them. Nothing keeps a second copy of learning state | A separate "memory" table the AI writes to |
| S3 | **The student's word wins.** The AI proposes topic statuses and registers questions; the student confirms or fixes them | Auto-accepting the AI's judgement |
| S4 | **Four statuses a student sets** — *not started, covered, understood, confused* — plus **mastered**, which is earned from evidence (rules label `secure` or `durable`), never claimed | A free-text status |
| S5 | **Search runs over notes** (the student's and the AI's session notes) with MySQL full-text search. The meaning index of ADR 0001 (Qdrant) waits until the pilot shows keyword search isn't enough | Building the index first |
| S6 | **The engine is a setting.** The built-in assistant calls the model through an API key the owner holds; Claude first, ChatGPT and Gemini behind the same interface after. A student's own subscription works today by pasting: the briefing in, Save from the chat out. An MCP server (the AI app calling ViStud directly) is planned but put off by the owner as extra for now | One hard-wired provider |
| S7 | **Office files aren't converted on the server.** ViStud suggests uploading a Word or PowerPoint file's own PDF export beside it; when there is none, a session sends the slides' and pages' text (read from the file's XML, only when a session needs it) | Converting every upload with LibreOffice |

## 3. The box (per workspace)

| Part | What it holds | Where it lives |
|---|---|---|
| **Topics** | The spine of the course: Normalisation, Joins, Transactions… Each has a module, a status the student set, and the evidence label and flags the rules derive | `topics` table (organisation: name, module, order) + a `defines` claim in the journal (the entity) |
| **Statuses** | *Covered* = an `exposure` about the topic (a lecture reached the student). *Understood* = a `confident` self-report. *Confused* = a `confused` self-report. *Mastered* = rules label secure or durable | The journal; the latest click is cached on the row |
| **Questions** | What the student doesn't get while reading or studying: text, module, topic, **pending**, **stuck** or **answered** (the student's word), the answer once there is one, the study session it was asked in, and an *ask the teacher* flag. Each module has a **Questions page** (the module's page links to it, with how many are open; *New → Question* opens the page for a new one): the questions as cards across the whole width, a line to write one, a search (the words and the answer), the filter by status and a sort (stuck first, newest, oldest). In a study session they're asked with *Ask a question* and folded under their heading until opened; Progress holds all of them. A question opens on a page of its own (the owner's review, 2026-09-29) with its words, its answer and the options beside them (status, module, ask the teacher, delete); a new one has the same page | `questions` table + a question entity, a `question` event and a `refers_to` claim; *answered* is an explanation (exposure, the answer as its text) that answers it and a `clicked` self-report; *stuck*, or pending again after an answer, a `confused` self-report. The briefing lists the open ones, stuck first |
| **Findings** | Short "must know" lines pinned to a topic, with their source (a note or file, and where in it) and who wrote them (the student, or a study session) | `findings` table; plain rows, since they are material, not learning state. Under each topic in Progress |
| **Resources** | Files and web links, in the same places as notes | `files` and `links` tables. In Modules and Notes & files |
| **Assignments and tasks** | Assignment, quiz, exam, lab, problem set or to-do, with *to do / in progress / done* and a deadline: a day and, optionally, a time on it in the student's own time zone (2026-10-02); also what the calendar will show. An assignment keeps its files in a folder of its own, made with it in its module (a to-do gets one when a file is first added), named after it, moving with it, and kept when it is deleted | `activities` table (`due_time`, `folder_id`) + an `activity` record revision in the journal for every change (`due_time` in it). The Assignments section (cards, soonest first, how long is left and how far its plan has got), each assignment's own page (details, its files, where the student is with it, its plan), and Coming up on the Overview. **The plan** (2026-10-02): one way to track any assignment, whatever the course: *parts* (its sections or deliverables), *steps* (small things to do, under a part or on their own) and *marking criteria* (what it is marked on, checked as not yet / partly / met). A student starts from a starter (essay or report, problem set, presentation, coding project, lab report, exam revision), from an AI's reply to a prompt that carries the brief (`resources/prompts/assignment-plan.md`, read by `Study\PlanMaker`), or by hand; every list can be added to, renamed, given marks, reordered and deleted. Progress is the things ticked (by marks when every part has some), and the pace is what is left against the time left. Ticking the first thing starts a to-do assignment; ticking the last offers to mark it done. `activity_items` table, `Study\Plans` |
| **Instructions** | *About you* (every course), this course, and each module: how to teach, what to focus on, the student's level | `instructions` table. One dialog from the Overview's ⋯ menu; a module's in its own menu |
| **Flashcards** | A front and a back, on a topic, written by the student, made with an AI, or saved from a session; when each is next due (§4.5) | `flashcards` table + a `task` record and an `exercises` claim in the journal; every answer an `attempt`. The Flashcards section |
| **Overview** | Short (the owner's review, 2026-09-27): the streak, the last 7 days, cards to review, what to continue (the last session and where it stopped, recent notes) and what's coming up. Topics and questions are in Progress; the instructions are a dialog in its ⋯ menu | The workspace's Overview page |

## 4. The session (step 2)

Start from a file, a module or a topic. The engine receives the instructions, a
short summary of the box (topic statuses, open questions, recent findings) and
the material a few pages at a time. The student's loop is unchanged: explain,
elaborate, next, "quiz me", answer, feedback. The AI writes the session note as
it goes. At the end (and at checkpoints) the note and findings are saved, the
questions asked are registered, the quiz answers become `attempt` evidence in
the journal, and the AI proposes topic status changes the student confirms.

### 4.1 The session and its clock (built)

A session belongs to a workspace, and optionally to a topic and a module. A
student has **one open session at a time**, whatever its state (studying,
paused or on a break), in any workspace. Starting another (Start studying,
a module's *Study this*, a topic's *Study this now*) starts nothing: a panel
says which session is open, with *Go to the session* and *End it* (then the
new one can start). The database keeps it too, so two tabs starting at once
can't both succeed. A session in a module sits under Modules: its path is
Modules › the module › Study session. The top bar's timer can be hidden
(*Hide the timer*): it becomes a small dot, pulsing while the clock runs,
that shows it again; the choice stays on that device. Time is kept in **segments**, each a stretch of study or of a break,
so pauses are exact and nothing is guessed from one start and one end.

| State | The clock counts | From here |
|---|---|---|
| **Studying** | study time | pause, take a break, end |
| **Paused** | nothing | resume, take a break, end |
| **On a break** | break time, apart from study | back to studying, pause, end |
| **Ended** | — | delete (a session started by mistake) |

The clock is honest by itself, and the student's word wins (S3):

- **Away.** Studying with no activity for **30 minutes** pauses the session
  *at the last activity*. Activity is the page being in use: visible, and
  focused or touched in the last minute (a heartbeat every minute, on every
  ViStud page). The session page then asks: *I was studying: count it* puts
  the time back and runs on; *Resume from now* doesn't.
- **Long break.** A break longer than **an hour** ends there, and the session
  pauses.
- **Forgotten.** A session with no activity for **12 hours** ends at its last
  activity.
- **Logged time.** Time studied without the clock (a library afternoon) is
  logged afterwards: date, start time and minutes, in the student's time
  zone, up to 12 hours.

The rules run whenever a session is read or changed, and every 10 minutes
from the scheduler (`vistud:sessions:settle`). Durations show as "1 h 25 min";
the running clock ticks as 0:25:13 on the session page and in the top bar on
every page, and other open tabs follow a change straight away.

**The session page** is an open space to study in (the owner's review,
2026-09-27): a slim clock bar (the state, the time, Pause, Take a break, End
session; the Pomodoro phase in a small ring), then what to do next as tiles:
*Study with an AI* (the briefing), *Save from the chat*, *Ask a question*,
*New flashcard*, *Write a note* (a new note in the session's module, opened
in the editor) and *Notes & files* (a side panel to open them or *Use* them
in the briefing). The topic's status shows beside its name and is asked when
the session ends. The questions, the tutor's summary and, once it has ended,
what happened show only when there are any. How the AI teaches and the
Pomodoro settings are in its ⋯ menu. A week starts
on Monday in the student's time zone. The Overview shows study time this week
and in all, and the latest sessions.

In the journal, a session is a `session` record (a revision when it opens,
ends or is deleted, with its study and break seconds), and every event
written in the workspace while it is open carries its id. The rules count
distinct sessions for *secure* and *practised* (ADR 0002 §7), so real
sessions are what makes *mastered* reachable.

### 4.2 The Pomodoro clock (built)

A session runs on the **free clock** or the **Pomodoro clock**, chosen when it
starts (the last choice is offered again) and switchable during it. Pomodoro:
a focus period, then a short break; after every few focus periods, a long
break.

| Rhythm | Focus | Short break | Long break | After |
|---|---|---|---|---|
| Classic | 25 min | 5 min | 15 min | 4 |
| Deep work | 50 min | 10 min | 30 min | 2 |
| Short bursts | 15 min | 3 min | 10 min | 4 |
| Custom | 5–120 | 1–30 | 5–60 | 2–8 |

- **The phases are counted from the segments**, like all session time: focus
  counts study time since the phase began, so a pause stops the countdown; a
  break counts break time. A focus period that runs out becomes a pomodoro
  and its break starts at that exact moment, even when nobody is looking;
  the next focus period starts by itself after the break, or waits for the
  student (*Start the next focus by itself* is a setting).
- **Honest with the idle rule:** once the student is away, a focus period only
  finishes if it ran out before their last activity. *Count it* on an away
  pause puts the time back, and the pomodoro with it.
- **Skip** ends a focus period early (it isn't counted as a pomodoro) and
  starts a short break, or ends a break and starts focus.
- **On screen:** the countdown in a ring that fills, the rounds before the long
  break as dots, the pomodoros done, and the study time; the countdown also
  shows in the top bar and the tab's title. When a phase ends, a chime (in
  the browser, no sound file; *Sound* turns it off on that device) and, if the
  student allows it, a notification, once across all open tabs.
- **Counted:** pomodoros per session, this week and in all on the Overview, and
  in the session's journal record when it ends.

### 4.3 Teaching options, the tutoring prompt and the briefing (built)

**Teaching options.** Starting a session asks how the AI should teach; the
last choices come back next time, and they can change during the session
(*How the AI teaches* in the session page's ⋯ menu, or from the briefing).

| Choice | Options (default first) |
|---|---|
| How to teach | Explain, then check · Socratic · Summary first · Step by step |
| Check questions | After every section · At the end · None |
| Quiz level | Normal · Easy · Exam level |
| Pace | One slide at a time · A section at a time |

**The tutoring prompt** is a plain text file, `resources/prompts/tutor.md`,
anyone can read and edit. It is built from Mollick and Mollick's tutor prompt
(2023), OpenAI's Study mode rules (2025) and the Learning Scientists' six
strategies, and it gives the AI a clear path so it never has to guess:

- **A role:** the student's tutor for one session. The student leads and
  decides; ViStud remembers and saves; the teacher and the course material
  are the authority. The AI is not an answer machine for graded work, not a
  lecturer, and not the judge of what the student knows.
- **Its words defined:** material, part (a slide or a section, as the pace
  says), section, idea, check question (one the student must answer before
  going on; Socratic teaching questions aren't), quiz. The teaching options
  are written in these words, so they can't contradict each other: the
  method says how to present, the check questions setting says how often to
  test, the quiz level says how hard, the pace says how big a part is.
- **An order for clashes:** honesty first; never writing the graded work the
  student hands in (helping them prepare is the job); then what the student
  asks; their instructions; the session's teaching; the rest.
- **How to read the briefing,** section by section.
- **A path:** open (a plan in a few points, the main words, and exactly one
  question, chosen in a fixed order: no topic, nothing known about the
  student, no material, something hard last time, else "begin?"); teach one
  part at a time, each message starting with where it is (**Slide 5 of 18 ·
  Left joins**, or without the total when unknown), a check question only
  when one is due, a hint and a second try before the answer, one attempt
  mark per question; checkpoint at every section's end and about every
  twenty messages (and resume from the last one); close only when the
  student says so.
- **Words the student can use:** next, again, example, why, quiz me, save
  that, flashcard, skip, break, where are we, wrap up.
- **Marks as plain lines,** never in code blocks or backticks (the write-back
  will strip them anyway).
- **The unclear moments:** no material yet, an empty briefing, "I don't
  know", stuck or frustrated, off the topic, the material and the AI
  disagree, an unclear topic, an unclear request.
- **Always:** one idea and one question at a time, start from what the
  student knows, guide rather than answer (two tries on a quiz question),
  ask them to explain back, bring back earlier topics, be brief.

The teaching options fill its four blanks (`App\Study\Tutoring`).

**Marks** are the prompt's contract with the write-back: the AI adds them on
their own line, and ViStud offers to save them. `<finding topic>`,
`<question topic>`, `<flashcard topic><front/><back/></flashcard>`,
`<attempt topic form support result><asked/><answer/></attempt>`, `<checkpoint>` (where the
session stands), and at the end
`<summary>` and one `<status topic proposed="covered|understood|confused">`
per topic covered.

**The briefing** (`App\Study\Briefings`) is what any AI receives: the prompt,
then, in Markdown: about the student; the course and its instructions; the
module and its instructions; this session (topic with the student's status
and the evidence, clock, teaching, time so far); what the student recorded
about the topic; what's still confusing; open questions (the topic's first);
what's due in the next two weeks; earlier sessions; the material; all topics
with statuses; other findings; and the chosen notes' text. It stays within
60,000 characters (about 15,000 tokens): the prompt, instructions and
session are always whole, the rest fills in by relevance, and what doesn't
fit is counted ("… and 12 more"). A note gives at most 8,000 characters.
The student's name and email never go into it.

**Material.** In the session page's *Notes & files* panel (only the session's module's notes and files, by folder with each folder's path, with a search; never another module's; with no module, each module's and the top level's apart; anything given to the AI from elsewhere before is listed apart to take out), *Use* puts a note or file of the course
into the session's material: a note's text goes into the briefing, a file
goes in by name for the student to share (the MCP server and the built-in
chat will send its pages). With nothing chosen, the briefing lists what the
session's module holds.

**Seeing it.** *Study with an AI* on the session page shows exactly what the AI
receives, with *Copy* and *Download* (a `.md` file to attach). Until the
engine and the MCP server exist, pasting it into any AI starts the tutor warm.

### 4.4 The write-back (built)

**Save from the chat** on a session's page: the student pastes the tutor's
replies (or the whole chat), ViStud reads the marks (`App\Study\Capture`:
it copes with backticks, code blocks, escaped `&lt;`, curly quotes and
repeats, and removes only the marks' own tags, so "age<18" stays), and
shows them to review, grouped: the summary, key points, questions,
flashcards, answers, where the session stands, and statuses. Each has a
tick, the topic it goes to (matched by name; an unknown name becomes a new
topic in the session's module when saved), and editable words where that
makes sense. Statuses are never ticked for the student (S3). Only the last
summary and checkpoint are kept. `App\Study\WriteBack` saves:

| Mark | Goes to |
|---|---|
| finding | a finding on the topic, written by *a study session* |
| question | a registered question on the topic |
| flashcard | a flashcard on the topic, made by *a study session* (§4.5) |
| attempt | evidence in the journal: the question is a **task** (its id comes from its words, so the same question in a later session is the same task, as §4 of ADR 0002 asks), a `relates` claim that the task **exercises** the topic (once), and an **attempt** with the AI's result (`judged_by: ai`, `setting: chat`, the form and support the tutor gave), carrying the session's id and dated at the session's end. The rules then count it: a correct *apply* answer moves a topic past "not practised yet" |
| status | the student's word on the topic, only if ticked |
| summary, checkpoint | kept on the session; the next briefing carries the last sessions' summaries, and a resumed session's briefing says where it last stood |

A **session note** (on by default) gathers what was kept, readable in the
module (or the course's top level), and later pastes add to it. Each saved
mark's fingerprint is kept on the session, so pasting the same chat again
shows *Saved before*. What can't be saved (an emptied text, a topic gone)
is listed; the rest is saved.

### 4.5 Flashcards (built)

A workspace's **Flashcards** section (*Cards* in the phone tab bar) shows
what's due today with **Review now**, and every card by topic, each with
when it's next due and who made it; a filter narrows it to a topic. Cards
come three ways:

- **By hand**: *New card* (also a topic's menu in Progress and the
  session's menu) opens one dialog for the front, the back and the topic,
  with *Save and add another* for writing several in a row.
- **With any AI**: *Make cards with an AI* builds a prompt
  (`resources/prompts/flashcards.md`, filled by `App\Study\CardMaker`)
  from the topic (or all topics, for the AI to choose among), how many
  (5 to 20), the topic's key points and, if chosen, one note's text (up to
  12,000 characters), and lists the cards the student already has so the
  AI doesn't repeat them. It says what makes a good card (one idea, one
  answer, short backs, why and how as well as what; after Wozniak's twenty
  rules and Matuschak's prompt-writing notes). The student copies it into
  any AI, pastes the reply back, and reviews the `<flashcard>` marks
  (read by `App\Study\Capture`, as after a session): cards they already
  have are recognised and left unticked, and each keeps an editable front,
  back and topic.
- **From a study session**: the tutor's `<flashcard>` marks, saved with
  the write-back (§4.4).

**Reviewing** (`/workspaces/{w}/flashcards/review`, optionally one topic)
takes up to 20 cards a round: those due, the longest waiting first, then
new ones. A card shows its front; *Show the answer* (or Space, or a tap on
the card) turns it over, showing the question again above the answer. The
student says how it went: **Not yet**, **Partly** or **Got it** (or 1, 2,
3), each saying when the card comes back. The first answer to a card in a
round moves it on a **ladder** of gaps: *Got it* waits longer each time in
a row (1, 3, 7, 14, 30, 60, 120 days); *Partly* waits the last gap again;
*Not yet* starts it over from tomorrow and brings it back once at the end
of the round, for another go that's recorded but moves nothing. Days are
the student's own (their time zone). With nothing due, *Practise anyway*
goes through the next cards without changing when they come back. The
round ends with how many were *got*, *partly* and *not yet*, and what's
due next. The Overview shows how many cards are due today.

**Evidence** (ADR 0002): each card is a **task** (`card-{id}`, key
`card/{id}`, the front as its prompt and the back as its answer), with a
`relates` claim that it **exercises** its topic; new words make a new
revision of the task (earlier answers keep the revision they answered), a
new topic retires the old claim and writes a new one, and deleting a card
retires its task. Every answer is an **attempt**: *recall*, *unaided*,
*practice*, judged by the student (`judged_by: self`), with the task's
revision, in the study session open in the workspace or, when none is,
the day's review (so reviews on different days count as different
sessions). The rules count a self-judged success as practice but never as
proof, as ADR 0002 asks; a card missed after a topic was working marks it
as slipped.

## 5. Build order

1. **Tracker:** topics, statuses, questions (1a); findings, links, assignments,
   instructions, Overview (1b). Useful before any engine is connected.
2. **Session engine:** the session, its clock and the Pomodoro clock, the
   teaching options, prompt and briefing, the write-back, and flashcards
   (built). Still to come: engine settings and the built-in chat; Office
   files (S7): the PDF-export suggestion and slide text.
3. **Progress** over time from the journal.
4. ChatGPT and Gemini engines. The MCP server, when the owner wants it: the
   same briefing and write-back as tools an AI app calls, starting local
   (Claude Desktop), online once ViStud is hosted.
