# ViStud: project overview

Read this first if you are new to the project. It explains what ViStud is, how it is built, how we work, and where every detailed decision lives. It summarises; the linked documents are authoritative. Where this page and a linked document disagree, the linked document wins, and this page should be fixed.

Then read [docs/coordination/STATUS.md](docs/coordination/STATUS.md) for who is doing what right now.

## 1. What ViStud is

ViStud is a study platform with a **persistent study brain**. Students keep notes, organise courses and modules, plan in a calendar and review flashcards, much as they would in RemNote. Underneath, the brain records what the student actually does and learns (lectures attended, questions asked, attempts made, explanations received) in an **append-only journal**. From that journal it works out, by fixed and versioned rules, where each topic stands: introduced, developing, working, secure or durable, together with open questions and active misconceptions.

The memory belongs to the student, not to any AI model. External assistants such as ChatGPT or Claude can read a brief from it and propose additions to it through MCP (from milestone M6), but they are one way in, not the product. No LLM ever writes the student's state directly, and rebuilding that state never calls an LLM.

**Pilot scope.** The first phase is a **personal pilot**: the owner's own account plus synthetic test accounts, invite-only ([ADR 0003](docs/adr/0003-web-workspaces-and-study-content.md) decision D4). Before any other real learner joins, three safeguards from [ADR 0001](docs/adr/0001-permanent-store-and-retrieval-index.md) must be in place: encryption of personal text with per-learner keys, the full tenant-isolation test suite (gate G2), and the erasure drill (gate G3). Invite-only is not a substitute for them.

## 2. Two workspaces, one application

| Workspace | Who | What it holds |
|---|---|---|
| **Student** | Anyone with the student role | Notes, courses and modules, folders, calendar, flashcard review, learning history, inbox, search, AI connections, settings |
| **Admin** | Accounts with the admin role and two-factor authentication | Accounts, roles, audit log, background jobs, theme presets, platform settings |

Both run in one Laravel application with one account system and shared components and theme tokens, but separate navigation. Permissions are checked on the server, never just by hiding buttons. **Being an admin never grants access to anyone's learning content**: admin services have no way to return it. A student who opens an admin URL gets 403; another learner's record answers 404, exactly like a record that doesn't exist.

