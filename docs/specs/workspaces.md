# Workspaces: the plan for milestone M2

**Status:** approved by the owner (decisions W0–W6 as recommended). Built so
far: **step 0** (renames and workspace colours), **step 1** (workspaces:
create, open, switch, edit, archive and restore) and **step 2** (modules, and
folders inside them up to 8 levels: add, rename, reorder by dragging or from a
menu, move, delete when empty) and **step 3a** (notes: the editor with
autosave, drafts kept in the browser, conflicts, trash and restore; folders
and notes at a workspace's top level in Notes & files). Next: step 3b (warning
other open tabs, the logout choice for unsaved drafts, deletion records
reaching browsers, keeping editors alive across pages). Personal, dragging
folders and notes, and version history come later in M2.

**Mockups:** [docs/design/mockups/](../design/mockups/). They use the real
theme, top bar and components, so what is approved is what gets built. On a
development machine they can be opened at `/_mockups/workspaces` (they never
exist on a live server).

---

## 1. The idea

A student organises everything by **workspace**: one per subject, such as
Biology, Mathematics or Spanish. Opening a workspace turns the whole screen
into that subject, with its own sections:

| Section | What it holds |
|---|---|
| **Overview** | What's coming up, what's worth revisiting, where you left off, how far through the modules you are |
| **Modules** | The subject's units in order (Week 1, Week 2…), each with its notes, files and folders |
| **Notes & files** | Everything in the workspace in one place: notes written in ViStud, and uploaded files (PDF slides, photos) |
| **Calendar** | Lectures, labs, quizzes, exams and deadlines for this subject |
| **Progress** | What ViStud has noticed: topics that are mastered, being learned or shaky, and the journal in plain sentences |

Outside any workspace there is **All workspaces** (the home page),
**Personal** (notes that belong to no subject) and one **Calendar** across
every subject.

The owner chose this design ("B: workspace spaces") over one big file tree.

## 2. Words

| Word | Meaning |
|---|---|
| **Workspace** | A student's space for one subject. Private to that student |
| **Area** | The student area or the admin area. The screens already say "Student area" and "Admin area"; the code still calls these "workspaces" and is renamed (decision W1) |
| **Module** | An ordered unit of a workspace, with optional dates |
| **Folder** | Organisation only: inside a workspace, a module, another folder (up to 8 levels) or Personal |
| **Note** | A document written in ViStud's editor |
| **File** | An uploaded document: PDF, image, and later other types |
| **Activity** | A dated event: lecture, lab, quiz, exam, deadline |
| **Journal** | The private, append-only record of the student's learning. There is one per student; each workspace's Progress shows its part |

## 3. The screens (see the mockups)

1. **My workspaces** (`workspace-home-*`): a card per workspace with its
   colour, what's next and what it holds, plus **New workspace**. The sidebar
   lists every workspace for quick access.
2. **Overview** (`workspace-overview-*`): the workspace's switcher at the top
   of the sidebar, its five sections below it.
3. **Modules** (`workspace-modules-*`): each module opens to show its notes,
   files and folders, with **Note**, **Folder** and **Upload file** buttons.
4. **A note** (`workspace-note-*`): the editor, where you are (Biology ›
   Week 2), the save status, and on wide screens the topics and versions.
