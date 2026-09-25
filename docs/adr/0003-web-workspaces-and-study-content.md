# ADR 0003: Web workspaces, front-end stack and study content

- **Status:** PROVISIONAL. The direction has been provisionally approved by the PM. This ADR becomes ACCEPTED only when the workspace prototype passes every criterion in §14. Decisions D1–D9 are recorded in §16; none is open.
- **Date:** 2026-09-25
- **Revised:** 2026-09-25. Recorded the PM's decisions D1–D8 and four corrections:
  - drafts are separated per account, and retries are made safe;
  - deletion records prevent deleted notes from coming back;
  - flashcards keep one identity while their content is revised;
  - an explicit policy for undo history.

  Also: basic custom themes moved into the prototype; retrieval timing reconciled with ADR 0001; milestones and the acceptance checklist revised.
- **Decision owner:** the project owner. The PM reviews and coordinates; the developer owns implementation.
- **Depends on:** [ADR 0001](0001-permanent-store-and-retrieval-index.md) and [ADR 0002](0002-learning-event-schema.md). Both now carry cross-references back to this ADR.

## 1. Context

ViStud is a full study platform, comparable in ambition to RemNote. Students work inside it through:
- notes and folders;
- courses and modules;
- a dashboard and a calendar;
- flashcards with spaced repetition;
- plugins, eventually.

MCP is one way for external AI assistants to connect. It is not the product.

**Experience requirements (unchanged):**
- no full-page reloads during normal in-app navigation or actions;
- the user's working state is preserved;
- browser back and forward work, and every screen and note has a direct link;
- local interactions respond immediately;
- saving, loading, error and retry states are clear and never block the whole workspace;
- the layout is modern and uncluttered, designed for mobile, tablet and desktop;
- colours come only from semantic tokens.

There are two workspaces, **Student** and **Admin**. They run in one application with one account system, and share components and theme tokens. Each has its own navigation, and permissions are enforced on the server. Being an admin never grants access to private study content.

The PM chose **Blade and Livewire**. This ADR records that choice and everything around it. The prototype (§14) is what proves the experience.

## 2. Decision summary

| Area | Decision |
|---|---|
| Application | Laravel 13, as one application. Student and Admin are workspaces within it, not separate apps or deployments |
| Web UI | Livewire 4 with Blade for screens. Alpine (bundled with Livewire) handles everything that stays in the browser |
| Navigation | `wire:navigate` for moving around, and `@persist` for the shell. Every screen has a real URL |
| Note editor | Tiptap 3 (MIT, built on ProseMirror), plus ViStud's own block and outline schema. It runs in the browser, isolated from Livewire |
| Saving notes | Through the versioned JSON API, with optimistic concurrency. Drafts are kept per account, and deletion records are checked before any draft is replayed |
| Offline (**D1 = A**) | **Draft-safe web.** Notes already open stay editable while disconnected, and their drafts are durable. The app shell is not cached, so the app can't be opened while offline (§7). Flutter's offline scope is a later decision |
| Styling | Tailwind CSS 4 with its built-in palette removed. Only semantic tokens exist, and a three-layer check enforces this |
| Themes (**D7 = A**) | A **basic custom theme** (seed colours) ships in the prototype and the first usable study workspace. **Advanced** editing of every individual token comes later |
| Calendar | FullCalendar 7 (MIT), using its structural stylesheet only, mapped to our tokens |
| Charts | ECharts 6 (Apache-2.0), using the SVG renderer with a theme built from our tokens |
| Browser tests | Playwright Test |
| Web authentication | Laravel sessions through Fortify, with mandatory two-factor authentication for admins (**D5 = A**) |
| Accounts (**D4 = A**) | Invite-only. The pilot starts with the owner's account plus synthetic test accounts. ADR 0001's gates (encryption, isolation and erasure) still apply before another real learner joins |
| Admin denial (**D6 = A**) | A student who opens an admin route gets 403. Another learner's private records return 404, the same as a record that doesn't exist |
| External clients (Flutter, MCP, plugins) | Passport, in milestone M6. Sanctum isn't used |
| Live updates | Reverb, in M6 |
| Business rules | One application service layer, with thin adapters for Livewire, REST and MCP |
| Paid components | None required |

## 3. Local interactions vs server interactions

Most interactions that should feel instant need no server at all.

| Kind | Interactions | Mechanism | Server? |
|---|---|---|---|
| **Local** | Menus, popovers and dialogs · expanding and collapsing folders that are already loaded · resizing and collapsing panels · switching theme · editor typing, formatting, selection and undo · the slash menu and shortcuts · switching between notes that are already open · filtering lists that are already loaded · previewing a drag reorder | Alpine and the editor | **None.** Preferences are saved in the background afterwards |
| **Server read** | Opening a note that isn't in memory · the first expansion of a folder that isn't loaded · search · topic suggestions · dashboard data · calendar ranges · the flashcard queue | Livewire or API requests, with a skeleton only in the area being loaded | Yes. The rest of the workspace stays usable |
| **Server write** | Saving notes · creating, renaming, moving or deleting items · calendar changes · review answers · settings | Notes use a background autosave queue (§5.3). Other items update optimistically and roll back with a message on failure. IDs are UUIDv7s created by the client, so creating something is instant | Yes, but it never blocks the workspace unless the user truly needs the result |

**Feedback rules:**
- **No blocking spinner.** Navigation shows a thin progress bar. A skeleton appears in the main area after 150 ms. Loading indicators sit on the element that is loading.
- **Save status is always visible.** The note save indicator is defined in §5.3.
- **Failed requests.** Anything slower than 15 s counts as failed. Every write carries an idempotency key.
- **Offline.** An offline banner uses `wire:offline` plus the browser's online and offline events.
- **Unsubmitted input.** Leaving a form with unsubmitted input asks for confirmation, using `wire:dirty` and a navigation guard.

## 4. Workspace shell, navigation and preserved state

**Shell and URLs**
- The persistent parts are wrapped in `@persist`: the sidebar and its tree state, the top bar, and the **editor host** (§5.2).
- The main workspace is swapped by `wire:navigate`. Livewire 4.4 provides `.hover` prefetch and `.preserve-scroll`.
- The theme lives on `<html>`, which navigation never replaces.
- URLs:

  | Screen | URL |
  |---|---|
  | Dashboard | `/` |
  | Note | `/notes/{id}`, optionally with `#block` |
  | Course | `/courses/{id}` |
  | Module | `/courses/{id}/modules/{id}` |
  | Folder | `/folders/{id}` |
  | Calendar | `/calendar?view=…&date=…` |
  | Flashcard review | `/review` |
  | Settings | `/settings/...` |
  | Admin | `/admin/...` |

