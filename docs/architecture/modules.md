# Project structure and module responsibilities

- **Status:** M1, increment 1. Stable unless a section says otherwise.
- **Owner:** the architect (backend lead). Changes follow [contracts.md](contracts.md#changing-a-contract).
- **Related:** [schema.md](schema.md), [contracts.md](contracts.md), [conventions.md](conventions.md), [ADR 0003 §8](../adr/0003-web-workspaces-and-study-content.md#8-one-set-of-business-rules).

ViStud is one Laravel 13 application. Business rules live in **application services**, grouped into modules. Livewire screens, the JSON API, MCP tools (M6) and console commands are **thin adapters** over those services: they translate input and output, and nothing else.

## Layout

```
app/
  Platform/            Cross-cutting infrastructure used by every module
    Access/            Principal, Role, Guard, LearnerScope
    Database/          LearnerTables (isolation layer), RuntimeGrants
    Errors/            AppError and its subclasses
    Http/              Error envelope, request ID, account header
    Ids.php            ID rules and UUIDv7 generation
  Identity/            Accounts, roles, learners, invitations, 2FA, workspaces
  Audit/               The append-only audit log
  Appearance/          Themes as data: colours, gradients, contrast validation, the theme stylesheet (DESIGN.md §3)
  Brain/               The learning memory engine (ADR 0001, ADR 0002)
    Journal/           Entry value objects, vocabulary, validation, review policy (pure PHP)
    Projection/        rules@1: topic labels, misconceptions, questions, profile  (pure PHP)
    Store/             Journal persistence and learner-scoped reads
    Writer/            The append service
    Redaction/         Redaction, clean-up, reconciliation                        (work package WP5)
  Http/Controllers/    HTTP adapters: JSON API (Api/V1/...) and form endpoints
  Http/Middleware/     Route checks: account status, role, 2FA, admin workspace
  Livewire/            Web screen adapters                                        (work package WP6)
  Console/Commands/    Console adapters
  Models/              Eloquent models: data mapping only, no business rules
  Providers/           Service providers
routes/
  web.php              Requires one file per web area from routes/web/
  api/v1.php           The JSON API
database/
  migrations/          Owned by the architect (see "Shared files" below)
  scripts/             Local and CI database setup
docs/
  adr/                 Decisions
  architecture/        Structure, schema, contracts, conventions
  development/         Setup and testing
  handoff/             Work packages
  specs/               Executable specifications (the golden replay)
tests/
  Unit/                Pure tests, no database
  Feature/             Tests through services, HTTP or the database
  Architecture/        Structural rules that fail the build (isolation, layering)
  Acceptance/          Independent acceptance tests (work packages V1 and V2)
  Concerns/            Shared test traits, e.g. RefreshesDatabase
```

Folders marked with an increment or work package don't exist yet. `app/Study/` holds a student's study content: workspaces since M2 step 1, then modules, folders, notes, files and activities (docs/specs/workspaces.md).

## Module responsibilities

| Module | Responsible for | Must not |
|---|---|---|
| **Platform** | Who is acting (`Principal`), permission checks (`Guard`), learner isolation (`LearnerScope`, `LearnerTables`), errors and their JSON form, request IDs, database privileges | Contain business rules of any feature |
| **Identity** | Users, roles, learner records, invitations, 2FA enforcement, password confirmation, workspace switching, the last-admin safeguard, the first-admin and 2FA-reset commands. Builds the `Principal` for each request | Expose any learning content. Admin services have no methods that return it (ADR 0003 §10.4) |
| **Audit** | Writing and listing audit records | Update or delete audit records, or store content or email addresses in them |
| **Appearance** | Theme data and its validation (allowlisted tokens, `#rrggbb` values, complete gradient definitions), the contrast checks of ADR 0003 §6.4 across whole gradients, and writing themes as CSS variables | Hold colour values itself (they live in `resources/themes/` and `resources/brand/`), or accept CSS from anyone |
| **Brain/Journal** | The event vocabulary, entry contracts, time intervals, reference parsing, authority, review rules | Touch the database, the framework or the clock |
| **Brain/Projection** | Deriving learner state from journal entries under `rules@1`, deterministically | Touch the database, call an LLM, or read content text |
| **Brain/Store** | Persisting entries, content and the reference index; learner-scoped reads | Be reached without a `LearnerScope` |
| **Brain/Writer** | Appending entries: validation, positions, idempotency, review refusals | Trust the caller about the actor or the learner |
| **Brain/Redaction** | Redaction, block entries, the outbox clean-up, the redaction ledger, reconciliation after restores | Share a transaction with file deletions |
| **Adapters** (Http, Livewire, Console) | Parsing input, building the `Principal`, calling one service, shaping output | Contain business rules, query learner tables, or skip the service |
| **Models** | Mapping rows to objects | Contain business rules or be used to bypass `LearnerTables` for learner data |

## Dependency rules

- Adapters depend on services. Services never depend on adapters.
- `Brain/Journal` and `Brain/Projection` depend on nothing outside themselves and PHP. They are the part the golden replay tests exercise directly.
- Every module may depend on **Platform**. Platform depends on no other module.
- **Identity** and **Audit** may depend on each other's service interfaces only.
- Services take a `Principal` or a `LearnerScope` argument. They never read the session, the request or the logged-in user themselves. `tests/Architecture/LearnerIsolationTest.php` enforces this for `app/Brain`.
- Queue jobs carry IDs, never text (ADR 0001 day-one rule 5).

## Shared files

Some files are touched by every package. To avoid conflicting edits, each has one owner, and other packages ask for changes in their pull request description (or a `contract-change` issue) rather than editing them directly:

| File | Owner |
|---|---|
| `composer.json`, `composer.lock`, `package.json` | Architect |
| `bootstrap/app.php`, `config/*.php`, `phpunit.xml`, `.env.example` | Architect |
| `database/migrations/*` | Architect. Other packages propose migrations in their PR; the architect reviews them before merge |
| `app/Platform/**` | Architect |
| `routes/web.php`, `routes/api/v1.php` | Architect. Web areas live in their own files under `routes/web/`, each owned by one package |
| `.github/workflows/*` | Architect |
| `package.json`, `package-lock.json`, `vite.config.js`, `playwright.config.js`, `scripts/*` | Architect |
| `DESIGN.md`, `resources/themes/*`, `resources/brand/*`, `public/brand/*`, `app/Appearance/**`, `resources/css/**`, `resources/js/**`, `resources/icons/*` | Architect. The visual foundation every screen shares; changes to `DESIGN.md` go through the PM |
| `docs/architecture/*`, `docs/handoff/*` | Architect. Anyone may propose changes |

Everything else belongs to exactly one work package ([m1-work-packages.md](../handoff/m1-work-packages.md)).
