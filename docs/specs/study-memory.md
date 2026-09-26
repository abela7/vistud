# Study memory: the tracker, the session and the engine

**Status:** decided by the owner on 2026-09-26. Built: step 1, the tracker:
topics, statuses and questions (1a); findings, web links, assignments and
tasks, instructions and the Overview (1b). Step 2 has begun: the study
session, its clock (§4.1) and the Pomodoro clock (§4.2) are built; the start options, the briefing, the
write-back and the MCP server are next (§5).

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
| S1 | **No server-side reading of files.** The AI reads a file itself during a session (PDF and images directly; Word and PowerPoint converted to PDF once, at upload). What is kept is what the student learned, not the file's text | Extracting text from every upload, OCR, a vector index |
| S2 | **The journal is the memory** (ADR 0001, ADR 0002). The tracker's clicks are journal events; the screens show the state the rules derive from them. Nothing keeps a second copy of learning state | A separate "memory" table the AI writes to |
| S3 | **The student's word wins.** The AI proposes topic statuses and registers questions; the student confirms or fixes them | Auto-accepting the AI's judgement |
| S4 | **Four statuses a student sets** — *not started, covered, understood, confused* — plus **mastered**, which is earned from evidence (rules label `secure` or `durable`), never claimed | A free-text status |
| S5 | **Search runs over notes** (the student's and the AI's session notes) with MySQL full-text search. The meaning index of ADR 0001 (Qdrant) waits until the pilot shows keyword search isn't enough | Building the index first |
| S6 | **The engine is a setting.** The built-in assistant calls the model through an API key the owner holds; Claude first, ChatGPT and Gemini behind the same interface after. The connector for a student's own subscription (M6) stays in the plan | One hard-wired provider |

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

## 5. Build order

1. **Tracker:** topics, statuses, questions (1a); findings, links, assignments,
   instructions, Overview (1b). Useful before any engine is connected.
2. **Session engine:** the session, its clock and the Pomodoro clock (built); start options
   (how to teach, check-ins, quiz level) and the tutoring prompt; the briefing
   builder; the write-back (session note, findings, questions, flashcards,
   attempts, proposed statuses); the MCP server, so any AI client works with
   the same box; engine settings and the built-in chat; flashcard review;
   Office → PDF conversion.
3. **Progress** over time from the journal.
4. ChatGPT and Gemini engines; the connector (M6).