- Back and forward restore the previous screen. A direct link loads the full shell with that screen open.
- An unknown ID, or another learner's private ID, shows the same "not found" state inside the shell (§10.4).

**Where state is kept.** This is the promise the tests check (§14):

| State | Where | Survives navigation | Survives reload | Survives closing the browser |
|---|---|---|---|---|
| Open editors: document, selection, **undo history** | Memory, in the editor host. Up to **5** most recently used notes per tab | Yes, for those 5 notes. Opening a 6th note removes the oldest editor (policy below) | Document and selection only | Document and selection only, once the app is opened online again |
| Unsaved note drafts | IndexedDB, in a separate store for each account (§5.3) | Yes | Yes | Yes. The app shell isn't cached, so drafts reappear when the app is next opened online (§7) |
| Selected item | The URL | Yes | Yes | Yes (bookmark) |
| Sidebar width, expanded tree nodes, right panel state | localStorage, per account and device | Yes | Yes | Yes |
| Scroll position | History state and sessionStorage | Yes, on back and forward | Yes | No |
| Theme | Account preference, cached per account in localStorage | Yes | Yes | Yes |
| Unsubmitted small forms | Alpine state | Within that screen | No | No |

**Undo history policy**
- Undo history belongs to an open editor. It lasts while that editor stays in memory in the tab.
- Visiting other screens (dashboard, calendar, settings, admin) **never** removes an editor.
- Opening a **6th** note removes the editor for the least recently used note. That note's document and selection are kept, through its draft or the server, and it reopens instantly with its content and selection intact. Its **undo history starts empty**.
- Undo history never survives a reload or closing the tab.
- The sixth-note case is tested explicitly (§14, criterion 4).

## 5. The note editor

### 5.1 First editing features