5. **Progress** (`workspace-progress-*`): topics with their state and the
   reason in plain words, then the journal as sentences ("You answered
   practice quiz question 3 correctly").

**On phones** a workspace's sections sit in a bottom tab bar (decision W4);
the slide-in menu keeps everything else (switching workspace, Personal, the
account).

## 4. What the first version includes, and what waits

| In M2 (this plan) | Later |
|---|---|
| Create, rename, recolour, reorder and archive workspaces | **Workspace types** with their own tools: maths (formulas, graphs), biology (diagrams, labelling), languages (vocabulary, pronunciation), physics (units, simulations). Each is a plugin that adds sections to its workspaces |
| Modules and folders: create, rename, reorder (drag and keyboard), move, nest | **External resources**: linking and importing from outside sources (videos, articles, open courses) |
| Notes with the first editor: headings, bold, italic, lists, code, quotes; autosave that never loses work (ADR 0003 §5) | Search across notes and files (M3) |
| Uploading files into modules and folders, preview and download (needs WP5's file storage; decision W3) | Flashcards (M5) |
| Activities and the calendar, per workspace and across all | ChatGPT and Claude connections (M6), which fill Progress automatically |
| Overview and Progress with whatever the journal holds | |
| Trash and restore for 30 days | |
| No page reloads when moving around (ADR 0003 §4) | |

**Leaving room for workspace types now:** every workspace gets a `type`
(`general` for all of them in M2) and its sections come from a list, so a
later type can add its own section without changing the others.

**An honest note on Progress:** in M2 nothing writes learning evidence yet
(that comes from flashcards in M5 and the AI connections in M6), so Progress
starts mostly empty. M2 builds the section so it fills as the evidence
arrives; the mockup shows how it will look then.

## 5. How it connects to learning

- Each workspace and module is also recorded in the journal (as a record), so
  learning evidence knows which subject it belongs to and each Progress
  section can show its own part.
- A saved note becomes a **source** that evidence can cite, down to the
  version (ADR 0003 §9.1–9.3). Moving a note between folders changes only its
  place; moving it to another workspace also records the move.
- Everything a workspace holds is private to its student, through the same
  learner isolation as the journal: another student's workspace, note or file
  looks exactly like one that doesn't exist (404).

## 6. Build order

Each step ends with something the owner can try, tests, previews and a push.

| Step | What | Who |
|---|---|---|
| 0 | Renames (W1, W2): "workspace" → "area" for the student/admin split in the code; the journal's `course` record → `workspace`. The workspace colour palette in the themes (W5) | Architect |
| 1 | **Workspaces**: My workspaces, create/rename/colour/archive, the switcher and the workspace sidebar, empty sections | Architect |
| 2 | **Modules and folders**: create, rename, reorder, move, nest | Architect, with Grok on the drag-and-drop polish |
| 3 | **Notes**: the editor and draft-safe autosave, trash and restore | Architect |
| 4 | **Files**: upload, preview, download, trash, once WP5's file storage exists | Architect (security), Grok (the screens) |
| 5 | **Calendar**: activities per workspace and across all, "Coming up" on Overview | Grok, reviewed by the architect |
| 6 | **Overview and Progress** | Architect |
| 7 | **No reloads and the phone layout** (tab bar, drill-down), then ADR 0003 §14's checks, adjusted to this plan | Architect and Grok |

**Meanwhile, finishing M1** (decision W0): the independent acceptance tests
V1 (journal replay) and V2 (security T1–T10) go to Grok or Gemini, and WP5
(redaction, background jobs, file storage) to the architect before step 4.

## 7. Decisions for the owner

| # | Question | Recommendation |
|---|---|---|
| W0 | Start M2 now, while M1's acceptance tests (V1, V2) and WP5 are finished in parallel? ADR 0003 says M2 starts after M1's gate | **Yes.** M2 can't be accepted until M1's gate passes, but building it doesn't have to wait |
| W1 | Rename the student/admin "workspace" to "area" in the code and docs | **Yes, now**, so "workspace" has one meaning. The screens already say "area" |
| W2 | Rename the journal's `course` record to `workspace` | **Yes, now.** No stored data or test depends on it yet; later it would need a migration |
| W3 | File uploads in M2 (after WP5's file storage) or later | **In M2**, as step 4 |
| W4 | On phones, a bottom tab bar for a workspace's sections | **Yes**, as in the mockups. It revisits the earlier "slide-in menu only" decision now that there are sections to switch between |
| W5 | A colour palette for workspaces in every theme (8 colours, contrast-checked), the student picks one per workspace | **Yes.** The mockups borrow the status colours until then |
| W6 | What a new workspace asks for | **Name** (required); code, term and dates, colour and icon optional |

## 8. For developers

**Tables** (all learner-scoped through `LearnerTables`, with isolation tests):

| Table | Main columns |
|---|---|
| `workspaces` | `id` (UUIDv7), `learner_id`, `name`, `code`, `term`, `starts_on`, `ends_on`, `colour`, `icon`, `type` (`general`), `position`, `archived_at` |
| `modules` | `id`, `learner_id`, `workspace_id`, `title`, `position`, `starts_on`, `ends_on` |
| `folders` | `id`, `learner_id`, parent (workspace, module, folder or none for Personal), `name`, `position`, depth ≤ 8 |
| `notes`, `note_versions`, `note_blocks`, `content_tombstones` | As ADR 0003 §9.1, with the container being a workspace, module, folder or Personal |
| `files` | `id`, `learner_id`, container, `name`, `mime`, `size`, `sha256`, storage key; storage through WP5 |
| `activities` | `id`, `learner_id`, `workspace_id`, optional `module_id`, `kind`, `title`, `starts_at`, `ends_at`, `all_day` |

**URLs:** `/workspaces` (My workspaces), `/workspaces/{id}` (Overview),
`/workspaces/{id}/modules`, `/workspaces/{id}/notes/{note}`,
`/workspaces/{id}/files/{file}`, `/workspaces/{id}/calendar`,
`/workspaces/{id}/progress`, `/personal`, `/calendar`. After step 1, `/`
opens My workspaces.

**Every action is one service call**, checked by the service (ADR 0003 §8);
Livewire components hold `#[Locked]` IDs; the theme rules apply to every
screen and state.
