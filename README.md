# ViStud

A persistent study brain and study platform. Students keep notes, courses and a
calendar; the brain records what they learn in an append-only journal and
derives where each topic stands, independent of any AI model.

## Where to start

| You want to… | Read |
|---|---|
| Understand the decisions | [ADR 0001](docs/adr/0001-permanent-store-and-retrieval-index.md) (storage and retrieval), [ADR 0002](docs/adr/0002-learning-event-schema.md) (learning events), [ADR 0003](docs/adr/0003-web-workspaces-and-study-content.md) (web workspaces and study content) |
| Set up and run the tests | [docs/development/setup.md](docs/development/setup.md) |
| Find where code belongs | [docs/architecture/modules.md](docs/architecture/modules.md) |
| Build against another package | [docs/architecture/contracts.md](docs/architecture/contracts.md) and [schema.md](docs/architecture/schema.md) |
| Follow the house rules | [docs/architecture/conventions.md](docs/architecture/conventions.md) |
| See the M1 work packages | [docs/handoff/m1-work-packages.md](docs/handoff/m1-work-packages.md) |

## Quick start

```bash
sudo mysql < database/scripts/local-mysql-users.sql   # once per machine
composer setup
composer test
```