| Feature | How it works |
|---|---|
| **Blocks** | The document is a list of `block` nodes, each with a stable ID (Tiptap's Unique ID extension, MIT). Block types: paragraph, heading 1–3, bulleted, numbered, to-do, quote/callout, code (syntax highlighting coloured by tokens), divider, image, file |
| **Nesting** | Our own schema: each block holds its content plus an optional group of `children`. Tab indents and Shift+Tab outdents. Blocks can be collapsed, and collapse state is stored per device, not as a new version. Blocks move with a drag handle (MIT) or Alt+↑/↓ |
| **Topic links** | Typing `[[` opens suggestions for the learner's topics, with a "Create topic …" option. Choosing one inserts a `topicLink` chip. Backlinks are worked out on the server when the note is saved (§9.3) |
| **Keyboard** | Enter: new block · Shift+Enter: line break · Tab / Shift+Tab: indent / outdent · Ctrl/Cmd+B, I, U, E: bold, italic, underline, code · Ctrl/Cmd+Z / Shift+Z: undo / redo · Alt+↑/↓: move block · Ctrl/Cmd+.: collapse · `/`: block menu · Ctrl/Cmd+K: command palette (applies across the whole app). All shortcuts live in one registry with a help sheet, and none overrides browser essentials |
| **Paste** | Pasted HTML is parsed through our schema: unknown formatting is dropped, while headings, lists and code are kept. Plain text becomes paragraphs. Markdown is converted using `@tiptap/markdown` (MIT), which the prototype evaluates. Links accept only `http`, `https` and `mailto`. Pasted images become attachments |
| **Attachments** | Files are dropped, pasted or picked (File Handler extension, MIT). They are uploaded as private, per-learner canonical files (ADR 0001). A placeholder stays until the file record exists, and the document stores only the file's ID. Files are served through the file service, which respects redaction blocks. Type and size limits apply |
| **Flashcards** | `front >> back` makes a basic card, and `front <> back` makes a two-way card. The card is stored in block attributes and extracted when the note is saved (§9.4). A margin marker shows which blocks are cards |

**Later:** tables, maths, cloze deletions, embedded PDFs and block references.

### 5.2 Isolation from Livewire, and cleanup

- **The editor host.** This is an Alpine component inside a `@persist` element, placed outside every Livewire component's DOM, with `wire:ignore` as a safeguard. Livewire never re-renders it.
- **Communication.** The editor and Livewire screens talk only through browser events, such as `note:open`, `note:saved` and `note:conflict`.
- **Up to 5 editors.** The host keeps up to 5 editor instances per tab, ordered by most recent use.
- **Removing an editor** (on a 6th note, logout, or account change):
  1. flush its changes into the draft store and the save queue;
  2. call `editor.destroy()`;
  3. remove its listeners;
  4. abort any reads it has in flight.

  Saves are never aborted. They finish, or are retried from the draft.
- **Leak test.** After 50 navigations there are at most 5 editors, and memory has not kept growing (§14).

### 5.3 Autosave, drafts, ordering and conflicts

**Drafts are kept per account.**
- **Where drafts live.** Each account has its own IndexedDB database, `vistud-drafts-{accountId}`. Each record is keyed by **note and tab**, so two tabs never overwrite each other's draft.
- **What a record holds:** `note_id`, `client_id` (the tab), `base_version`, `draft_rev` (increases with every local change), `doc`, `selection`, `updated_at`, `state`.
- **Messages between tabs.** Tabs signal each other on a `BroadcastChannel` named `vistud-{accountId}`. Tabs signed in to a different account never receive them.
- **Checking the account.** Every API response carries the current account ID in a header. If a tab sees an account it doesn't expect, it stops its save queue immediately and returns to the login screen.

**Saving:**

```
PUT /api/v1/notes/{id}   { base_version, doc, draft_rev, save_id, client_id }
```

- **Order.** Each note has one save queue, with only one save in flight at a time. A save starts 1.5 s after typing stops, or at least every 10 s during continuous typing. Edits made while a save is in flight go into the next save.
- **Draft first.** The draft is written to IndexedDB before every save.
- **Safe retries.** Reusing the same `save_id` returns the same result.
- **`PUT` only updates.** It never creates or recreates a note. New notes are created with `POST`, using an ID generated by the client.

**Server responses:**

| Response | What the client does |
|---|---|
| `200 {version: V}` | **It clears only the revision it saved.** If the stored `draft_rev` still matches the one sent, the draft is deleted. If the user typed more while the save was in flight, the draft is kept and its `base_version` becomes V, so the newer edits sit safely on top of what was saved |
| `409 {current_version}` | Conflict. The draft is kept and autosave for this note pauses. The conflict screen appears |
| `410 {reason}` | The note was trashed, deleted or redacted (§5.4) |
| `401` / `419` | The session has expired. The queue **pauses**, drafts stay, and the user is asked to log in. The queue resumes **only if the same account** logs back in |
| `401 account_deleted` | The account no longer exists. Every draft for that account on this device is purged |
| `403` | The account is suspended or access has been revoked. Retries **stop for good** for this account, and the draft shows "can't be saved: access removed" |
| Network error, 5xx or timeout | Retried at 2, 5, 15, 30 and 60 s, then every 60 s while online. Retries pause while offline and resume on reconnect |

**Logging out** stops the queue at once. If unsynced drafts exist, the user is asked to choose:
- *Sync now*;
- *Log out and keep drafts on this device*. They sync the next time the same account logs in. A warning explains that anyone using this device's browser profile could read them;
- *Log out and discard*.

**The save indicator is honest about local storage:**

| State | Label |
|---|---|
| Server has it | *Saved* |
| Pending, stored locally | *Saved on this device* |
| Saving | *Saving…* |
| Failed, retrying | *Not saved, retrying in N s* |
| Conflict | *Conflict, needs your choice* |
| **Local storage writes failing** (private mode, full disk, blocked or evicted storage) | *Not stored on this device, keep this tab open* |

- If a change exists only in memory, the browser warns before the tab is closed.
- The app asks the browser for persistent storage (`navigator.storage.persist()`). If the browser refuses, the settings page says that it may clear drafts when storage runs low.

**Conflicts: newer work is never silently overwritten**
- **Tabs in the same browser.** A tab broadcasts "note X is now version N". Another tab that has no unsaved edits reloads to version N. A tab that has unsaved edits shows a conflict warning before it tries to save.
- **Across devices,** conflicts are caught when saving (`409`).
- **Resolving a conflict.** Both versions stay on the server, and the user chooses one of:
  - *see both side by side*;
  - *keep mine*: a new version is saved on top of theirs;
  - *keep theirs*: my draft stays recoverable for 24 hours;
  - *save mine as a new note*.

  Version 1 doesn't merge automatically.
- **After a reload or restart:**
  - If a draft is based on the current version, it is restored with a notice.
  - If it is based on an older version, the conflict screen appears.
  - Several drafts of one note (from different tabs) are listed so the user can choose.

### 5.4 Deletion, trash and redaction reaching browsers

**Notifying connected tabs isn't enough.** The server keeps **durable deletion records** in a `content_tombstones` table, one for each note that is trashed, restored, deleted or redacted:

| Field | Meaning |
|---|---|
| `learner_id` | The learner |
| `entity_type`, `entity_id` | Which note |
| `kind` | trashed, restored, deleted or redacted |
| `at` | When |

**Before any draft is replayed,** on app load and on every reconnect, the client:

1. Fetches `GET /api/v1/sync/tombstones?since=<cursor>` for its account.
2. **Purges** the drafts of deleted or redacted notes without sending them, with a notice.
3. **Holds** the drafts of trashed notes. The user can choose "Restore the note and apply my changes" or "Discard my changes".
4. Only then replays the remaining drafts in order.

**The server rejects saves to deleted, redacted or trashed notes with `410`,** as a last line of defence. Because `PUT` never creates notes, offline edits can't bring deleted content back.

**Limits that must be stated honestly.** Drafts are plaintext in the browser's storage. A device that never reconnects keeps them until one of these happens:
- the app is opened on it again;
- the learner clears the browser's data;
- local expiry, 30 days after the last change. Expiry runs when the app next loads.

If an account's session has expired, a returning device can't prove who it is and can't fetch deletion records. If the account itself was erased, the server answers `401 account_deleted`, and the device purges every draft for that account. ADR 0001 now lists browser drafts in invariant I4, in gate G3, and in its table of what remains after erasure.

## 6. Themes and colour

### 6.1 Tokens

Actual colour values live **only** in theme definitions. Everything else uses semantic tokens:

| Group | Tokens |
|---|---|
| Surfaces | `--bg`, `--surface`, `--surface-raised`, `--surface-sunken`, `--overlay` |
| Text | `--text`, `--text-muted`, `--text-subtle`, `--text-disabled`, `--text-on-accent` |
| Borders | `--border`, `--border-strong`, `--divider` |
| Accent | `--accent`, `--accent-hover`, `--accent-active`, `--accent-subtle`, `--accent-contrast` |
| Interaction | `--hover`, `--pressed`, `--selected`, `--selection`, `--drag-target`, `--drop-indicator` |
| Focus | `--focus-ring`, `--focus-ring-offset` |
| Status | `--danger`, `--warning`, `--success`, `--info`, each with `-subtle` and `-on` variants |
| Categories | `--category-1…8`, each with `-subtle`. Used for course colours, calendar event types and chart series. A course's colour is chosen from these, **never typed as a hex value** |
| Editor | `--topic-link`, `--topic-link-bg`, `--code-bg`, `--syntax-keyword`, `--syntax-string`, `--syntax-number`, `--syntax-comment`, `--syntax-function`, `--card-marker`, `--block-hover` |
| Charts | `--chart-grid`, `--chart-axis`. Series colours come from the categories |
| Other | `--shadow-color`, `--role-admin` |

Tailwind 4 exposes the tokens through `@theme inline` and removes its own palette with `--color-*: initial`.

### 6.2 Everything the tokens must cover

| Area | How |
|---|---|
| Blade components | Token utilities |
| Editor | Our own CSS. Tiptap ships no styles of its own |
| Calendar | FullCalendar's `skeleton.css`, plus our own mapping to tokens. We never import its palette files |
| Charts | Our ECharts wrapper builds the chart theme from the computed tokens, sets every colour option, and rebuilds when a `vistud:theme-changed` event fires |
| Dialogs | Native `<dialog>`. Its backdrop uses `--overlay` |
| Interaction states | Hover, pressed, selected, disabled and dragging states all use their tokens |
| Text selection, scrollbars, shadows | Their tokens |
| Icons | `currentColor` |

### 6.3 Enforcement

Three checks run in CI, and all three must pass:

1. **Source scan.** Scans `resources/css`, `resources/js`, `resources/views` and PHP files that output styles.
   - **Forbidden:** hex colours, colour functions (`rgb`, `hsl`, `hwb`, `lab`, `lch`, `oklab`, `oklch`, `color`), named colours in colour positions, `color-mix()`, Tailwind arbitrary colour classes, and colour strings in JavaScript.
   - **Allowed:** `currentColor`, `transparent`, `inherit`, `initial`, `unset`, `none` and `var(--…)`.
   - **Exempt:** only `resources/css/themes/*.css` and the preset seed data.
2. **Compiled CSS scan.** Every colour value in the built CSS must sit inside a theme block.
3. **Sentinel theme test (Playwright).**
   - Every token gets a unique colour that would never appear by chance.
   - The test visits every screen and state: hover, focus, dialogs, dragging, the editor (including code blocks and topic chips), the calendar, charts, notifications and errors. This happens **in both workspaces**.
   - Every colour computed on the page, chart SVG included, must match a sentinel value.

**Focus.** Removing outlines is forbidden unless the shared focus-visible style replaces them. That style is a 2 px `--focus-ring` with an offset.

### 6.4 Custom themes (D7 = A), contrast and preferences

**Basic custom themes** arrive with the prototype and the first usable study workspace, not later:
- **What the student sets.** The student picks **seed colours**: background, surface, text and accent, plus optional categories. The server derives every other token from these, stepping lightness in OKLCH.
- **Live preview.** A preview updates live and shows contrast using culori.
- **Scope.** The saved theme belongs to the account and applies across **both workspaces**.

**Advanced editing of every individual token comes later,** under the same rules.

**Themes are data, never CSS:**
- only allowlisted token names are accepted, with `#rrggbb` values validated and normalised on the server;
- the server writes the CSS variables itself;
- user-supplied CSS, `url()`, expressions or any other strings are never accepted.

**Contrast rules, checked when a theme is saved:**

| Pair | Minimum |
|---|---|
| Text on background and on surface, including muted text on surface | 4.5:1 |
| Text on accent, and the `-on` colours on their status colours | 4.5:1 |
| Focus ring against background and surface | 3:1 |
| Strong border against surface | 3:1 |

- **Failures and presets.** A theme that fails is rejected, with the reason and a suggested fix. Built-in themes and admin presets must pass the same check, enforced by a unit test.
- **Saved preferences.** The preference is stored on the account as a theme ID, or as *system* with a light/dark pair. It syncs across devices and is cached per account.
- **No flash of the wrong theme.**
  - The server renders `<html data-theme="…">` and writes custom-theme variables inline in `<head>`.
  - In *system* mode, a tiny script in `<head>` picks light or dark before the first paint.
  - Switching theme is instant, and the choice is saved in the background.
- **Who manages what.** Admins manage the **platform presets**. Students choose their **personal** appearance.

## 7. Offline scope (D1 = A: draft-safe web)

**While disconnected with the tab still open:**
- **Works:**
  - reading and editing notes that are already open, or are among the notes kept in the editor host;
  - moving around the shell locally;
  - switching theme;
  - expanding folders that are already loaded.

  Every edit is written to durable drafts, and the indicator shows *Saved on this device*, or the storage-failure label.
- **Doesn't work, with a clear message:** opening other notes, search, dashboard refresh, calendar changes, creating, renaming, moving or deleting items, flashcard review, and MCP. In version 1, only note drafts are queued.

**After closing the browser:**
- **What survives:** drafts, and the selection at the time. Layout and theme preferences survive too.
- **What doesn't:** undo history, and any unsent action that isn't a note draft.
- **The app can't reopen offline.** There is no service worker, so the shell isn't cached. Reopening the app while offline shows the browser's own offline page. The drafts are safe in storage and reappear the next time the app is opened online.

**When the connection returns:**
1. The account is checked.
2. Deletion records are fetched and applied (§5.4).
3. Drafts are replayed in order with their `base_version`.
4. Conflicts go through the `409` flow.
5. A draft is deleted only after the server confirms the exact revision it saved.

**Cost of fuller offline support (not chosen).** It would need:
- a service worker to cache the shell;
- a local mirror of every entity;
- a queue of operations;
- synchronisation and conflict handling for every entity type;
- screens that render without the server.

That is effectively a second application rendered in the browser, and it would reopen the choice of Livewire.

**Flutter's offline scope** is a separate decision, to be taken later.

## 8. One set of business rules

- **One service layer.** All validation, permissions, learner isolation (ADR 0001 §5) and business rules live in **one application service layer**. Policies are enforced inside the services.
- **Three thin adapters sit on top:**
  1. **Livewire** for web screens, calling the services in the same process;
  2. **the JSON API** (`/api/v1`), used by the web note editor from day one, Flutter and plugins;
  3. **MCP tools**.
- **Precisely: the web app does not use the JSON API for everything.** Only note sync, attachments, deletion records and topic suggestions go through it.
- **How the API stays fit for Flutter and plugins:**
  - **A parity test.** Every student-facing capability must either have an API endpoint or appear on a web-only list with a reason.
  - **Offline-friendly conventions:** UUIDv7 IDs created by the client, idempotency keys, version checks (`409`), `410` for deleted items, cursor pagination, the deletion-record feed, and a general change feed when Flutter arrives.
  - **A documented contract.** An OpenAPI definition, with contract tests against it.
- **Livewire hardening:**
  - IDs are locked with `#[Locked]`.
  - Every action re-authorises through the service.
  - Tests forge requests to prove it (§10.5).

## 9. Study content model

### 9.1 Notes, versions and citations (D3 = A, with the evidence exception)

| Table | Contents |
|---|---|
| `notes` | `id` (UUIDv7), `learner_id`, container (course, module, folder, or top level), `title`, `current_version`, `position`, `trashed_at` |
| `note_versions` | `note_id`, `version`, `doc` (ProseMirror JSON, stored as content), `base_version`, `save_id`, `client_id`, `kind` (autosave, checkpoint, conflict_resolution or restore), `created_at` |
| `note_blocks` | Derived and rebuildable: block, note, version, text fingerprint, topic links, card attributes |
| `content_tombstones` | Deletion records (§5.4) |

**Editing sessions.** An editing session is a run of accepted saves to one note from one tab, each less than 30 minutes after the one before. A session ends when any of these happens:
- 30 minutes pass without a save from that tab;
- the tab closes;
- another tab or device saves the same note.

The **session-final version** is the last save accepted in that session.

**Retention:**
- Every version is kept for 30 days.
- After that, these are kept:
  - session-final versions;
  - checkpoints (versions the learner named explicitly);
  - conflict-resolution and restore versions;
  - the current version;
  - **every version cited by learning evidence**, whether by a claim, a task revision or another journal entry.

  A cited version is removed only if the note is **explicitly redacted** or the learner is **erased**.

**Citations pin the version.** Evidence cites `source:<note-id>#v<version>/<block-id>`, not just a block. Versions never change once saved, so editing a note can never change what an old learning claim cites. ADR 0002 §5 has the matching reference format.

**Trash and deletion:**
- Moving a note to the trash is soft for 30 days, and creates a deletion record.
- Permanent deletion or redaction follows ADR 0002 §10, including deletion records for browsers.

### 9.2 Courses, modules and folders

| Entity | Meaning | In the journal? |
|---|---|---|
| **Course** | Title, code, term, dates, and a colour **category**. Private to the learner in the pilot | Yes, as a `course` record (ADR 0002) |
| **Module** | An ordered unit of a course, with optional dates | Yes, as a `module` record |
| **Folder** | Organisation only. Nests up to 8 levels, and can sit under a course, a module, or the top level ("Personal") | No |
| **Note** | Lives in one container | Its source record (§9.3) |
| **Activity** | A lecture, lab, exam or deadline, attached to a course or module, and shown on the calendar | An existing record type |

- **Moving a note between folders** changes only the notes store.
- **Moving it to another course** also appends a new revision of its source record.

### 9.3 How study content feeds the memory engine

When a note version is saved, a background **extractor** runs:
- **How it runs.** It is deterministic (no LLM), idempotent per (note, version), and compares each version with the previous one.
- **Note as a source.** The note gets a `source` record, so evidence can cite `source:NOTE#v<version>/<block>`.
- **Topic links.** Topic links go into the **derived** `note_blocks` index, which powers backlinks and retrieval. They are organisation, not learning evidence, so they don't become journal claims.
- **Flashcards.** Cards become `task` records plus `exercises` claims (§9.4).
- **Reviews.** Card reviews become **batched attempts**, one event per review session, in milestone M5 (ADR 0002 §12).

### 9.4 Flashcards: identity, revisions and trusted checkers (D2 = A)

**Identity follows the card, not its wording.**
- **Creating a card.** Creating a card creates a `task` record with a new ID and the key `card:<note-id>#<block-id>`. Block IDs stay stable through edits and drag moves.
- **Revising a card.** A **meaningful change** creates a new **revision** of the same task, not a new task. A meaningful change is a change to the normalised front, back or card type (Unicode NFKC, whitespace collapsed, formatting marks removed).
  - Each revision appends a task record revision with `revision`, `content_hash` and a citation of the note version it came from.
  - A change to formatting or whitespace alone creates no revision.
- **Attempts.** Attempts record `task` plus `task_revision`. Earlier attempts keep the revision they answered and are never rewritten.
- **Diversity.** Task diversity counts **task IDs**, so revising a card never increases diversity.
- **A new task.** Only an explicit "Make this a new card" action, or a new block, creates a new task.
- **Copies.** A copied card gets a new block and a new task. The extractor proposes `same_as` when the normalised content matches another active card, and `policy@1` accepts matches at confidence 0.90 or above. Copies therefore can't inflate diversity.
- **Deleting a card.** This retires the task. Its attempts remain.

**Self-grading only schedules practice.** A review graded by the learner ("again / hard / good / easy") is recorded with `judged_by: self`. It drives the spaced-repetition schedule and appears as *practice*. Under ADR 0002 it never counts towards a topic's label.

**Automatically checked answers are *eligible evidence*, not automatic mastery.** A checked answer is recorded with `judged_by: auto` only when a **trusted checker** produced it. A checker is trusted only if all four of these hold:

1. **The key is independent.** The expected answer or test comes from something other than the learner being assessed. That means course or instructor material with recorded provenance (for example, a lab's test suite or an official answer key), or a deterministic computation from such material (for example, running the course's reference SQL against the sample database).
2. **It is deterministic and versioned.** The same answer always gets the same verdict. Every attempt records `checker: {id, version, key_source}`.
3. **The answer can be checked automatically.** That means exact or normalised matching of short answers (numbers, identifiers, terms), running tests for code, or comparing result sets for SQL. Free-text explanations are never auto-checked.
4. **The learner can't make it trivial.** A test suite or key the learner has edited is not trusted.

**A key the learner wrote is not trusted.** Matching the back of a card the learner wrote themselves only shows the answer is consistent with their own note, not that it is correct. It is recorded as `judged_by: self`, which means practice.

**Every ADR 0002 rule still applies to trusted checks:**
- task diversity counts task IDs;
- sessions;
- retention;
- authority;
- disputes, which are settled by re-running the checker;
- immediate repeats don't count.

Flashcards use the `recall` and `recognise` forms. On their own they can lift a topic to **working** at most, because `secure` needs *apply* tasks.

**Pilot note.** The owner uploads their own course material, so its provenance is whatever the owner says it is. That is acceptable for a personal pilot. Recording where material came from is required before shared or institutional material exists.

## 10. Student and admin workspaces

One application and one account system, with **two workspaces**. Navigation is separate, permissions are checked on the server, components and theme tokens are shared, and the user's personal theme applies in both.

### 10.1 Screens

| Student workspace | Admin workspace |
|---|---|
| Home: dashboard, what's due, recent notes | Overview: system health, queue depth, failed jobs, overdue redaction clean-up, storage |
| Notes, courses and modules, folders | Accounts: invite or create, suspend or reactivate, request deletion |
| Calendar | Roles: grant or revoke admin |
| Review: flashcards (M5) | Theme presets |
| Learning history: topic states, questions, misconceptions, with the evidence for each | Background jobs |
| Inbox: pending claims and disputes | Audit log (read-only) |
| Search and command palette | Platform settings: invite-only mode, limits |
| Connections: ChatGPT and Claude (M6) | Approved plugins (later) |
| Settings: profile, **appearance and basic custom theme**, privacy (redact, export, delete account), devices and sessions | — |

The admin workspace has its own sidebar, plus a permanent **Admin** marker (`--role-admin`) showing which role is active.

### 10.2 Permissions

| Action | Student (own account) | Admin | Anyone else |
|---|---|---|---|
| Read or write own notes, folders, courses, calendar, flashcards and learning history | ✓ | Only as a student, on their own data | ✗ |
| Read **another** person's learning content (notes, journal, AI conversations) | ✗ (returns 404) | **✗**. Any future support access needs an explicit, time-limited grant from the student, and is audited | ✗ |
| Choose or create a personal theme | ✓ | ✓, as a student | ✗ |
| Create or edit platform theme presets | ✗ | ✓ (2FA required) | ✗ |
| Create or invite accounts | ✗ | ✓ (2FA and a recent password confirmation) | ✗ (invite-only, D4) |
| Suspend or reactivate an account | ✗ | ✓ (2FA and recent password confirmation). Audited. Refused if it would leave no active admin | ✗ |
| Delete an account (erasure, ADR 0001) | ✓, own account, re-confirmed. Refused if it would leave no active admin | ✓ on request (2FA and recent password confirmation). Audited. Refused if it would leave no active admin | ✗ |
| Grant or revoke admin | ✗ | ✓ (2FA and recent password confirmation). Audited. Refused if it would leave no active admin | ✗ |
| Reset another admin's 2FA | ✗ | ✓ (2FA and recent password confirmation). Audited | ✗ |
| View account details (email, dates, last active, storage, counts) | Own only | ✓. **Details only, never content** | ✗ |
| View system health and jobs, retry jobs | ✗ | ✓ (2FA required) | ✗ |
| View the audit log | ✗ | ✓, read-only. No path in the app can edit or delete entries | ✗ |
| Connect an external AI | ✓ (own) | Only as a student, on their own data | ✗ |
| Redact own content | ✓ | ✗ | ✗ |

"Recent password confirmation" means within the last **10 minutes**. The services check it, not only the screens.

### 10.3 Accounts, roles, 2FA and recovery (D4, D5)

**Accounts, roles and learners**
- A **user** is a login identity.
- Roles are stored in `user_roles` as `student` and/or `admin`.
- The `student` role comes with a **learner** record (ADR 0002). An account that is only an admin has no learning data.

**Invite-only (D4)**
- The pilot starts with the owner's account plus **synthetic test accounts**. Self-registration is off.
- Invite-only is **not** a substitute for ADR 0001's safeguards. Before another real learner joins, three things must be in place:
  - encryption with a separate key per learner;
  - the full G2 isolation suite;
  - the G3 erasure drill.

**Setting up the first admin**
1. On the server, run `php artisan vistud:account:create {email}`, followed by `php artisan vistud:admin:grant {email}`. There is no web route for creating or granting the first admin. Both steps are audited as system actions.
2. The first time the new admin enters the admin workspace, they must enrol in 2FA before anything else. Fortify then shows their recovery codes once.

**Mandatory 2FA, enforced everywhere**
- Every `/admin` route is protected by `auth`, `role:admin` and `two_factor_enrolled`. That covers **direct entry by URL**, not just the workspace switch.
- An admin without 2FA is sent to enrol and can reach nothing else.
- Protected actions also require a recent password confirmation.
- The same checks run **inside the services**, so a Livewire action, an API call or a console path can't skip them.

**Recovering admin access**
- **Lost device:** use a recovery code.
- **Lost codes as well:** another admin resets their 2FA. This is a protected, audited action, and the user must enrol again before entering the admin workspace.
- **No other admin available:** run `php artisan vistud:admin:reset-2fa {email}` on the server, which needs shell access and is audited as a system action.

**The last-admin safeguard**
- An *active admin* is an account with the admin role that is neither suspended nor deleted.
- The service layer refuses **any** operation that would leave zero active admins: revoking the role, suspending, deleting or erasing, **including an admin deleting their own account**. The same service backs every path: the admin screens, student self-service and the console commands.

**Switching workspace**
- The switch is shown only to users with the admin role.
- Entering the admin workspace requires 2FA and a recent password confirmation.
- The active workspace is stored in the session.
- Ordinary accounts can never grant themselves admin. Roles can't be set through mass assignment, registration or profile fields.

### 10.4 Isolating student data, and audit records

- **Isolation everywhere.** The learner-scoped data layer (ADR 0001 §5) applies in the same way to Livewire, the API and MCP.
- **Two kinds of denial (D6):**
  - a student who opens an admin route gets **403**;
  - a private record belonging to another learner returns **404**, exactly the same response as a record that doesn't exist. This holds for guessed URLs, forged Livewire requests, API calls and MCP tools.

  IDs are UUIDv7s. Nothing depends on them being hard to guess.
- **No admin path to learning content.** Admin services have **no** methods that return learning content.
- **Audit log (`audit_log`).**
  - **Contents:** actor (user and role, or system), action, target, metadata (never content), IP, user agent, request ID, time.
  - **Append-only.** The application's database user can only insert and read.
  - **Retention:** kept for as long as the platform runs. No email addresses or learning content are stored in it.

### 10.5 Security tests (part of the M1 gate)

| Test | What it proves |
|---|---|
| T1 | A student opening any `/admin` route gets 403 |
| T2 | A student sending a forged request to an admin Livewire component gets 403 |
| T3 | Student A trying to reach B's records by URL, API, a tampered locked Livewire property or an MCP tool gets 404, identical to a record that doesn't exist. B's canary markers never appear (ADR 0001 G2) |
| T4 | An admin can't get student content through admin screens, the API or Livewire. Admin pages contain no canary markers |
| T5 | A `role` field in a registration, invite-acceptance or profile request changes nothing |
| T6 | Granting a role requires 2FA and a recent password confirmation, and every grant or revocation writes an audit record |
| T7 | Switching into the admin workspace without the admin role gets 403. The workspace marker matches the active role |
| T8 | Audit records can't be updated or deleted through the app |
| T9 | An admin without 2FA who opens `/admin/...` directly is sent to enrol. A protected action without a recent password confirmation is refused, whether it arrives through Livewire, the API or a console path that goes through the services |
| T10 | Revoking, suspending, deleting or self-erasing the last active admin is refused. The first-admin and 2FA-reset console commands work and are audited |

## 11. Responsive workspace layout

**Desktop (1280 px wide and above)**
- **Left sidebar.** Resizable from 220 to 360 px, and collapsible. It contains:
  - the workspace switcher;
  - search;
  - Home, Calendar, Review (with a count of cards due) and Inbox;
  - **Courses**, which open into modules, folders and notes;
  - **Personal** folders;
  - Trash;
  - Settings.
- **Main area.** A breadcrumb and the save status, with the content below.
- **Right panel.** Optional and resizable, closed by default where it wouldn't help.
  - For a note: its outline, backlinks, linked topics with their state, its flashcards, and its versions.
  - For the calendar: event details.

**Tablet (768–1279 px)**
- The sidebar becomes an overlay drawer.
- The right panel slides over the content.
- Panels can't be resized.

**Mobile (under 768 px).** A **deliberately different layout**, not a shrunken desktop:
- bottom navigation: Home, Notes, Review, Calendar, More;
- drill-down lists instead of a tree;
- the editor goes full screen, with a toolbar above the keyboard;
- bottom sheets instead of the right panel;
- no split panels.

**Admin** uses the same breakpoints with its own sidebar. On mobile, its data tables turn into cards.

## 12. Dependencies

Checked on 2026-09-25. Exact versions will be pinned in the lock files.

| Package | Version | Licence | Use | Milestone |
|---|---|---|---|---|
| laravel/framework | 13.33.0 | MIT | Application | M1 |
| livewire/livewire | 4.4.6 | MIT | Screens, `wire:navigate`, `@persist`, `wire:offline`, `wire:dirty`, `wire:loading`, `wire:ignore` | M1–M2 |
| laravel/fortify | 1.40.0 | MIT | Login, 2FA and recovery codes, password confirmation and reset, with our own views | M1 |
| phpunit/phpunit | 12.5.x | BSD-3-Clause | Server tests | M1 |
| vite / laravel-vite-plugin | 8.3.1 / 3.2.0 | MIT | Build | M2 |
| tailwindcss / @tailwindcss/vite | 4.3.3 | MIT | Token utilities | M2 |
| Alpine (bundled) with @alpinejs/focus, @alpinejs/anchor, @alpinejs/collapse | 3.17.4 | MIT | Local interactions | M2 |
| @tiptap/core, pm, starter-kit, extension-unique-id, extension-drag-handle, suggestion, extension-link, extension-image, extension-file-handler, extension-code-block-lowlight, markdown | 3.31.3 | MIT | Editor foundation | M2 |
| lowlight | 3.3.0 | MIT | Code highlighting | M2 |
| fullcalendar | 7.1.0 | MIT | Calendar: day, week, multi-month and list views, plus interaction | M2 |
| echarts | 6.1.0 | Apache-2.0 | Charts | M2 |
| idb | 8.0.3 | ISC | IndexedDB drafts | M2 |
| culori | 4.0.2 | MIT | Contrast preview in the basic theme editor | M2 |
| Lucide icon SVGs | 1.48.0 | ISC | Blade icon components drawn with `currentColor` | M2 |
| @playwright/test | 1.63.0 | Apache-2.0 | Browser and acceptance tests | M2 |
| @axe-core/playwright | 4.13.0 | MPL-2.0 | Accessibility checks | M2 |
| laravel/passport | 13.8.0 | MIT | OAuth for Flutter, MCP and plugins | M6 |
| laravel/mcp | 1.0.1 | MIT | MCP server | M6 |
| laravel/reverb | 1.12.0 | MIT | Live updates | M6 |

Qdrant (Apache-2.0) and the local embedding runtime arrive in **M3**, per ADR 0001. The exact runtime will be chosen and version-pinned in M3.

**Not used and not approved:**
- Flux, whose free tier is proprietary-licensed, and whose palette conflicts with §6;
- Flux Pro;
- Tiptap's paid Pro and Cloud features;
- FullCalendar Premium;
- Sanctum;
- Dusk;
- Pest's browser plugin.

**No paid features are needed.**

**Recommendations:**
- **Charts: ECharts.** It has a calendar heatmap, ARIA support, and an SVG renderer whose colours the sentinel test can inspect.
- **Browser tests: Playwright Test.** It supports throttling, offline mode, request interception, multiple tabs and contexts, persistent contexts and viewports.
- **Reverb** is not needed until M6.
- **Passport alone.** The web app uses sessions, and its `/api/v1` sync runs under the web middleware group with CSRF protection. Passport arrives in M6 for external clients, using the same controllers.

## 13. Milestones (D8, revised)

| Milestone | Scope | Gate |
|---|---|---|
| **M1: Minimum foundations** | Schema for the journal, content and projections (ADR 0002). The golden replay and its edge cases made **executable**, with the draft projection code fixed until they pass. Fortify: login, invite acceptance, 2FA, password confirmation. Roles, the workspace switch, the admin middleware. The first-admin and 2FA-reset commands. The learner-isolation layer. The audit log | All ADR 0002 replay and edge-case tests pass, and T1–T10 pass |
| **M2: Workspace prototype** | The thin slice described in §14. This includes the **basic custom theme in both workspaces**, the draft-safe editor, deletion records, and a minimal admin shell | Every item in §14 passes. After that, ADR 0003 can be marked ACCEPTED |
| **M3: Retrieval pilot** (ADR 0001 G1) | Qdrant, the local embedding runtime (behind the processor boundary), keyed keyword tokens with one installation key, the indexing outbox and consistency check, and the retrieval gateway with learner filter and re-fetch. In-app search across notes and course material. A **brief preview**, showing what an AI would be sent. Retrieval traces and the labelling screen. A minimal canary test | Retrieval works on seeded data, and the canary test passes. **G1 data collection starts when M4 begins** |
| **M4: Study workspace v1** | The full editor feature set from §5.1. Courses, modules and folders. The calendar with activities. A dashboard built from projections. Learning history and the inbox. Settings: appearance with basic custom themes, privacy with redaction and export. The admin workspace: accounts, roles, audit, jobs, theme presets, platform settings | Acceptance tests for each feature. The owner's real pilot begins. **Before another real learner joins:** ADR 0001 encryption, full G2, and G3 |
| **M5: Flashcards** | Extracting cards, with identity and revisions (§9.4). The review screen. Self-graded scheduling. Trusted checkers for typed answers. Batched attempts. The review forecast | Review and evidence tests, including "revising a card doesn't increase diversity" and "a key the learner wrote counts as `self`" |
| **M6: AI connections** | Passport, the MCP server (capture and `get_context`), the connection screens, Reverb live updates | ADR 0002 §9 capture tests. **G1b** with chat queries (D9) |
| **Later** | Advanced per-token theme editing, plugins, Flutter (including its offline scope), shared streams | Separate decisions |

Nothing beyond M1 and M2 starts until the gates before it have passed. Deferred features stay deferred.

## 14. Acceptance checklist

### M1 (foundations)

- [ ] Golden replay: all 13 checkpoints, the reconstruction assertions A1–A6, and variants V1–V5 pass.
- [ ] Edge cases pass: task identity (X1–X5), disputes (D1–D6), redaction and restore (R1–R3).
- [ ] T1–T10 pass.
- [ ] The first admin can only be created from the console. 2FA enrolment is forced for admins, and recovery codes are shown once.
- [ ] The audit log is append-only at the database-permission level.

### M2 (workspace prototype)

**Seeded data:**
- 1 owner account holding both roles;
- 1 synthetic student account;
- 2 courses, 6 modules, 10 folders;
- 30 notes, one of 5,000 words;
- 20 activities;
- 3 built-in themes, one of them dark.

Every item below is an automated Playwright test unless marked *review*.

1. [ ] **No reloads.**
   - Going Note → Dashboard → Calendar → Note keeps a marker set on `window` alive, and the browser records no new document navigation.
   - Switching views takes at most 300 ms (95th percentile) locally.
2. [ ] **Back, forward and direct links.**
   - Back and forward restore the view, the selection and the scroll position.
   - `/notes/{id}#block` opens directly.
   - An unknown ID, and another learner's ID, show the same "not found" state inside the shell.
3. [ ] **Editor state within the retained editors.**
   - Type, move the selection, go to the dashboard and come back: the text and selection are unchanged, and **undo still undoes** the typing.
   - A reload restores the draft and the selection.
   - Closing the browser and reopening it online restores the draft.
4. [ ] **The sixth note.**
   - Open notes 1–6 in turn, then go back to note 1: its content and selection are restored, and **undo does nothing** (its history was cleared by policy). No error appears.
   - Notes 2–6 still have their undo history.
5. [ ] **Workspace state.** Sidebar width, expanded tree nodes and the right panel state survive navigation and a reload.
6. [ ] **Local stays local.** Opening menus, expanding loaded folders, resizing panels, switching themes and typing send no requests. The only exceptions are the deferred autosave and preference saves.
7. [ ] **Responsive.**
   - At 390×844, 834×1194 and 1440×900 the layout matches §11, with no horizontal scrolling.
   - Mobile uses the bottom navigation and drill-down lists.
   - *Review:* snapshots checked by the PM.
8. [ ] **Themes.**
   - Switching between the 3 built-in themes updates the shell, editor, calendar, chart, dialogs and focus ring without a reload.
   - The source scan, compiled-CSS scan and sentinel test all pass **in both workspaces**.
   - With a dark preference, the correct theme shows from the first paint.
9. [ ] **User-created theme.**
   - The user builds a basic custom theme from seed colours, and a contrast failure is rejected with the reason.
   - The saved theme applies across **both workspaces**: editor, calendar, chart, dialogs, hover, focus, selected, disabled and dragging states.
   - The sentinel test runs with a user-created theme.
   - The theme is still active after logging out and back in.
10. [ ] **Slow connection** (1.5 s latency). The workspace stays usable, *Saving…* is shown, a skeleton appears within 150 ms, and there is no full-page spinner.
11. [ ] **Failed saves.** 500 errors and timeouts show *Not saved, retrying*, retries back off, the draft is kept, and saving recovers with nothing lost.
12. [ ] **Only the saved revision is cleared.** Hold a save in flight, type more, then let the save succeed: the newer text is still in the draft, and the next save sends it.
13. [ ] **Storage failure.** With IndexedDB writes failing, the indicator shows *Not stored on this device, keep this tab open*, and the browser warns before the tab closes.
14. [ ] **Offline.** The banner appears, typing continues, and *Saved on this device* is shown. Going online flushes the saves in order. Reopening the browser while offline shows the browser's own offline page (the shell is not cached), and the drafts reappear once online.
15. [ ] **Drafts are per account.**
    - Account A leaves a draft. B logs in on the same browser: B never sees A's draft, and nothing of A's is sent under B's session.
    - Tabs of A and B don't exchange messages.
    - A tab whose account changes stops its queue immediately.
16. [ ] **Logout and revoked access.**
    - Logging out with unsynced drafts offers Sync, Keep and Discard. After logout, no retries are sent.
    - A `403` stops retries for good.
    - A `401 account_deleted` purges that account's drafts.
17. [ ] **Deletion records.**
    - A note deleted on another device, while this device is offline with a draft for it: on reconnect, the draft is purged **before** any replay, and nothing recreates the note.
    - A trashed note offers "Restore and apply" or "Discard".
    - A direct `PUT` to a deleted note returns `410`.
18. [ ] **Conflicts.**
    - A second tab is warned through `BroadcastChannel`.
    - A save against an older version gets `409` and the conflict screen.
    - Both versions are kept.
19. [ ] **Editor lifecycle.** After 50 navigations there are at most 5 editors, and the JavaScript heap has grown by less than 20 MB.
20. [ ] **Accessibility.** Everything can be done from the keyboard, focus is always visible, and axe finds no serious or critical issues.
21. [ ] **Workspaces.**
    - The switch appears only for admins, and entering the admin workspace needs 2FA and a recent password confirmation.
    - A student gets 403 on `/admin`.
    - The admin marker is visible.
    - The minimal admin shell (accounts list, audit log) uses the same components and the user's theme.

## 15. Consequences

**Positive**
- One Laravel and Livewire stack.
- The editor, where the experience matters most, runs entirely in the browser.
- Theming is enforced by tests, including a theme the user builds.
- Drafts are account-safe and can't bring deleted content back.
- Citations are pinned to versions.
- Flashcard evidence can't be gamed by rewording cards or by keys the learner wrote.
- Admin status never exposes study content.

**Costs and risks**
- The editor schema is custom code on Tiptap, which makes it the largest front-end effort.
- Parity between the web app and the API relies on the parity test.
- Offline is limited to drafts, and the app can't open while offline.
- Plaintext drafts in the browser are a documented exception in ADR 0001, with limits on how far we can purge them.
- Screens rendered by the server need careful, targeted loading states. The prototype has to prove they feel right.

## 16. Decisions

**Decided by the PM (2026-09-25):**
- **D1 A:** draft-safe web.
- **D2 A:** self-grading schedules practice; answers checked by a trusted checker are eligible evidence.
- **D3 A:** version retention, keeping cited versions.
- **D4 A:** invite-only.
- **D5 A:** mandatory 2FA for admins.
- **D6 A:** 403 for admin routes, and 404 for other learners' records.
- **D7 A:** seed-colour themes in the prototype, with Advanced editing later.
- **D8 A (revised):** the order in §13.

**Decided by the PM before WP4 (M1):**
- **D9 A (staged):** G1a measures in-app queries, starting at M4; G1b later checks chat queries, once MCP capture exists (M6). The agreed evidence thresholds apply to each stage. If the evidence is insufficient, the result is reported as **inconclusive**; nothing is decided just because 4–6 weeks have passed. See ADR 0001, G1.
