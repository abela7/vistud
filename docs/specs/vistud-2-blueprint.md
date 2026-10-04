# ViStud 2: the course-centred platform

**Analysis, architecture and the phased plan.**

- **Status:** proposed, 2026-10-06. Nothing in it is built yet.
- **Owner:** Abel (the product). **Architect:** the designer of this document. **Implementers:** the models that take one phase at a time (Part 4).
- **Why now:** the AI engine works. The tutor reads files, saves cards, writes notes, keeps topics. What's left is the thing a student feels first: *where do I start, where do I go next, and why is there so much on every screen.*
- **How to use it:** Abel reads Parts 1 to 3 and the decisions in Part 6. An implementer reads Part 5 first, then the one phase assigned, then the sections of Part 3 that phase points to. Each phase is one work package with its own definition of done.

---

## Part 1 · The ambition, in Abel's words

1. **Three AI roles, not one.** An expensive tutor for study sessions; a medium model that reads and summarises big files; a very cheap, fast helper for small jobs on every page (edit a card, answer a quick question, tidy a folder).
2. **No reading of instructions.** Students learn by trying. Short labels, one obvious action, no paragraphs of "do this, don't do that".
3. **Course first.** A workspace is a course. The course is set up once (its syllabus, what it's about, how the student likes to learn), and everything below inherits it: modules know the course, topics know the module.
4. **One clear pathway.** Course → modules → files → topics → study (the whole module, or one topic; never a mix) → mark topics → cards → progress rolls up. Nothing outside the pathway on the screen you're on.
5. **Hierarchy everywhere.** A thousand topics never appear in one list. Topics live in their module; modules in their course; progress adds up upwards.
6. **The AI acts.** It can mark topics, make cards, write notes, add questions, check answers, quiz and test, and read what the student uploads. The engine and the "body" (the platform) must fit together properly.
7. **Every device.** Phone, tablet, desktop, with a layout that fits each.

One more sentence that shapes everything below, from the same conversation: *"I might change model every time."* So: **the platform remembers; the model is replaceable.** The course, the topics, their statuses, the cards and the notes live in ViStud. Any model, cheap or expensive, is handed that state when it starts. Nothing important lives only in a chat.

---

## Part 2 · What exists today: the honest inventory

### 2.1 In numbers

Laravel 13, Livewire 4, Tailwind 4, MySQL. About 29,000 lines of PHP in `app/`, 116 Blade views, 30 Livewire components, 9,200 lines of CSS, 586 PHP test methods (603 tests in the run) and 37 browser specs with accessibility checks. 178 commits. The engine talks to OpenRouter in the OpenAI chat format with streaming, and has 19 tools. All of it works and is tested; none of it needs to be thrown away. The problem is arrangement, not quality.

### 2.2 The map of screens, and what should happen to each

| Area | Screens today | Verdict for ViStud 2 |
|---|---|---|
| Home | All workspaces (cards, archived) | Keep. Rename to **Courses**. |
| Course Overview | Greeting, Start studying, study-time stats and week bars, To do (deadlines), Continue, Modules (5), More menu | **Rebuild** as the course home: one "Next" line, progress, modules. Stats fold into Progress. |
| Modules | Grid/list of modules with counts and topic progress | Keep, trim text. |
| Module page | Title, Questions / Flashcards / Study sessions buttons, New menu, Study this, Topics box (new), folders, notes, files, links | **Rebuild** as the working surface: tabs Topics · Files · Notes · Questions · Sessions. The pathway happens here. |
| Folder page | Same as a module page, inside a folder | Keep (inside the Files tab). |
| Notes & files | Explorer across the course, trash | Keep as "All notes & files", reached from Modules, not a top-level section. |
| Note editor | Rich editor, pop-out window, exports, live updates from the tutor | Keep. Add the Helper's ✦ actions. |
| File page | Preview (PDF, Office via LibreOffice), download | Keep. Add "Read by the AI" (summary, outline, topics). |
| Assignments | List, assignment page, plan (parts, steps, criteria, milestones), sections, PM extras (labels, team, health, priority) | Keep the data and the pages; **hide the PM extras** behind "More" by default. |
| Flashcards | Deck by module (new), editor, AI card maker by copy-paste, review | Keep deck, editor, review. **Retire the copy-paste card maker** once the Reader can make cards from a file or note. |
| Calendar | Course calendar, all-courses calendar | Keep. Course calendar becomes a panel on the course home; the full one stays global. |
| Progress | Every topic of the course, grouped by module, with status buttons, "Evidence:" lines, findings, counts | **Rebuild** as a tree: course → modules → topics; the flat list goes. |
| Questions | Module questions page (filter, sort), question page, session board | Keep; add a course-wide Questions page (stuck first). |
| Study session | Header with topic, clock bar, **six tiles** (Another AI, Save from the chat, Ask a question, New flashcard, Write a note, Notes & files), tutor chat with chips and Quiz me, questions board, paste-and-review capture, timeline, end dialog with topic status | **Rebuild** chat-first. The tiles, the board and the capture panel become a side rail and a "+" menu. |
| AI engine settings | Tutor / quick / fallback model, own key, caps, no-training, language, ask-topics, consent | **Rebuild** around three roles with usage by role and two trust toggles. |
| Admin | Accounts, engine setup (key, default models), audit log | Keep; add default models per role. |
| Journal | The learner's event log, entry pages | Keep the data; move out of the sidebar into Settings → Your data. |

### 2.3 The engine today

- **Roles:** `tutor_model` (the chat), `quick_model` (only wrap-ups and folding), `fallback_model`. No cheap helper on the pages, and no reader: a big file is read inside an expensive chat, five pages a call.
- **Tools (19):** course_overview, topics, questions, findings, assignments, assignment_plan, calendar, notes, read_note, read_file, search_notes, files, earlier_sessions, set_topic, add_topics, make_flashcards, save_key_points, add_questions, write_note.
- **The prompt stack a chat sends:** `resources/prompts/tutor.md` (181 lines, about 4,000 tokens) + the **briefing** (`App\Study\Briefings`, up to about 15,000 tokens: about the student, the course, the module, the session, findings, confusing topics, open questions, due soon, earlier sessions, material, *all topics in the course*, notes) + the tools section + the Topics rules + Language + Diagrams + the folded summary. The briefing was designed to be **pasted into another AI** that has no tools. Inside ViStud's own chat it repeats what the tools can fetch on demand, and it is prose. That is the single biggest cost and confusion source on the AI side.
- **What's good and stays:** streaming, attachments and file text extraction (`FileTexts`), the write-back with fingerprints (`WriteBack`), Effects reporting, the Fake engine for tests, the reasoning round-trip for Claude, cost caps.

### 2.4 Where the overwhelm comes from (the diagnosis)

1. **Two ways to do everything.** Every AI thing exists twice: the built-in tutor *and* a copy-paste path (briefing download, "Save from the chat" paste-and-review, the flashcard prompt-and-paste maker). Both are on the screen at once.
2. **Seven doors per course, plus three global ones,** and the student doesn't know which one to open. Topics, the thing that makes progress work, live on **Progress**, far from the module they belong to.
3. **The session page is furniture around a chat.** Six tiles, a questions board, a capture panel, a timeline and a clock bar compete with the one thing that matters: the conversation.
4. **Text everywhere.** Panel hints, "Evidence: seen, not practised", legends, status words, dialogs with four fields before anything happens ("Start studying" asks topic, module, teaching method, clock).
5. **No pathway.** Nothing says *you are here; next, do this*. A new course shows a greeting and an empty "Continue".
6. **Progress is flat.** Every topic of the course on one page, each with three status buttons.
7. **Assignments carry project-management depth** (labels, team, health, priority, milestones) a student rarely needs; it widens every menu.
8. **The AI roles are implicit.** One expensive model does everything, including reading files and small edits; nothing cheap is available on the pages.
9. **Words.** Workspace vs course; findings vs key points; "marks"; "evidence"; "journal". A student shouldn't meet any of them.

### 2.5 What must not change (the foundations)

- Learner isolation (`LearnerTables`, `Guard`, `LearnerScope`): every query filtered by learner; another student's things are 404.
- The journal and its evidence (ADR 0001, ADR 0002): records, claims, attempts; `Memory::append`. Mastery is earned from evidence, never typed.
- Theme tokens only (DESIGN.md §3; `ThemeEnforcementTest`, `StylePerformanceTest`).
- The flashcard ladder, the write-back, the fingerprints, the tool registry, streaming, `FileTexts`, the note editor and its live updates.
- The tests. Every phase ends green, with new tests for what it adds.

---

## Part 3 · The design

### 3.0 A student's week, after ViStud 2

Sara starts the Operating Systems course. She types its name and drops the syllabus PDF on the page. ViStud reads it (the Reader, a cheap model) and shows: "This course has 12 modules. Add them?" She taps **Add all**. Four quick questions follow, as chips: how she likes things explained, how fast, how often to be checked, what her goal is. She taps four chips and is on the course home, which says **Next: add the files for Module 1**.

She opens Module 1 and drops the week's slides and lab sheet. A moment later the module shows **Topics: Processes, Threads, Context switching (from Lecture 1.pdf) · Add all**. She adds them and taps **Study this module**. The tutor (the expensive model) opens the chat already knowing the course, her preferences and the module, and starts on Processes. When she says "next", the session's topic moves to Threads by itself. She asks for cards; three appear under the module. At the end, the tutor says Processes and Threads are understood and Context switching still needs work; she taps **OK**. The module shows 2 of 3, the course shows 1 of 12 started, and the course home says **Next: Context switching, 1 topic left in Module 1**.

On the bus she reviews cards on her phone. In the library, on a question she got stuck on, she taps ✦ and the Helper (the cheapest model) answers from her notes in a second. Nothing on any screen told her how to do any of this.

### 3.1 Principles (the eight rules every screen follows)

1. **One path.** Every screen knows the student's next step and shows it first. Everything else is below or behind a menu.
2. **Hierarchy.** Course → Module → Topic. A thing is shown inside its parent; nothing is shown in a flat list across the course unless the student asks for "all".
3. **Show, don't explain.** No hint paragraphs. A label, a button, an empty state with one line and one action. Help is a tooltip or a "?" the student can ignore.
4. **One primary action per screen.** It is the biggest button, top right (desktop) or bottom (phone).
5. **The AI works quietly.** It reads files, suggests topics, moves the session's topic, proposes statuses. It asks only where the student would want to be asked, and each of those is a setting (3.6.6).
6. **The platform remembers; the model is replaceable.** All state is in the database; the model is handed a short, structured brief. Swapping models loses nothing.
7. **Cheap by default.** Three roles; the cheapest model that can do the job does it; the expensive one teaches.
8. **Phone first.** Every screen is designed at 390 px first, then widened. No horizontal scroll, ever.

### 3.2 Words

| In the code | In the UI (ViStud 2) | Meaning |
|---|---|---|
| workspace | **Course** | One subject the student takes. |
| module | **Module** | A part of the course: a week, a chapter, a unit. |
| topic | **Topic** | One part of a module, like a section of a chapter. The unit progress is measured in. |
| study_session | **Session** | One sitting, with the tutor or alone, in a module and on a topic. |
| flashcard | **Card** | A question and its answer, reviewed on the ladder. |
| question | **Question** | Something the student doesn't get yet; pending, stuck or answered. |
| finding | **Key point** | One thing worth remembering, on a topic. |
| note / file | **Note / File** | The student's own writing; what they uploaded. |
| instructions `me` | **About you** | Free text the tutor always sees. |
| journal | **Your data** | The record ViStud keeps. Not a student word. |
| evidence, marks, briefing, write-back | *(not shown)* | Internal words. |

The `workspaces` table and the `Workspaces` service keep their names. Only labels, titles and routes' words change (`/courses/...` can alias `/workspaces/...` in Phase 6; the old URLs keep working).

### 3.3 The pathway (the spine of the product)

```
 1 Set up          2 Add a module     3 Topics          4 Study            5 Mark &        6 Progress
   the course        and its files      appear            (Tutor)            practise        rolls up
 ───────────── ▶ ────────────── ▶ ────────────── ▶ ─────────────── ▶ ────────────── ▶ ──────────────
 name, syllabus,   upload slides,     Reader reads      whole module       status per      topic → module
 how you learn     lab sheets         the files,        or one topic;      topic (tutor    → course;
 (Reader builds    (Reader reads      proposes topics   quiz, test;        proposes, you   "Next" on the
 the course        each on upload)    (one tap to add)  cards, key         confirm or      course home
 profile)                                               points, notes,     delegate);
                                                        questions saved    review cards
                                                        in place
```

**The "Next" rule.** The course home, the module page and the end of a session all show one line, computed, never typed:

| State | Next says | Button |
|---|---|---|
| No modules | Add your first module | Add module |
| A module with no files and no topics | Add Module 1's files | Open module |
| Files read, topics suggested, none added | Add the topics found in Lecture 1.pdf | Add topics |
| Topics, none studied | Study Module 1 | Study |
| A session is open | Continue the session on *Threads* | Continue |
| Topics left in the current module | *Context switching*: 1 topic left in Module 1 | Study |
| Cards due ≥ 10 | Review 12 cards (5 min) | Review |
| A test under 50 % or a topic confusing for 7+ days | Go over *Deadlocks* again | Study |
| Current module done, next module exists | Start Module 2 | Open module |
| A deadline within 3 days | *ER diagram* is due on Thursday | Open |
| Everything done | All caught up. Review cards or add a module | Review |

The priority is the table's order, except that an open session and a deadline within 24 hours always win. The service is `App\Study\NextStep` (Phase 1) and it is pure: it takes the course's state and returns one `{text, action, url}`.

### 3.4 Information architecture and navigation

**Global (the "Everywhere" group):** Courses · Calendar · Settings (AI engine, Appearance, Security, Your data).

**Inside a course, the sidebar (desktop and tablet):**

| Section | What it is |
|---|---|
| **Home** | Next, progress ring, modules in order, Coming up, Continue. |
| **Modules** | The modules, in order; "All notes & files" and search in its header. |
| **Cards** | The deck by module; review. |
| **Questions** | All open questions, stuck first, by module. |
| **Assignments** | As today, PM extras hidden under More. |
| **Progress** | The tree: course → modules → topics. |

Six, not seven. Notes & files is reached from Modules (and from inside each module). The course calendar is the "Coming up" panel on Home plus the global Calendar.

**Phone, the bottom tab bar:** Home · Modules · Cards · **Ask** · More (Questions, Assignments, Progress, Notes & files, Calendar, Settings). Ask is the Helper (Phase 5); until then the bar has four tabs.

**The page template** (every page, no exceptions):

```
[←  Parent]                                           [Primary action] [⋯]
Title                                                  (one-line context, optional)
──────────────────────────────────────────────────────────────────────────────
content: lists, tabs, panels. No hint paragraphs. Empty state = icon + 1 line + 1 button.
```

On the phone the primary action moves to a sticky bottom bar; right-side panels become bottom sheets; the "⋯" menu stays top right.

### 3.5 The screens

#### 3.5.1 Course home (replaces the Overview)

```
┌──────────────────────────────────────────────────────────────────────┐
│ ← Courses                                      [Study ▶]  [⋯]        │
│ Operating Systems                               Module 3 of 12       │
│                                                                      │
│ ▶ Next: CPU scheduling · 1 topic left in Module 3        [Study]     │
│                                                                      │
│ ┌ Progress ───────────┐ ┌ Modules ───────────────────────────────┐  │
│ │   ◯ 23 %            │ │ 1 Introduction            ▓▓▓▓▓▓ done  │  │
│ │ 9 of 40 topics      │ │ 2 Processes               ▓▓▓▓░░ 4/6   │  │
│ │ 12 cards due        │ │ 3 Process management  ●   ▓▓░░░░ 2/7   │  │
│ │ 2 stuck questions   │ │ 4 Memory                  ░░░░░░ 0/5   │  │
│ └─────────────────────┘ │ … All modules                           │  │
│                         └─────────────────────────────────────────┘  │
│ ┌ Coming up ──────────┐ ┌ Continue ──────────────────────────────┐  │
│ │ Thu · ER diagram due│ │ Yesterday · Session on Threads, 40 min │  │
│ │ Mon · Midterm       │ │ "Stopped before context switching"     │  │
│ └─────────────────────┘ └─────────────────────────────────────────┘  │
└──────────────────────────────────────────────────────────────────────┘
```

- **Study ▶** starts in the current module on the next topic, no dialog. The clock and the teaching options are remembered from last time; they live under ⋯ ("Session settings").
- The greeting, the week bars and the stats panel go (the week bars move to Progress).
- Phone: the same blocks stacked; Next is the first thing under the title; Study is the sticky bottom button.

#### 3.5.2 Course setup (new; also the "About this course" page later)

A three-step sheet after "New course", every step skippable:

1. **Name and colour** (as today).
2. **What is this course?** One box: drop the syllabus (PDF, Word, PowerPoint) or paste text, or type two lines. The Reader builds the **course profile**: about, learning outcomes, assessment (exams, coursework, weights, dates), textbook, and a **proposed module list**. The student sees the list with ticks: *Add 12 modules?* [Add all] [Pick]. Dates found become module dates and calendar entries (assessments become assignments with due dates).
3. **How do you like to learn?** Four chip questions, one line each, multi-choice where it makes sense, all optional:
   - *Explain with:* examples first · theory first · analogies · diagrams
   - *Pace:* small steps · normal · fast
   - *Check me:* often · at the end · rarely
   - *My goal:* pass · top marks · understand deeply · finish the coursework
   - *(optional line)* Anything else the tutor should know.

The answers are the **learner profile** for this course (`learner_profiles`, 3.7). They replace the four "How the AI teaches" dropdowns as the default; a session can still override them from ⋯. The existing "About you" free text stays and applies to every course. (On learning styles: research doesn't support fixed "visual" or "auditory" learner types, so ViStud asks how the student *likes things explained*, which the tutor can act on, and never labels the student.)

#### 3.5.3 Module page (the working surface)

```
┌──────────────────────────────────────────────────────────────────────┐
│ ← Modules                                   [Study this ▾]  [⋯]      │
│ 3 · Process management                       ▓▓░░░░  2 of 7 understood│
│ ─ Topics (7) ─ Files (4) ─ Notes (2) ─ Questions (3) ─ Sessions (5) ─ │
│                                                                      │
│ ✦ 3 new topics found in Lecture 3.pdf: Scheduling, Context switching,│
│   Deadlocks                                        [Add all] [Pick]  │
│                                                                      │
│ ● Processes             understood   5 cards        [Study]          │
│ ● Threads               understood   3 cards, 1 due [Study]          │
│ ◐ CPU scheduling        confusing    2 cards        [Study]          │
│ ○ Memory of a process   not started                 [Study]          │
│ …                                                                    │
│ [ Add a topic…                                                    ]  │
└──────────────────────────────────────────────────────────────────────┘
```

- **Study this ▾:** Whole module · Pick a topic · Quiz me · Test me. One tap, no dialog.
- **Topics tab:** the list, status chips (icon + one word), cards count, Study per row; the AI suggestion bar on top when the Reader found topics; the add box at the bottom. A row opens the **topic sheet** (status, cards, questions, key points, sessions; Study; rename; move).
- **Files tab:** drop zone + rows. Each row shows **Read ✓**, **Reading…**, or **Read now**; tapping a read file shows its one-paragraph summary and outline; ✦ offers Summarise · Make a note · Make cards · Find topics.
- **Notes tab:** the module's notes (and folders). **Questions tab:** the module's questions with the filter. **Sessions tab:** as the module sessions page today.
- The module's own instructions for the tutor stay under ⋯ ("Tell the tutor about this module").

#### 3.5.4 The session (chat first)

```
┌──────────────────────────────────────────────────────────────────────┐
│ ← Module 3     CPU scheduling ▾      ⏱ 00:35   [Pause] [End]  [⋯]    │
├──────────────────────────────────────────────┬───────────────────────┤
│                                              │ Topics in Module 3    │
│  Tutor: **Slide 4 of 18 · Round robin**      │ ● Processes           │
│  …                                           │ ● Threads             │
│                                              │ ◐ CPU scheduling  ◀   │
│  You: so the quantum is the time slice?      │ ○ Deadlocks           │
│                                              │                       │
│  Tutor: Exactly. …                           │ Material              │
│  ┌ Saved: 3 cards · Topic: CPU scheduling ┐  │ 📄 Lecture 3.pdf      │
│                                              │ 📝 My notes           │
│                                              │                       │
│ [Quiz me] [Cards] [Note this] [Where are we] │ This session          │
│ ┌──────────────────────────────────── 📎 ➤ ┐ │ 3 cards · 1 key point │
│ │ Write…                                   │ │ 12 min on Threads     │
└──────────────────────────────────────────────┴───────────────────────┘
```

- The chat fills the page. The **rail** (desktop and tablet) holds the module's topics (tap to switch the session's topic), the material (tap to attach), and what this session saved. On the phone the rail is a bottom sheet opened from the topic name in the header; the composer is sticky.
- The six tiles go. Their jobs: *Another AI* → ⋯ menu ("Use another AI by copy-paste", visible only when the setting in 3.6.6 is on); *Save from the chat* → same place; *Ask a question* → chip "Ask" in the composer's + menu and the `add_questions` tool; *New flashcard* → "Cards" chip / + menu; *Write a note* → "Note this" chip / + menu; *Notes & files* → the rail's Material.
- The questions board and the timeline leave the page (the module's Questions tab and Sessions tab have them).
- **Study modes** (3.9) set the header's second word: *Whole module*, *CPU scheduling*, *Quiz*, *Test*.
- **End** opens one screen: time studied; the topics touched, each with the status the tutor proposes as an editable chip; what was saved; the wrap-up summary; **Done**. No radio list.

#### 3.5.5 Progress (the tree)

```
Operating Systems                                  ◯ 23 %  ·  9 of 40 topics
[All] [Needs attention 4] [Not started 22] [Mastered 3]

▸ 1 Introduction            ▓▓▓▓▓▓  5/5    tested 90 %
▾ 3 Process management      ▓▓░░░░  2/7
    ● Processes          understood · 5 cards · 2 sessions          [Study]
    ◐ CPU scheduling     confusing · since 2 Oct · 2 cards           [Study]
    ○ Deadlocks          not started                                 [Study]
▸ 4 Memory                  ░░░░░░  0/5
```

Modules collapsed by default except the current one. A topic row opens the topic sheet. The "Evidence:" line becomes a tooltip on the status chip ("practised 3 times, last on 2 Oct"). The three status buttons per row go: the status is changed in the topic sheet or by the tutor.

#### 3.5.6 Cards, Questions, Assignments, Settings

- **Cards:** as built on 2026-10-05 (by module, filters, module review), with ✦ on each card (Phase 5). The copy-paste card maker is replaced by *Make cards from…* (a file, a note, a topic), run by the Reader.
- **Questions (course-wide):** stuck first, then pending, grouped by module; each row: text, module · topic, status chip; ✦ *Ask the tutor* starts a session on the question's topic with the question as the first message; *Answer from my notes* uses the Reader.
- **Assignments:** unchanged pages; labels, team, health and priority behind "More" (a per-course toggle "Project tools" turns them on).
- **AI engine (settings):** three model fields with one line each (*Tutor: teaches in sessions · Reader: reads files, writes summaries and cards · Helper: quick edits and questions*), a fallback, caps, **usage this month by role**, the language, and the trust toggles (3.6.6). Consent and the own key as today.

### 3.6 The engine, version 2

#### 3.6.1 Three roles

| Role | Does | Model class (owner's choice) | Tools | Context it gets | Runs as |
|---|---|---|---|---|---|
| **Tutor** | Teaches in sessions; quizzes and tests; saves cards, key points, questions, notes; keeps topics and proposes statuses | The best the budget allows (e.g. a Sonnet- or GPT-class model) | All 19 + `set_topic_status`, `record_quiz`, `module_files` | Role prompt, tools, course profile, learner profile, module brief, session state, conversation | A streamed chat (`SessionChat`) |
| **Reader** | Reads a file and writes its digest; builds the course profile from a syllabus; writes a note from a file; makes cards from a file, a note or a topic; wraps up a session; folds a long chat | A cheap long-context model (e.g. a Flash-, Haiku- or 4.1-mini-class model) | None (input in, JSON out) | A job prompt + the text, nothing else | A **job** (`engine_jobs`, 3.6.3) |
| **Helper** | Improves a card, clarifies a question, explains a selection, suggests where a file goes, answers a quick question about the course | The cheapest fast model (e.g. a Flash-Lite-, Nano- or Nova-Lite-class model) | Read-only: course_overview, topics, questions, findings, notes, search_notes, files (never write) | A one-line task + the thing + at most the module brief | A short call, not kept (`Helper::quick`) |
| *Fallback* | Tried when the Tutor fails | Any | as Tutor | as Tutor | as Tutor |

The existing `quick_model` becomes `reader_model` (migration renames the column; the value carries over). `helper_model` is new. The owner's defaults (`platform_settings`, admin Engine setup) gain the two new roles. Every role counts toward the month cap; the settings page shows the month's cost by role.

#### 3.6.2 The context layers (the prompt stack)

Stable layers first, identical byte for byte across calls, so the service's prompt cache hits (OpenRouter passes Anthropic's cache markers through; OpenAI caches stable prefixes on its own). Dynamic layers last. Each layer has a budget; the builder (`App\Engine\Context\Stack`) cuts from the bottom up and never silently drops a whole layer: it says "(cut)".

| # | Layer | Content | Budget (tokens) | Changes |
|---|---|---|---|---|
| 0 | **Role prompt** | `resources/prompts/tutor-2.md` (or reader-\*.md, helper.md): role, method, tool rules, marks, the words the student can use | Tutor ≤ 2,500 · Reader ≤ 600 · Helper ≤ 300 | Only with a release |
| 1 | **Tools** | The role's tool definitions | by role | Only with a release |
| 2 | **Course profile** | name, about, outcomes, assessment with dates, course language, the student's course instructions | ≤ 600 | When the profile is edited |
| 3 | **Learner profile** | preferences (3.5.2) as one line each, "About you", the chosen language, the trust toggles as one line each | ≤ 250 | Rarely |
| 4 | **Module brief** | the module's title and dates, its topics with status (one line each), its files with their one-line digest, open questions (≤ 5), key points (≤ 8), the last session's checkpoint | ≤ 700 | On every change in the module (cached, `module_briefs`) |
| 5 | **Session state** | mode, topic, clock, the tutor's checkpoint and summary so far, material attached, what's been saved this session | ≤ 300 | Every turn |
| 6 | **Conversation** | the turns, folded beyond the threshold (as today) | the rest | Every turn |

Standing cost before the conversation: about 5,600 tokens for the Tutor (the rules 2,400, the 19 tools 2,950, a few hundred of course, student, module and session), against up to 22,000 before Phase 0 (the briefing alone could be 15,000). The **briefing** (`Briefings`) stays only for the copy-paste path; the chat stops using it.

Structured layers are written as compact markdown lists or small JSON, never prose:

```
## Module 3 · Process management (28 Sep – 11 Oct)
Topics: Processes=understood · Threads=understood · CPU scheduling=confusing(since 2 Oct) · Deadlocks=not started
Files: Lecture 3.pdf (18 pages: scheduling algorithms, round robin, priority) · Lab 3.docx (6 pages: scheduler simulation)
Open questions: "Why does round robin starve long jobs?" (stuck) · "What is a quantum?" 
Key points: A context switch saves and restores registers · …
Last time: stopped at slide 7 of 18, before priority scheduling
```

#### 3.6.3 Jobs: how the Reader and the Helper run

- **`engine_jobs`** (3.7): one row per run: kind (`read_file`, `profile_course`, `brief_module`, `note_from_file`, `cards_from`, `wrap_up`, `fold`, `quick`), target id, status (`queued`, `running`, `done`, `failed`, `skipped`), model, tokens, cost, error code, timings. The month cap counts them.
- **Queue:** the database queue (already configured). `php artisan dev` runs a worker. When no worker has reported a heartbeat in the last 60 seconds, jobs run inline at the end of the request (`dispatchAfterResponse`), so a Windows laptop without a worker still works, only slower. Tests run them synchronously.
- **Progress on screen:** a job's target shows a chip (*Reading…*) that polls every 3 s (`wire:poll`) until done. Failures show one line and a *Try again*.
- **Idempotence:** a file is read once per content version (`files.content_hash` + model); a module brief is rebuilt only when its fingerprint changes.

#### 3.6.4 The prompting method (what the research says, applied)

1. **Stable before dynamic.** Caching and attention both reward it. Role, tools, course, learner, then module, session, conversation.
2. **One role, one job, one prompt.** The Tutor never gets Reader instructions. Behaviour toggles arrive as one line each from settings, not as paragraphs of options.
3. **Structured, compact state.** Lists and small JSON; names exact (the tool matching is by name).
4. **Every tool has a one-line *when* and a one-line *when not*.** "Use set_topic when you move to another part; not for a passing question."
5. **Do, not don't.** Say what to do; keep the don'ts to the few that matter (never invent what's not in the material; never save what wasn't asked for or agreed to).
6. **Examples for formats, not for behaviour.** One example of a quiz question, one of a mark. Behaviour comes from rules.
7. **The task last.** The session state and the student's message are the end of the prompt.
8. **Budgets everywhere,** and a visible "(cut)" when something is dropped.
9. **One question at a time; end with the next step.** Already in tutor.md; stays.
10. **Nothing personal.** No name, no email (as today); the learner profile is preferences, not identity.
11. **Short prompts for small models.** The Helper's prompt fits on one screen; it is the one that runs most often.
12. **Prompts are tested.** The Fake engine checks structure (what's in the stack, budgets, tool rules). A `prompts:try` console command runs five canned scenarios against the live models and prints the replies, so a prompt change can be eyeballed in a minute.

#### 3.6.5 The AI action catalogue

| Action | Role | Tool / job | Asks first? |
|---|---|---|---|
| Read a file; summary, outline, topics | Reader | `read_file` job on upload | No (setting *Read my files automatically*, on) |
| Build the course profile and propose modules from a syllabus | Reader | `profile_course` job | The student ticks the modules |
| Add topics to a module | Tutor (chat) · Reader (suggestions) | `add_topics` · suggestion bar | Tutor: no, unless *Ask me before adding or switching topics* · Reader: one tap |
| Set the session's topic | Tutor | `set_topic` | No, unless the setting above |
| Propose or set a topic's status | Tutor | `set_topic_status` | Setting *Let the tutor mark topics* (on: applied, with undo; off: shown as a chip to accept) |
| Save cards, key points, questions | Tutor | `make_flashcards`, `save_key_points`, `add_questions` | Only when asked or agreed (as today) |
| Write in a note | Tutor | `write_note` | Only when asked or agreed |
| Quiz (5 questions, a topic) · Test (10–15, a module, scored) | Tutor | chat + `record_quiz` | The student starts it |
| Make cards from a file, a note or a topic | Reader | `cards_from` job → write-back | The student starts it; cards appear ticked for review |
| Write a note from a file | Reader | `note_from_file` job | The student starts it |
| Wrap up a session; fold a long chat | Reader | `wrap_up`, `fold` | No |
| Improve a card, clarify a question, explain a selection, suggest a folder | Helper | `quick` | The student taps ✦ |
| Answer a quick question about the course | Helper | `quick` with read tools | The student asks |
| Answer a stuck question from the notes | Reader | `answer_from_notes` job | The student taps |

Mastery (`mastered`) is never set by anyone: it is earned from evidence as ADR 0002 says.

#### 3.6.6 Trust settings (the only AI toggles a student sees)

| Toggle | Default | Effect |
|---|---|---|
| Read my files automatically | on | Files uploaded to a module are read by the Reader at once. Off: "Read now" on each file. |
| Let the tutor mark topics | on | The tutor's `set_topic_status` applies, with undo and "set by the tutor" shown. Off: proposals as chips. |
| Ask me before adding or switching topics | off | As built on 2026-10-06. |
| I use another AI by copy-paste | off | Shows the briefing, the paste-and-review and the download in the session's ⋯ menu. |

### 3.7 Data model changes

Additive only. Never `migrate:fresh`; every migration backfills.

| Table / column | Purpose | Phase |
|---|---|---|
| `engine_settings.quick_model` → `reader_model`; `+ helper_model`, `+ auto_read_files` (bool, on), `+ tutor_marks_topics` (bool, on), `+ copy_paste_ai` (bool, off) | Three roles and the trust toggles | 0 |
| `platform_settings`: `engine.reader_model`, `engine.helper_model` | Owner defaults | 0 |
| `engine_jobs` (id, learner_id, workspace_id, role (`reader`/`helper`, added in Phase 0 for *Usage this month*), kind, target_type, target_id, status, model, tokens_in, tokens_out, cost_micros, error_code, attempts, started_at, finished_at, created_at) | Reader and Helper runs; cost accounting | 0 |
| `course_profiles` (id, learner_id, workspace_id, about, outcomes JSON, assessment JSON, textbook, syllabus_file_id, source (`manual`/`file`), model, built_at, updated_at; Phase 1 added `syllabus_text` and `proposed_modules JSON` so a pasted syllabus and the reader's module list can wait for the student) | The course's own description | 1 |
| `learner_profiles` (id, learner_id, workspace_id, preferences JSON, note, updated_at) | How the student likes to learn, per course | 1 |
| `file_digests` (id, learner_id, file_id, content_hash, status, summary, outline JSON, topics JSON, language, pages, chars, model, cost_micros, created_at) | What the Reader found in a file | 2 |
| `topic_suggestions` (id, learner_id, module_id, name, source_file_id, status `suggested`/`added`/`dismissed`, created_at) | Topics found, until the student acts | 2 |
| `module_briefs` (module_id, learner_id, text, fingerprint, built_at) | The cached module layer | 2 |
| `study_sessions.mode` (`module`, `topic`, `quiz`, `test`, `free`; default `topic`) | The study mode | 3 |
| `topics.status_by` (`student`/`tutor`), `topics.status_at` | Who set the status, when | 3 |
| `quizzes` (id, learner_id, workspace_id, session_id, module_id, topic_id, kind, questions JSON, score, started_at, finished_at) | Quizzes and tests as readable records (the attempts stay in the journal) | 3 |
| `workspaces.project_tools` (bool, off) | Shows the PM extras on assignments | 6 |

The `instructions` rows map without loss: `me` stays "About you"; `workspace:{id}` becomes the course profile's *instructions* line; `module:{id}` stays the module's line in the brief. The teaching choices (`study_sessions.tutoring`) stay per session; their defaults come from the learner profile.

### 3.8 Statuses and how progress rolls up

- **A topic's status:** `not_started` · `covered` (seen or taught) · `understood` · `confusing` · `mastered` (earned by evidence only). The student's word always wins over the tutor's; the tutor's word is shown as such and can be undone.
- **A module's progress:** understood + mastered, out of its topics. Shown as a bar and "4 of 7".
- **A course's progress:** the sum over modules, weighted by topic count (so a 2-topic module doesn't count like a 10-topic one). Topics with no module count in a "No module" group.
- **Needs attention:** topics confusing for 7+ days; questions stuck; a test under 50 %; cards overdue by 7+ days. This feeds "Next" and the Progress filter.
- The old flat status counts ("3 covered, 2 confused") become the course ring plus the attention count.

### 3.9 Study modes (never mixed)

| Mode | Started from | The tutor | The record |
|---|---|---|---|
| **Whole module** | Module page → Study this ▾ → Whole module; course home's Study when the module has several topics left | Goes through the module's files in order, topic by topic; moves the topic with `set_topic`; proposes a status at each topic's end | One session, mode `module`; the topic changes inside it (journal records say which topic each attempt was on, as today) |
| **One topic** | A topic row's Study; "Next" | Teaches that topic only; offers the next topic at the end, doesn't start it | mode `topic` |
| **Quiz** | Quiz me | 5 questions on a topic (or what's hardest), one per message, score at the end, `record_quiz` | mode `quiz`; a `quizzes` row |
| **Test** | Study this ▾ → Test me | 10–15 exam-level questions over the module, scored; proposes statuses from the score | mode `test`; a `quizzes` row (kind `test`) |
| **Free** | Ask (the Helper) escalated to the tutor; a question's "Ask the tutor" | Answers; no plan, no checkpoints | mode `free` |

The mode is one line in the session state layer; the tutor prompt has one short paragraph per mode.

### 3.10 Responsive rules (the ones that matter here; DESIGN.md has the rest)

- **Breakpoints:** phone < 640 px (one column, tab bar, sheets), tablet 640–1023 px (sidebar collapsed to icons, one or two columns), desktop ≥ 1024 px (sidebar, rail).
- **Sheets, not side panels, on the phone:** every `<dialog class="modal">` renders as a bottom sheet under 640 px (one CSS rule; the markup doesn't change).
- **The composer is sticky** on the session page; the chat scrolls under it; the keyboard never hides the send button (use `100dvh`, `env(safe-area-inset-bottom)`).
- **Tap targets ≥ 44 px;** row menus open on tap; hover-only affordances have a visible fallback.
- **Tables become lists** under 640 px (the deck, the questions, the assignments).
- **No horizontal scroll at 320 px with 200 % text** (the existing browser checks run on every new screen).
- **Previews:** every rebuilt screen gets phone and desktop screenshots in `docs/design/previews/` (the browser specs take them).

### 3.11 Writing rules for the interface

- A hint is one line of at most 60 characters, or nothing.
- Buttons are verbs: Study, Add, Review, Done. Never "Click here to…".
- A status is an icon and one word.
- An empty state is one line and one button, never a paragraph.
- No internal words (3.2). No "evidence", "marks", "briefing", "write-back", "journal", "engine" on a student screen; "AI" where a model is meant, "tutor" for the Tutor role.
- Errors say what to do next, in one line.

---

## Part 4 · The phases

Each phase is one work package: it leaves the app whole and green, with the old URLs still working. Sizes are in **work units**: one unit is one focused run like this project's features so far (a feature with its tests and docs). Phases 0 → 1 → 2 → 3 → 4 run in order; 5 and 6 can start once 3 is done; 7 is last.

Every phase ends with: the full PHP suite green; the browser specs for the screens it touched green on desktop and phone with no axe violations; `docs/architecture/schema.md` and this document's "Status" updated; a commit per feature; a handoff note `docs/handoff/v2-phase-N.md` (what was built, what Abel tests, what's left); and the message for the local tester (Gemini): fetch, ff-only merge, `composer migrate`, `npm run build`, the phase's targeted tests.

### Phase 0 · Groundwork (2 units)

**Goal:** the pieces every later phase stands on, with no visible change except the settings page.

**Build**
- `App\Engine\Role` (enum `Tutor`, `Reader`, `Helper`) and `Choices::modelFor(Role)`. Migration: `quick_model` → `reader_model` (value kept), `+ helper_model`, `+ auto_read_files`, `+ tutor_marks_topics`, `+ copy_paste_ai`. Owner defaults for the two new roles in admin Engine setup; `Setup::defaultModels()` returns all three.
- `engine_jobs` table, `App\Engine\Jobs\Job` (base: run, account cost against the month cap, record the row) and `App\Engine\Jobs\Runner` (queue or inline, 3.6.3), with the worker heartbeat (`cache('engine.worker_seen_at')` set by a `queue:work` listener or the `dev` command every 30 s). `php artisan dev` starts the worker.
- `App\Engine\Context\Stack` with layers and budgets (3.6.2), returning the system prompt and a report of what was cut. Layers 0–3 and 5 implemented; layer 4 arrives in Phase 2 (until then it holds the module's topics and instructions only). `SessionChat::system()` uses the Stack; the briefing is no longer sent in the chat. `resources/prompts/tutor-2.md`: the current tutor.md cut to ≤ 2,500 tokens (keep: role, method, the path of a session, tools when/when not, words the student can use, marks, ending; drop: duplicated explanations, the long briefing guidance).
- `App\Engine\Helper::quick(Principal, string $task, string $thing, ?string $moduleId): string` with the Helper's read-only toolbox (no UI yet).
- Settings page: three model fields with one line each, usage by role this month, the trust toggles. `Choices` and `Settings` updated; the `fallback_model` stays.
- Page template Blade component `<x-page>` (title row, primary action, ⋯ menu, context line) and the microcopy rules (3.11) written into DESIGN.md §5.
- UI word: **Course** everywhere a student sees "workspace" (labels only; routes in Phase 6).

**Tests:** `Engine/RolesTest` (model per role, defaults, migration carries the quick model), `Engine/JobsTest` (queue vs inline, cost counted, failure recorded), `Engine/StackTest` (layers in order, budgets, cuts reported, byte-identical stable prefix across two sessions of one course), `Web/EngineSettingsScreenTest` (three roles, toggles). Update `SessionChatTest` for the Stack.

**Abel checks:** AI settings show Tutor, Reader, Helper; a chat still works and says the same things; the "Usage this month" box shows the chat's cost under Tutor.

**Out of scope:** any new screen; the Reader's jobs; the Helper's ✦ buttons.

### Phase 1 · Course setup and the course home (3 units)

**Goal:** a new course starts with the AI knowing what it is and how the student learns, and the course home says what to do next.

**Build**
- `course_profiles`, `learner_profiles`; services `App\Study\CourseProfiles`, `App\Study\LearnerProfiles`.
- Reader job `ProfileCourse` (`resources/prompts/reader-course.md`): syllabus text (≤ 40,000 characters, from `FileTexts` or pasted) → JSON `{about, outcomes[], assessment[{name, kind, weight, due_on}], textbook, modules[{title, starts_on, ends_on}]}`; validated; the student reviews the modules with ticks and the assessment with dates; *Add* creates modules in order and assignments for dated assessments.
- The setup sheet (3.5.2): three steps after "New course"; the same steps reachable later from the course's ⋯ → *About this course* and *How you learn*.
- Context layers 2 and 3 filled from the profiles. The teaching defaults (`Tutoring::DEFAULTS`) come from the learner profile; the session's ⋯ keeps the override.
- `App\Study\NextStep` (3.3) and the course home (3.5.1): Next line, progress ring (temporary: understood topics / all topics, until Phase 4), modules with bars, Coming up (the existing Tasks panel, trimmed), Continue. *Study ▶* starts on the next topic with the remembered choices, no dialog. The week bars and stats move to Progress (Phase 4; until then they sit at the bottom of Progress as they are).

**Tests:** `Study/CourseProfilesTest`, `Study/LearnerProfilesTest`, `Engine/ProfileCourseJobTest` (a fake Reader reply → modules proposed → ticked ones created with dates; a bad reply is a clean failure), `Study/NextStepTest` (every row of the Next table, as a pure test), `Web/CourseSetupScreenTest`, `Web/CourseHomeScreenTest`; browser spec `course-setup.spec.js` (desktop + phone, axe).

**Abel checks:** New course → drop the OS syllabus → the modules appear ticked → Add → four chip questions → the course home says *Next: add Module 1's files*. Open AI settings → nothing new to configure.

**Out of scope:** reading module files (Phase 2); the Progress tree.

### Phase 2 · The module page as the working surface (3 units)

**Goal:** files go in, topics come out, and studying starts from the same screen.

**Build**
- `file_digests`, `topic_suggestions`, `module_briefs`; services `App\Study\FileDigests`, `App\Study\TopicSuggestions`, `App\Study\ModuleBriefs` (builds layer 4, caches by fingerprint, invalidated by `Topics`, `Files`, `Questions`, `Findings`, `Sessions` events).
- Reader job `ReadFile` (`reader-file.md`): the file's text (first 30,000 characters plus the outline of the rest from `FileTexts`) → JSON `{summary ≤ 600 chars, outline[{page, heading}], topics[≤ 8 names], language}`. Runs on upload into a module when *Read my files automatically* is on; *Read now* otherwise; once per content hash. Pictures and files without text are `skipped`.
- The module page (3.5.3): tabs Topics · Files · Notes · Questions · Sessions; the suggestion bar; the topic rows with status chips, cards, Study; the add box; *Study this ▾* with Whole module · Pick a topic (sheet with the topic list) · Quiz me · Test me (the last two land in Phase 3; until then they start a topic session with a first message). The topic sheet (status choice, cards, questions, key points, sessions, rename, move).
- File rows: Read ✓ / Reading… / Read now; the summary and outline on tap. The file page shows the same.
- Layer 4 goes live in the Stack; the tutor's `read_file` stays for reading on; a new read tool `module_files` returns the digests (so the tutor knows what each file is without opening it).
- Modules page header: *All notes & files* link and search. The Notes & files section leaves the sidebar (route kept).

**Tests:** `Study/FileDigestsTest` (once per hash, skipped kinds, cost counted), `Engine/ReadFileJobTest`, `Study/TopicSuggestionsTest` (add all, pick, dismiss, no duplicates against existing names), `Study/ModuleBriefsTest` (content, budget, invalidation), `Web/ModulePageScreenTest` (tabs, suggestion bar, Study ▾, topic sheet), `Engine/StackTest` (layer 4 present and cut cleanly); browser spec `module-page.spec.js` (desktop + phone: drop a file, see Reading…, topics suggested, Add all).

**Abel checks:** Open Module 1 → drop the slides → *Reading…* → the bar says *3 new topics found* → Add all → tap Study this → Whole module → the tutor starts on the first topic knowing the slides.

**Out of scope:** the session page redesign; quizzes and tests as records.

### Phase 3 · The session, chat first (4 units)

**Goal:** the session page is the conversation; everything else is a rail or a menu; the tutor keeps topics and statuses.

**Build**
- Layout (3.5.4): the header (back, topic ▾, clock, Pause, End, ⋯), the chat full height with the sticky composer and the chips (Quiz me · Cards · Note this · Where are we; + menu: Ask a question, Attach), the rail (Topics in this module with switch; Material with attach; This session). Phone: rail as a sheet from the header; composer sticky with the keyboard.
- `study_sessions.mode`; the start paths set it (3.9); the mode's paragraph in `tutor-2.md`; the session state layer carries it.
- Tutor tools: `set_topic_status {topic, status, reason}` honouring *Let the tutor mark topics* (apply with `status_by = tutor` and an undo chip, or propose as a chip); `record_quiz {kind, topic|module, questions[], score}`; `module_files` (from Phase 2). `quizzes` table and `App\Study\Quizzes`.
- Quiz me and Test me: Quiz = 5 questions on the chosen topic (the existing Quiz me menu, kept), Test = 10–15 over the module at exam level, scored, proposes statuses from the score (≥ 80 % understood, ≤ 50 % confusing, else covered).
- The end screen (3.5.4): time, topics touched with proposed statuses as editable chips, what was saved, the wrap-up summary (Reader job, as today), Done.
- The ⋯ menu: Session settings (clock, teaching, as today's dialogs), Tell the tutor about this module, and, when *I use another AI by copy-paste* is on, Briefing (copy, download) and Save from the chat (the capture panel as a sheet).
- The questions board and the timeline leave the session page (the module's tabs hold them); the six tiles and their component wiring go (`StudySession` slims down; `SessionCapture` and the briefing dialog stay as components opened from the menu).

**Tests:** `Engine/ToolboxTest` (set_topic_status both settings, record_quiz, module_files), `Study/QuizzesTest`, `Study/SessionsTest` (modes), `Web/SessionScreensTest` (header, rail, chips, end screen, menu paths, copy-paste hidden by default), `Web/TutorChatScreenTest` (status chip apply/undo, quiz record shown); browser specs `sessions.spec.js` and `chat.spec.js` updated (desktop + phone: sticky composer with the keyboard, rail sheet, end screen).

**Abel checks:** Start *Whole module* → the chat fills the screen → say "next" → the rail's arrow moves to the next topic → "quiz me" → 5 questions → End → the topics show the tutor's statuses → Done → the module page shows them.

**Out of scope:** the Progress tree; the Helper's ✦.

### Phase 4 · Progress that rolls up (2 units)

**Goal:** the student sees where they stand at every level and never a flat list.

**Build**
- `App\Study\Rollups` (3.8): topic → module → course numbers, needs-attention, with one query per course (no N+1), cached per request.
- The Progress page (3.5.5): ring, filters, modules collapsed with bars, topic rows with the status chip (tooltip for the practice words), Study; the topic sheet shared with Phase 2. The week bars and study-time totals as a small panel at the bottom.
- The course home and the Modules page use `Rollups` (the temporary numbers from Phase 1 go). `NextStep` reads the attention list.
- `topics.status_by`/`status_at` shown ("set by the tutor, 5 Oct").
- The old flat Progress (findings per topic, the three buttons per row, "Evidence:") goes; key points show in the topic sheet.

**Tests:** `Study/RollupsTest` (weights, no-module group, attention rules, one query), `Web/ProgressScreenTest` (tree, filters, sheet), `Study/NextStepTest` (attention rows); browser spec `progress.spec.js` updated (desktop + phone, axe).

**Abel checks:** Progress shows the ring and the modules collapsed; opening Module 3 shows its topics; the course home's number matches Progress; a confusing topic older than a week appears under *Needs attention* and in *Next*.

**Out of scope:** charts beyond the ring and bars.

### Phase 5 · The Helper everywhere (3 units)

**Goal:** small AI jobs are one tap on the thing itself, and never cost the Tutor's price.

**Build**
- The ✦ menu component (`<x-ai-menu>`): on a card (Improve · Shorter · Fix the wording · Two more like this), a question (Clarify · Split into two · Answer from my notes [Reader] · Ask the tutor [session, mode free]), a note's selection (Explain · Shorten · Fix), a file (Summarise · Make a note from it · Make cards from it · Find topics [all Reader]), a folder (Where should these go? [Helper]). Results show as a diff or a proposal with Keep / Discard, never applied silently.
- Reader jobs: `NoteFromFile` (→ `Notes::create` + `Notes::append`, kind `tutor`), `CardsFrom` (file, note or topic → items through `WriteBack::reviewItems`; the review sheet shows them ticked, as the capture does; saves through `apply`), `AnswerFromNotes` (question + the module's notes → a proposed answer, kept on the question with "from your notes").
- **Ask** (the global Helper): a sheet from the phone tab bar and a top-bar button on desktop; a short chat with the Helper and its read-only tools; not stored beyond the page; one line at the bottom: *Need teaching? Start a session.*
- The copy-paste card maker (`CardMaker`, the flashcards prompt) is replaced by *Make cards from…* on the deck; the prompt file stays for the copy-paste setting's users (reachable from the ⋯ menu when that setting is on).

**Tests:** `Engine/HelperTest` (read-only toolbox, budget, cost under Helper), `Engine/CardsFromJobTest`, `Engine/NoteFromFileJobTest`, `Engine/AnswerFromNotesJobTest`, `Web/AiMenuScreenTest` (each ✦ path with a fake reply; Keep / Discard), `Web/AskScreenTest`; browser spec `helper.spec.js` (phone Ask sheet, a card's ✦, axe).

**Abel checks:** On a card, tap ✦ → Improve → see the before/after → Keep. On a file, ✦ → Make cards → tick → Add. On the phone, Ask → "what did I find hard in Module 3?" → an answer in a second.

**Out of scope:** voice input (a separate decision); the Helper writing anything on its own.

### Phase 6 · Navigation and devices (2 units)

**Goal:** six doors per course, five tabs on the phone, and every screen right on every device.

**Build**
- The sidebar (3.4) and the phone tab bar (with Ask); the "Everywhere" group (Courses · Calendar · Settings); Settings as one page with four parts (AI engine, Appearance, Security, Your data = the journal pages and exports); the Journal leaves the sidebar.
- Routes: `/courses/...` as the canonical path with `/workspaces/...` redirecting (301), `Workspaces::SECTIONS` updated; the course calendar as the Coming up panel plus the global page.
- Assignments: `workspaces.project_tools`; labels, team, health, priority, milestones shown only when on; the assignment page's header trimmed to title, due, status, plan.
- The responsive pass (3.10): sheets under 640 px for every modal; sticky primary actions; tables to lists; the 320 px / 200 % check on every rebuilt page; tablet layout for the session (rail collapsible) and the module page (tabs scroll).
- Previews regenerated for all rebuilt screens (phone, desktop, light, dark).

**Tests:** `Web/NavigationTest` (six sections, redirects, settings parts), `Web/AssignmentsScreenTest` (project tools off/on); browser specs `navigation.spec.js`, `devices.spec.js` updated for every rebuilt screen (320 px, 200 % text, phone sheets, tablet).

**Abel checks:** On the phone: Home · Modules · Cards · Ask · More; a module page's Study ▾ opens a sheet; the session composer stays above the keyboard. On the desktop: the sidebar has six entries; old links still open.

**Out of scope:** new features.

### Phase 7 · Retire and tidy (1 unit)

**Goal:** one way to do each thing; the docs match the product.

**Build**
- Remove: the Overview panels replaced in Phase 1, the six session tiles' code paths, the flat Progress, "Evidence:" strings, the standalone copy-paste card maker screen (the setting keeps its sheet), unused CSS. Keep the data and the services.
- Docs: `docs/specs/study-memory.md` and `workspaces.md` marked "superseded by vistud-2-blueprint.md" where they are; `schema.md` complete; DESIGN.md §5 with the page template and the ✦ menu; README's table and PROJECT's reading order point here; `docs/handoff/v2-phase-7.md` closes the series.
- A `ThemeEnforcementTest`-style guard for the writing rules: a test that fails when a student-facing Blade view contains the internal words of 3.2.

**Tests:** the full suite; the full browser run; the words guard.

**Abel checks:** nothing new to see; everything from Phases 1–6 still works.

### Order, parallel work and what Abel sees when

```
Phase 0 ──▶ Phase 1 ──▶ Phase 2 ──▶ Phase 3 ──▶ Phase 4 ──▶ Phase 7
                                       │
                                       ├──▶ Phase 5 (Helper)   ┐ either order,
                                       └──▶ Phase 6 (Devices)  ┘ after 3
```

After Phase 1 Abel can set up a course and sees "Next". After Phase 2 the module flow (files → topics → study) works end to end. After Phase 3 the session is calm. After Phase 4 progress adds up. Phases 5 and 6 are polish with value; 7 is housekeeping. Total: about 20 work units.

---

## Part 5 · For the implementers

You are one model, taking one phase. Before you write code:

1. **Read, in this order:** this document (Parts 3 and 4, your phase and the sections it points to), `docs/architecture/conventions.md`, `docs/architecture/modules.md`, `docs/architecture/schema.md`, DESIGN.md §3 and §5, and the two specs that describe what exists (`docs/specs/workspaces.md`, `docs/specs/study-memory.md`). Then the code your phase touches, with its tests.
2. **Branch and commits:** work on `claude/persistent-study-context-zsilo6`; one commit per feature with a plain subject line and a body that says what the student gets; the attribution lines the session gives you at the end of the message. Never force-push. Never commit `.env`, `vendor`, `node_modules`, `.tools/`, `public/build/`, `test-results/`.
3. **The database:** migrations are additive and backfill; `php artisan migrate --database=mysql_owner --force` on the dev database; never `migrate:fresh`; never delete the dev data. Tests use `vistud_test`.
4. **Learner isolation is not optional:** every new table has `learner_id`; every query goes through `LearnerTables`; every Livewire id is `#[Locked]`; every action calls a service that authorises again (`Guard::learner`).
5. **Colours come only from theme tokens.** No hex, no `color-mix`, no Tailwind palette. `ThemeEnforcementTest` and `StylePerformanceTest` fail otherwise.
6. **Texts follow 3.11.** If you are writing a sentence of help on a screen, stop and make the screen clearer instead.
7. **Tests are part of the feature:** PHP feature tests for services and screens (the fake engine for anything AI), a browser spec for each rebuilt screen on desktop and phone with the axe check, and the full suite green before the commit (`php artisan test --compact`; `PLAYWRIGHT_CHROMIUM_PATH=/opt/pw-browsers/chromium npx playwright test tests/Browser/<spec>` in the cloud sandbox).
8. **Prompts:** change `resources/prompts/*.md` with a test that pins what matters (sections present, budget, tool rules). Run `php artisan prompts:try` (Phase 0 adds it) and read the replies once.
9. **Keys:** you never see or write a key. The owner pastes keys on the settings pages. Tell the owner never to paste a key or a token into a chat with any model.
10. **The handoff note:** `docs/handoff/v2-phase-N.md` with: what was built (screens, services, tables, tools), what Abel tests (plain steps, expected results), what's left or deferred, and the Gemini message (fetch, ff-only merge, `composer migrate`, `npm run build`, the targeted tests).
11. **When unsure, ask the owner in one short question with a recommendation.** Don't widen the phase.

---

## Part 6 · Decisions for Abel

| # | Decision | Recommendation |
|---|---|---|
| 1 | Call a workspace a **Course** everywhere a student reads it | Yes. The code keeps `workspaces`. |
| 2 | **Let the tutor mark topics** by default | On, with undo and "set by the tutor" shown. Mastered stays earned. |
| 3 | **Read my files automatically** on upload | On. Bound to 30,000 characters per file and once per file version; the cost shows in Usage. Off for files that are only pictures. |
| 4 | Keep the **copy-paste** path (briefing, paste-and-review, the flashcards prompt) | Keep, hidden behind the toggle, off by default. Students without a key still need it. |
| 5 | **Assignments' project tools** (labels, team, health, priority) | Keep the code; off by default per course. |
| 6 | **Calendar** in the course sidebar, or global plus a Home panel | Global plus the Home panel. |
| 7 | **Default models per role** | Abel picks in admin Engine setup. Classes: Tutor = the best affordable; Reader = cheap and long-context; Helper = the cheapest fast one. Each can be changed any time without losing anything. |
| 8 | **Voice input** | Separate decision (the Groq/Whisper note of 2026-10-06); not in these phases. |

---

## Part 7 · Risks and how the plan handles them

| Risk | Handling |
|---|---|
| A cheap or "lite" Tutor ignores the tool rules | The platform never depends on the model for state; the rules are short and tested; the student can do every AI action by hand; the settings page says which role each model plays so a weak Tutor is an easy swap. |
| Auto-reading files costs more than expected | Hard bounds (characters per file, once per version), the Reader is the cheap role, the month cap includes jobs, and the toggle turns it off. |
| No queue worker on a Windows laptop | Jobs run inline after the response when no worker heartbeat is seen; the screen polls; nothing is lost. |
| Existing data and the dev database | Additive migrations with backfills; old URLs redirect; the course profile and learner profile are created lazily; nothing is deleted until Phase 7, and then only code. |
| Scope creep | Every phase has *Out of scope*; a new idea goes into a later phase or Part 6, not into the current one. |
| Big courses (hundreds of topics) | Rollups are one query; module lists paginate at 50; the Progress tree is collapsed by default; the Stack's budgets cut, never grow. |
| Two models editing the same files | One phase at a time on the branch; Gemini only merges fast-forward; the handoff note is the baton. |

---

## Appendix A · The Tutor's prompt stack, one real example (abridged)

```
[0] # You are the student's tutor …  (tutor-2.md, ≤ 2,500 tokens, byte-identical every call)
[1] tools: 22 definitions (byte-identical every call)
[2] ## The course: Operating Systems
    About: second-year module on processes, memory, file systems and concurrency.
    Assessment: Midterm 30 % (12 Oct) · Coursework 30 % (ER diagram, 9 Oct) · Exam 40 % (Dec)
    Outcomes: explain scheduling policies · … (6 lines)
    Student's instructions for this course: "Use C examples; the lecturer does."
[3] ## The student
    Likes: examples first, diagrams · Pace: small steps · Check: often · Goal: top marks
    About you: "Second year. I get lost when there are many new words at once."
    Language: English · Topics: keep them yourself · Marking topics: you may set covered/understood/confusing
[4] ## Module 3 · Process management (28 Sep – 11 Oct)   … (the block in 3.6.2)
[5] ## This session
    Mode: whole module · Topic now: CPU scheduling · Clock: Pomodoro 25/5, 00:35 studied
    Saved so far: 3 cards, 1 key point · Material attached: Lecture 3.pdf (read with module_files/read_file)
    Checkpoint: slide 7 of 18, before priority scheduling
[6] (the conversation, folded beyond the threshold)
```

## Appendix B · Tools added in ViStud 2

| Tool | Role | Input | Effect |
|---|---|---|---|
| `module_files` | Tutor, Helper | module? | The module's files with their digests (summary, pages, topics). |
| `set_topic_status` | Tutor | topic, status (covered/understood/confusing), reason | Applies or proposes per the trust toggle; records `status_by`. |
| `record_quiz` | Tutor | kind, topic or module, questions[{asked, answer, result, right, fix}], score | A `quizzes` row; proposes statuses after a test. |

Everything else is a job (Reader) or `Helper::quick`, not a tool.

## Appendix C · Status of this plan

| Phase | State | Note |
|---|---|---|
| 0 Groundwork | done (2026-10-06) | Roles, jobs, the context Stack, the helper, the settings page, `<x-page>`, the word Course. Handoff: [docs/handoff/v2-phase-0.md](../handoff/v2-phase-0.md). |
| 1 Course setup and home | done (2026-10-07) | Course and learner profiles, the reader's `profile_course` job, the setup sheet, `NextStep`, the course home. Handoff: [docs/handoff/v2-phase-1.md](../handoff/v2-phase-1.md). |
| 2 Module page | done (2026-10-07) | File digests, the reader's `read_file` job, topic suggestions (**Add all** / Pick / Not these), the module brief (layer 4) and the `module_files` look-up, the module page with its five tabs, **Study this ▾**, the topic sheet, file chips (Read / Reading… / Read now), the file page's **Read by the AI**, search across notes and files. Added beyond the §3.7 table: `topic_suggestions.name_key` (a module is suggested a topic once), `course_profiles.proposed_modules` and `syllabus_text` (Phase 1). Handoff: [docs/handoff/v2-phase-2.md](../handoff/v2-phase-2.md). |
| 3 Session | done (2026-10-07) | Modes, `topics.status_by`, the `set_topic_status` and `record_quiz` tools, `quizzes`/`Quizzes`, the session as a conversation (header with clock and End, rail of topics, material and what was saved, chips, + menu, phone sheet), the one-screen end. `record_quiz` has no `score` input: the score is worked out from the results. Handoff: [docs/handoff/v2-phase-3.md](../handoff/v2-phase-3.md). |
| 4 Progress | done (2026-10-07) | `Rollups` (topic → module → course, one pass per page; `Topics::list` and `Questions::list` take the journal snapshot so it is read once), `CourseRoll`/`ModuleRoll`/`TopicRoll`, the Progress tree (ring, filters All / Needs attention / Not started / Mastered, the current module open and the rest folded, the topic sheet shared with the module page), the course home and the Modules page on the same numbers, `NextStep` fed with the attention list and the topics found, "set by the tutor, 5 Oct" and key points (add, remove) and removing a topic on the topic sheet. Needs attention: confusing for 7+ days, a stuck question, the latest test under 50 % (cleared by the student's own word after it), cards 7+ days overdue (`Flashcards::counts` now says `overdue`). A module's *tested %* is its latest quiz or test. Handoff: [docs/handoff/v2-phase-4.md](../handoff/v2-phase-4.md). |
| 5 Helper | done (2026-10-07) | The ✦ menu (`<x-ai-menu>`, one sheet `AiAssist`, `Assist`) on cards, questions, files, folders, notes, topics and a note's selection, results as proposals with Keep / Discard; Reader jobs `NoteFromFile`, `CardsFrom`, `AnswerFromNotes` (prompts under 600 tokens); **Ask** (`Ask`, `Helper::quick` now takes a course) in the top bar; *Make cards from…* replaces the copy-paste card maker on the deck (the old dialog stays for the copy-paste setting). Not through `WriteBack`: file/note cards have no session, so the sheet reviews and adds them itself. The browser tests are the `helper:` ones in `chat.spec.js` (it owns the fake service). Handoff: [docs/handoff/v2-phase-5.md](../handoff/v2-phase-5.md). |
| 6 Navigation and devices | done (2026-10-08) | Six doors per course (Home · Modules · Cards · Questions · Assignments · Progress) and an *Everywhere* group (Courses · Calendar · Settings); the phone's tab bar Home · Modules · Cards · Ask · More (a sheet of the rest); Settings as one page with four parts, the Journal under *Your data*; course pages at `/courses/…` with 301s from `/workspaces/…` (query kept); `workspaces.project_tools` (off for a new course, on by the migration where any project tool was in use) hiding labels, team, priority, person, milestones and health; every `<dialog class="modal">` a bottom sheet under 640 px; the 320 px / 200 % check on every rebuilt page (`devices-v2.spec.js`). Handoff: [docs/handoff/v2-phase-6.md](../handoff/v2-phase-6.md). |
| 7 Retire and tidy | done (2026-10-08) | The words guard (`tests/Architecture/StudentWordsTest.php`: no student view or notice says workspace, evidence, write-back, briefing, journal, engine, finding or overview; the journal is the *study record*, the briefing the *prompt*, Settings' first part *AI*); unused CSS removed (the old Progress status buttons, findings list, session tiles, `.place-badge`), the design mockups, their route and the layout slots they used, and the previews of screens that no longer exist; `study-memory.md` and `workspaces.md` marked superseded in part; `schema.md` checked against every migration; DESIGN.md §5.9 (the ✦ menu), §5.10 (words), the doors and the bottom sheets; every browser spec and the previews brought up to date. Handoff: [docs/handoff/v2-phase-7.md](../handoff/v2-phase-7.md), the end of the series. |
| 8 The course guide (asked for by the owner on 2026-10-04, after trying Phase 7 on a real course; reshaped on 2026-10-04) | done (2026-10-04, two talks 2026-10-04) | **A New course page** (`/courses/new`, `CourseNew`) replaces the dialog: name, colour and icon with a preview, optional details, and the choice *Guide me* / *I'll do it myself*. **The guide** (`/courses/{course}/guide`, `GuideChat`, `App\Engine\CourseGuide`) has two talks with the tutor's model, each asking one thing at a time and proposing with ticks, writing only what is ticked, never twice. **Setting the course up** (`resources/prompts/setup-guide.md`) is the course only: details, about text, outcomes, assessment (with deadlines, optionally as assignments) and textbook; it never proposes modules. **Adding modules** (`?for=modules`, `resources/prompts/module-guide.md`), from Modules, has read the course (about, outcomes, assessment, the modules already there) and proposes only modules, from a timetable pasted, weeks told, or suggestions when asked, a few at a time. Each talk's proposal is cleaned to its own job in code, not only in the prompt. Each turn is a run under the tutor (`engine_jobs.role = tutor`, kind `setup_guide` or `module_guide`, counted in *Usage this month*); `prompts:try --role=guide` checks both prompts live. Handoff: [docs/handoff/v2-phase-8.md](../handoff/v2-phase-8.md). |

Implementers update this table in the commit that closes a phase.
