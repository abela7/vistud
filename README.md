# ViStud

A persistent study brain and study platform. Students keep notes, courses and a
calendar; the brain records what they learn in an append-only journal and
derives where each topic stands, independent of any AI model.

## Before you start

**New developers must read [PROJECT.md](PROJECT.md) and [docs/coordination/STATUS.md](docs/coordination/STATUS.md) before starting any work.**

- [PROJECT.md](PROJECT.md) explains what ViStud is, how it is built, how we collaborate, and the order to read everything else in.
- [STATUS.md](docs/coordination/STATUS.md) shows every work package's owner and state, open decisions and blockers. Update only your own package's entry; scope and ownership changes go through the PM.
- **Taking over locally?** [docs/handoff/LOCAL-TAKEOVER.md](docs/handoff/LOCAL-TAKEOVER.md) lists the branches, what works, what is left, and which setup steps have been verified where.

## Where to find things

| You want to… | Read |
|---|---|
| Understand the project | [PROJECT.md](PROJECT.md) |
| See who is doing what | [docs/coordination/STATUS.md](docs/coordination/STATUS.md) |
| Build or change a screen | [DESIGN.md](DESIGN.md): brand, themes and gradients, scales, components and states, responsive rules, accessibility and the checks at UI handoff |
| Understand the decisions | [ADR 0001](docs/adr/0001-permanent-store-and-retrieval-index.md) (storage and retrieval), [ADR 0002](docs/adr/0002-learning-event-schema.md) (learning events), [ADR 0003](docs/adr/0003-web-workspaces-and-study-content.md) (web workspaces and study content) |
| Set up and run the tests | [docs/development/setup.md](docs/development/setup.md) |
| Find where code belongs | [docs/architecture/modules.md](docs/architecture/modules.md) |
| Build against another package | [docs/architecture/contracts.md](docs/architecture/contracts.md) and [schema.md](docs/architecture/schema.md) |
| Follow the house rules | [docs/architecture/conventions.md](docs/architecture/conventions.md) |
| See the M1 work packages | [docs/handoff/m1-work-packages.md](docs/handoff/m1-work-packages.md) |

## Quick start

The supported environment is PHP 8.4 and MySQL 8.4. The broader PHP constraint in `composer.json` does not change that. XAMPP's PHP 8.0 and MariaDB installation does not meet the baseline. Full instructions: [docs/development/setup.md](docs/development/setup.md).

The database command is a **Unix shell** command. It uses input redirection and is not a PowerShell command. A Windows procedure has not been verified.

```bash
# Unix shell only (bash). Not PowerShell.
sudo mysql < database/scripts/local-mysql-users.sql   # once per machine
composer setup
npm ci && npm run build                               # front-end assets
composer test
```

Process-forking concurrency tests need a Unix environment with `pcntl` and `posix`. A skipped concurrency test on Windows does not satisfy the M1 gate.
