# Acceptance tests

Independent acceptance tests for the M1 gate (docs/handoff/m1-work-packages.md):

- `Brain/`: the golden replay and its edge cases (work package V1), written
  from docs/specs/golden-replay-sql-joins.md and ADR 0002 alone.
- `Security/`: T1–T10 from ADR 0003 §10.5 (work package V2).

Rules:

- Validators write these tests. Implementers never edit them.
- Disagreements with an ADR or spec go to the PM, not into the test.
- Tests here may land before the code passes them. CI runs this suite in a
  separate job that reports without blocking, until the PM declares the M1
  gate; then it blocks like every other suite.
- Tests that touch the database use `Tests\Concerns\RefreshesDatabase`.