Authoritative: [ADR 0003 §10](docs/adr/0003-web-workspaces-and-study-content.md#10-student-and-admin-workspaces).

## 3. Architecture and stack

| Layer | Choice | Decided in |
|---|---|---|
| Application | Laravel 13, one application with strict module boundaries | [ADR 0001](docs/adr/0001-permanent-store-and-retrieval-index.md), [ADR 0003](docs/adr/0003-web-workspaces-and-study-content.md) |
| Permanent store | MySQL 8.4 LTS for structured data; Laravel Storage for canonical files | ADR 0001 |
| Retrieval index | Qdrant with local embeddings, derived and rebuildable, holding no content (arrives in M3) | ADR 0001 |
| Learning model | An append-only journal of observations, claims, records and amendments; state derived by versioned rules (`rules@1`) | [ADR 0002](docs/adr/0002-learning-event-schema.md) |
| Web UI | Blade and Livewire 4, Alpine for local interactions, Tailwind 4 with semantic tokens only (from M2) | ADR 0003 |
| Note editor | Tiptap 3 with ViStud's block schema, isolated from Livewire (from M2) | ADR 0003 |
| Authentication | Laravel sessions through Fortify, mandatory 2FA for admins; Passport for external clients in M6 | ADR 0003 |

**Shared services and adapters.** All business rules, validation, permission checks and learner isolation live in one **application service layer**. Livewire screens, the JSON API (`/api/v1`), MCP tools (M6) and console commands are thin adapters over it, so no entry point can skip a rule. Every service receives a `Principal` (who is acting) or a `LearnerScope` (whose data), and every query on a learner's data goes through one isolation layer that always adds the learner filter.

The web app uses the JSON API only for note sync, attachments, deletion records and topic suggestions; everything else calls services in-process. The API is still complete enough for Flutter and plugins, which a parity test will check.

Authoritative: [ADR 0003 §8](docs/adr/0003-web-workspaces-and-study-content.md#8-one-set-of-business-rules), [modules.md](docs/architecture/modules.md), [contracts.md](docs/architecture/contracts.md), [conventions.md](docs/architecture/conventions.md), [openapi.json](docs/api/openapi.json).

## 4. The experience we are building

These are requirements, and the M2 prototype must prove them with automated tests before ADR 0003 is accepted:

- **No full-page reloads** in normal use. `wire:navigate` swaps the main area while a persistent shell (sidebar, top bar and editor host) stays in place. Every screen and note still has a real URL, and back and forward work.
- **Working state is preserved.** Open editors keep their content, selection and undo history as you move around (up to five notes per tab). Unsaved drafts are kept per account in the browser and survive reloads and restarts.
- **Local interactions are instant**, and saving, loading, error and retry states are always visible without blocking the workspace.
- **Responsive by design:** a desktop layout, a tablet layout, and a deliberately different mobile layout with bottom navigation and drill-down lists.
- **Colours come only from semantic tokens.** Tailwind's built-in palette is removed, a three-layer check enforces this, and students can build a basic custom theme from seed colours, which must work across both workspaces.
- **Offline is draft-safe, not offline-first:** open notes stay editable while disconnected, but the app cannot be opened offline (decision D1).

Authoritative: [ADR 0003 §3–7 and §11](docs/adr/0003-web-workspaces-and-study-content.md), and its acceptance checklist in §14.

## 5. Milestones and gates

| Milestone | In short | Gate |
|---|---|---|
| **M1 Minimum foundations** (current) | Schema, the golden replay and its edge cases as executable tests, authentication with 2FA, roles, workspaces, learner isolation, audit log | All replay and edge-case tests pass; security tests T1–T10 pass; first admin from the console only; recovery codes shown once; audit log append-only at the database-permission level |
| **M2 Workspace prototype** | A thin working slice: draft-safe editor, deletion records, basic custom theme in both workspaces, minimal admin shell | Every item in ADR 0003 §14 passes; ADR 0003 becomes ACCEPTED |
| **M3 Retrieval pilot** | Qdrant, local embeddings, keyed keyword tokens, the retrieval gateway, in-app search | Retrieval works on seeded data; the minimal isolation canary passes |
| **M4 Study workspace v1** | The full study workspace and admin workspace; the owner's real pilot begins | Feature tests; encryption, G2 and G3 before another real learner joins |
| **M5 Flashcards** | Card identity and revisions, review, self-graded scheduling, trusted checkers | Review and evidence tests |
| **M6 AI connections** | Passport, the MCP server, connection screens, live updates | Capture tests; retrieval gate G1b |

Nothing beyond the current milestone starts until the gate before it has passed, and deferred features stay deferred. **The PM approves every milestone gate.** All three ADRs are still PROVISIONAL; each names what makes it ACCEPTED.

Authoritative: [ADR 0003 §13–14](docs/adr/0003-web-workspaces-and-study-content.md#13-milestones-d8-revised), [ADR 0001 validation gates](docs/adr/0001-permanent-store-and-retrieval-index.md#validation-gates), [the golden replay spec](docs/specs/golden-replay-sql-joins.md).

## 6. Getting the repository running

The supported and tested environment is **PHP 8.4** and **MySQL 8.4 LTS**. CI runs those versions. `composer.json` allows a broader PHP constraint (`^8.3`). That constraint is only the range Composer will accept at install time. It does not establish the supported or tested environment.

XAMPP's existing PHP 8.0 and MariaDB installation does not meet this baseline. Use PHP 8.4 and MySQL 8.4 for the application and the tests.

| Requirement | Version |
|---|---|
| PHP | 8.4, with `pdo_mysql`, `mbstring`, `intl` and `sodium` |
| Composer | 2.x |
| MySQL | 8.4 LTS, the supported and tested database |
| Node | Not needed on this branch. WP6 (`m1/wp6-web-adapters`, [PR #3](https://github.com/abela7/vistud/pull/3)) adds Node 22 for the front-end build and the browser tests |
| Unix, for one test | `pcntl` and `posix`, so the process-forking concurrency test can run. Windows PHP does not provide them |

The database-user command below is a **Unix shell** command. It uses shell input redirection and is not a PowerShell command. A Windows procedure has not been verified. Details: [docs/development/setup.md](docs/development/setup.md).

```bash
# Unix shell only (bash). Not PowerShell.
sudo mysql < database/scripts/local-mysql-users.sql   # once per machine: two MySQL users
composer setup                                        # install, .env, key, migrations
composer test                                         # every suite
composer lint                                         # code style
```

`tests/Feature/Brain/Store/ConcurrentAppendTest.php` forks two processes with `pcntl` and `posix`. Where those functions are missing, PHPUnit skips the test. A skipped concurrency test does not satisfy the M1 gate. The gate needs a Unix run in which the test executes, which is what CI does.

Migrations always run as the schema owner (`composer migrate`), and the application runs as a restricted database user. Tests use MySQL, never SQLite. Full instructions, database users, local accounts and troubleshooting: [docs/development/setup.md](docs/development/setup.md).

## 7. How we work together

**Roles.** The **PM** reviews and coordinates: they assign work packages, decide open questions, and are the only one who can mark a package or milestone Accepted. The **architect** (backend lead) owns the architecture, database schema, backend, shared application services, API contracts and integration. Other developers and independent validators each own the work packages the PM assigns them.

**Work packages.** Work is split into packages with named owners, dependencies, acceptance criteria and owned files: [docs/handoff/m1-work-packages.md](docs/handoff/m1-work-packages.md). Their live state is in [STATUS.md](docs/coordination/STATUS.md).

**File ownership.** Every file belongs to exactly one package. Shared files (dependencies, configuration, migrations, route entry points, CI, the Platform module and the architecture documents) belong to the architect; ask for changes in your pull request or through a `contract-change` issue instead of editing them. Web areas have one route file per package under `routes/web/`. Details: [modules.md, "Shared files"](docs/architecture/modules.md#shared-files).

**Contracts.** Anything another package builds on (service methods, tables, API endpoints, error codes, audit action names, the journal entry format, projection output) is a contract, labelled **Stable**, **Provisional** or **Draft**. Changing one needs a pull request labelled `contract-change`, reviewed by the architect and, for Stable contracts or ADR decisions, approved by the PM. Contracts never change silently inside an unrelated pull request. Details: [contracts.md](docs/architecture/contracts.md#changing-a-contract).

**Independent validation.** Acceptance tests are written by validators from the ADRs and specs alone, and implementers never edit them. When a test and an ADR disagree, the validator raises it with the PM instead of changing either. Acceptance tests may land before the code passes them: CI reports them without failing the build until the PM declares the gate.

**Branches and pull requests.** One branch per package, small pull requests, green CI before review, and every contract change listed in the pull request description. The integration branch is `claude/persistent-study-context-zsilo6` (recorded in STATUS.md).

**Status updates.** Each developer updates only their own package's entry in STATUS.md, at each handoff or status change. Changes to shared scope or ownership go through the PM.

## 8. Reading order

1. [README.md](README.md), then this page.
2. [docs/coordination/STATUS.md](docs/coordination/STATUS.md): the current state, owners and open decisions.
3. The decisions, in order:
   - [ADR 0001: Permanent store and retrieval index](docs/adr/0001-permanent-store-and-retrieval-index.md)
   - [ADR 0002: Learning event schema](docs/adr/0002-learning-event-schema.md)
   - [ADR 0003: Web workspaces, front-end stack and study content](docs/adr/0003-web-workspaces-and-study-content.md)
4. [docs/specs/golden-replay-sql-joins.md](docs/specs/golden-replay-sql-joins.md): the reference scenario that the projection must reproduce.
5. The architecture documents:
   - [modules.md](docs/architecture/modules.md): structure, module responsibilities, shared files
   - [conventions.md](docs/architecture/conventions.md): authentication, authorization, validation, errors, tests
   - [contracts.md](docs/architecture/contracts.md): what you can build on, and how to change it
   - [schema.md](docs/architecture/schema.md): the database
   - [docs/api/openapi.json](docs/api/openapi.json): the JSON API
6. [docs/development/setup.md](docs/development/setup.md): get it running.
7. [docs/handoff/m1-work-packages.md](docs/handoff/m1-work-packages.md): the package you have been assigned, its acceptance criteria and its files.
