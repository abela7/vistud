# Study memory: the tracker, the session and the engine

**Status:** decided by the owner on 2026-09-26. Built: step 1, the tracker:
topics, statuses and questions (1a); findings, web links, assignments and
tasks, instructions and the Overview (1b). Step 2 has begun: the study
session, its clock (§4.1), the Pomodoro clock (§4.2), and the teaching
options, tutoring prompt and briefing (§4.3) are built; the write-back and
the MCP server are next (§5).

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
| S6 | **The engine is a setting.** The built-in assistant calls the model through an API key the owner holds; Claude first, ChatGPT and Gemini behind the same interface after. The connector for a student's own subscription is the MCP server: any AI client that speaks MCP works with the same box | One hard-wired provider |
| S7 | **Office files aren't converted on the server.** ViStud suggests uploading a Word or PowerPoint file's own PDF export beside it; when there is none, a session sends the slides' and pages' text (read from the file's XML, only when a session needs it) | Converting every upload with LibreOffice |

## 3. The box (per workspace)

| Part | What it holds | Where it lives |
|---|---|---|
| **Topics** | The spine of the course: Normalisation, Joins, Transactions… Each has a module, a status the student set, and the evidence label and flags the rules derive | `topics` table (organisation: name, module, order) + a `defines` claim in the journal (the entity) |
| **Statuses** | *Covered* = an `exposure` about the topic (a lecture reached the student). *Understood* = a `confident` self-report. *Confused* = a `confused` self-report. *Mastered* = rules label secure or durable | The journal; the latest click is cached on the row |
| **Questions** | What the student asked or didn't get: text, topic, and a state (*open* or *understood*), plus an *ask the teacher* flag | `questions` table + a question entity, a `question` event and a `refers_to` claim; *understood* is an explanation (exposure) that answers it and a `clicked` self-report |
| **Findings** | Short "must know" lines pinned to a topic, with their source (a note or file, and where in it) and who wrote them (the student, or a study session) | `findings` table; plain rows, since they are material, not learning state. Under each topic in Progress |
| **Resources** | Files and web links, in the same places as notes | `files` and `links` tables. In Modules and Notes & files |
| **Assignments and tasks** | Assignment, quiz, exam, lab, problem set or to-do, with *to do / in progress / done* and a due date; also what the calendar will show | `activities` table + an `activity` record revision in the journal for every change. On the Overview |
| **Instructions** | *About you* (every course), this course, and each module: how to teach, what to focus on, the student's level | `instructions` table. On the Overview; a module's in its menu in Modules |
| **Overview** | "Where am I": topics by status, what's still confusing, open questions, assignments and tasks soonest first, notes to pick up, instructions | The workspace's Overview page |

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
student has **one open session at a time**; starting another asks to end it
first. Time is kept in **segments**, each a stretch of study or of a break,
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
every page, and other open tabs follow a change straight away. A week starts
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
(the session page's *How the AI teaches* card).

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
`<attempt topic result="correct|partial|incorrect">`, `<checkpoint>` (where the
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

**Material.** On the session page, *Use* puts a note or file of the course
into the session's material: a note's text goes into the briefing, a file
goes in by name for the student to share (the MCP server and the built-in
chat will send its pages). With nothing chosen, the briefing lists what the
session's module holds.

**Seeing it.** *Briefing* on the session page shows exactly what the AI
receives, with *Copy* and *Download* (a `.md` file to attach). Until the
engine and the MCP server exist, pasting it into any AI starts the tutor warm.

## 5. Build order

1. **Tracker:** topics, statuses, questions (1a); findings, links, assignments,
   instructions, Overview (1b). Useful before any engine is connected.
2. **Session engine:** the session, its clock and the Pomodoro clock (built); start options
   (how to teach, check-ins, quiz level) and the tutoring prompt; the briefing
   builder; the write-back (session note, findings, questions, flashcards,
   attempts, proposed statuses); the MCP server, so any AI client works with
   the same box; engine settings and the built-in chat; flashcard review;
   Office files (S7): the PDF-export suggestion and slide text.
3. **Progress** over time from the journal.
4. ChatGPT and Gemini engines; the connector (M6).
