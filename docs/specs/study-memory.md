# Study memory: the tracker, the session and the engine

**Status:** decided by the owner on 2026-09-26. Built: step 1a, the tracker's
topics, statuses and questions (the Progress section). Next: 1b (findings,
links, assignments, instructions, Overview), then steps 2–4.

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
| **Findings** | Short "must know" lines pinned to a topic, with their source | Step 1b |
| **Resources** | Files (built) and web links | Step 1b |
| **Assignments and tasks** | With *to do / in progress / done* and a due date; also what the calendar shows | Step 1b, as `activity` records |
| **Instructions** | *About me* (every course), per workspace, per module: how to teach, what to focus on, the student's level | Step 1b |
| **Overview** | "Where am I": topics by status, open questions, what's due, continue where you left off | Step 1b |

## 4. The session (step 2)

Start from a file, a module or a topic. The engine receives the instructions, a
short summary of the box (topic statuses, open questions, recent findings) and
the material a few pages at a time. The student's loop is unchanged: explain,
elaborate, next, "quiz me", answer, feedback. The AI writes the session note as
it goes. At the end (and at checkpoints) the note and findings are saved, the
questions asked are registered, the quiz answers become `attempt` evidence in
the journal, and the AI proposes topic status changes the student confirms.

## 5. Build order

1. **Tracker:** topics, statuses, questions (1a); findings, links, assignments,
   instructions, Overview (1b). Useful before any engine is connected.
2. **Session engine:** box summary → prompt, the built-in chat, pages to the
   engine in chunks, the end-of-session write-back, Office → PDF conversion.
3. **Progress** over time from the journal.
4. ChatGPT and Gemini engines; the connector (M6).
