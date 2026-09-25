# Golden replay: SQL JOINs

This is the reference scenario for [ADR 0002](../adr/0002-learning-event-schema.md). It will become an automated test. The test feeds in the events and claims below, in order of `position`, then checks the expected state at each checkpoint.

**Fixture settings**

| Setting | Value |
|---|---|
| Learner | `L1` |
| Time zone | Europe/London. Clocks change on 25 Oct 2026; all times below are local |
| Rules | `rules@1` |
| Acceptance policy | `policy@1` |
| When a session closes | 30 minutes after its last event. The interpreter runs at that moment |

## Setup

Everything here was recorded on Mon 12 Oct 2026 by the learner (method `person`, accepted), unless a different time is given.

**Topics** (`defines` claims):

| ID | Topic |
|---|---|
| T-JOIN | SQL JOIN |
| T-INNER | INNER JOIN |
| T-LEFT | LEFT JOIN |
| T-NULL | NULL semantics |
| T-LFILTER | Filtering a LEFT JOIN (ON vs WHERE) |

**Relations** (`relates` claims):

| From | Relation | To |
|---|---|---|
| T-INNER | part_of | T-JOIN |
| T-LEFT | part_of | T-JOIN |
| T-LFILTER | part_of | T-LEFT |
| T-NULL | prerequisite_of | T-LEFT |
| T-INNER | contrasts_with | T-LEFT |
| A-L7 | covers | T-JOIN, T-INNER, T-LEFT |

**Activity records:**

| ID | Activity | Tasks and their topics |
|---|---|---|
| A-L7 | Lecture 7, Tue 13 Oct 10:00–11:00 | — |
| A-LAB4 | Lab 4, Thu 15 Oct 14:00–16:00 | q1–q2 cover T-INNER; q3–q5 cover T-LEFT and T-LFILTER |
| A-Q1711 | Practice quiz, Tue 17 Nov | q2 covers T-LEFT and T-LFILTER |
| A-P25 | 2025 past paper | q6a covers T-LEFT; q6b covers T-LEFT and T-LFILTER |

**Source records:**
- SRC-7: recording of Lecture 7, recorded Tue 11:20.
- X-7: transcript of SRC-7, produced by the local transcriber and recorded Tue 11:50.

## Events and claims

| # | Time | Session | What was recorded |
|---|---|---|---|
| E1 | Tue 13 Oct 10:00 (recorded 11:05) | S0 web | `exposure`: lecture, A-L7, by lecturer, origin `reported`, about T-JOIN, T-INNER, T-LEFT (copied from the lecture's covered topics) |
| C1 | Tue 12:00 | — | `relates`: A-L7 *emphasizes* T-LEFT {exam_relevant}. Based on X-7 at 00:34:10. Method: interpreter (transcript analyser), confidence 0.9. **Pending** |
| C2 | Tue 12:30 | — | `reviews`: accepts C1 (learner) |
| E2 | Tue 19:40 | S1 ChatGPT | `self_report` confused, about T-LEFT and T-INNER, origin `first_hand`. "I'm confused about LEFT vs INNER JOIN, the NULL rows thing." |
| E3 | Tue 19:41 | S1 | `question`: "Why does LEFT JOIN give me rows full of NULLs?" |
| C3–C5 | Tue 19:41 | — | By the question resolver (rule), accepted: define Q1 ("Why does a LEFT JOIN produce rows filled with NULLs?"); E3 *refers_to* Q1 (1.0); Q1 *stems_from* T-LEFT (0.86) |
| E4 | Tue 19:43 | S1 | `exposure`: explanation by the chat AI, using an analogy and a worked example, responding to E3. "Left table as a guest list; missing partners show NULL; three-row example." |
| E5 | Tue 19:44 | S1 | `self_report` clicked, about Q1, triggered by E4 |
| C6 | Tue 20:14 | — | `judges` E4 (interpreter, 0.8): fully answered Q1; helped Q1 |
| E6 | Thu 15 Oct 14:12 | S2 web | `attempt` on A-LAB4/q3: apply, unaided, lab, **incorrect** (auto-checked). Answer: `… LEFT JOIN orders o ON c.id = o.customer_id WHERE o.year = 2026` |
| E7 | Thu 14:20 | S2 | `attempt` on A-LAB4/q4: the same pattern, **incorrect** (auto-checked) |
| C7 | Thu 14:50 | — | `defines` M1 on T-LFILTER: "Believes a WHERE condition on a right-table column keeps the unmatched left rows." Interpreter, 0.8. Accepted under `policy@1`, because it appeared on two different tasks |
| C8, C9 | Thu 14:50 | — | `judges` E6 and E7 (interpreter): T-LEFT correct; T-LFILTER incorrect; M1 present; cause: misconception |
| E8 | Thu 15:20 | S3 ChatGPT | `question`: "Why do customers with no orders disappear from my results?" |
| C10–C12 | Thu 15:20 | — | By the resolver, accepted: closest match was Q1 at 0.62, so it defines Q2 ("Why do unmatched rows disappear when a LEFT JOIN is filtered?"); E8 *refers_to* Q2; Q2 *stems_from* M1 (0.78) |
| E9 | Thu 15:22 | S3 | `exposure`: feedback by the chat AI, using contrast and visuals, responding to E8. "Before/after tables: NULL rows fail the WHERE; move the condition into ON." |
| E10 | Thu 15:41 | S4 web | `attempt` on q5 (first try): apply, unaided, lab, **correct** (auto-checked) |
| E11 | Thu 15:45 | S4 | `attempt` on q3 (**a retry**): **correct** (auto-checked) |
| C13, C14 | Thu 15:52 | — | Interpreter, accepted: E9 *addresses* M1 (0.85); `judges` E9 as fully answering Q2 (0.75) |
| C15–C17 | Thu 16:15 | — | `judges` E10: T-LEFT and T-LFILTER correct, M1 absent. `judges` E11: correct, M1 absent. `judges` E9: helped M1 (0.6) |
| E12 | Thu 22 Oct 19:05 | S5 ChatGPT | `attempt` on task chat:E12: **explain**, unaided, chat, correct (judged by AI). "LEFT JOIN keeps every row from the left table; where there's no match the right-side columns are NULL. A WHERE filter on a right-table column throws those NULL rows away, so that filter belongs in ON." |
| E13 | Thu 19:12 | S5 | `attempt` on task chat:E13: apply, a new problem, unaided, correct (judged by AI) |
| C18, C19 | Thu 19:42 | — | `judges` E12: own words; T-LEFT and T-LFILTER correct; M1 absent; **demonstrates Q1 and Q2**. `judges` E13: correct; M1 absent |
| E14 | Tue 17 Nov 18:05 | S6 web | `attempt` on A-Q1711/q2: apply, unaided, quiz, **correct** (auto-checked) |
| C20 | Tue 18:35 | — | `judges` E14: correct; M1 absent |
| E15 | Wed 3 Feb 2027 19:35 | S7 web | `attempt` on A-P25/q6a: apply, unaided, practice, **correct** (auto-checked) |
| E16 | Wed 19:42 | S7 | `attempt` on A-P25/q6b: apply, unaided, practice, **incorrect** (auto-checked). The WHERE placement again |
| E17 | Wed 19:43 | S7 | `self_report` forgot, about T-LFILTER. "I forgot where the filter goes." |
| C22, C23 | Wed 20:13 | — | `judges` E15: T-LEFT correct. `judges` E16: T-LEFT correct; T-LFILTER incorrect; M1 present; cause: forgot (based on E16 and E17) |
| E18 | Wed 20:15 | S8 ChatGPT | `question`: "Why does putting the date condition in WHERE drop rows again?" |
| C21 | Wed 20:15 | — | E18 *refers_to* Q2 (0.88). Accepted under `policy@1`; **can be reversed** |
| E19 | Wed 20:17 | S8 | `exposure`: feedback by the chat AI, using contrast and visuals, responding to E18 |
| C24, C25 | Wed 20:47 | — | E19 *addresses* M1 (0.9). `judges` E19 as fully answering Q2 |

## Expected state at each checkpoint

Each checkpoint shows the state as of that moment, counting only what had been recorded by then. Anything not listed is unchanged since the previous checkpoint.

| CP | As of | Topics | M1 | Q1 | Q2 |
|---|---|---|---|---|---|
| 1 | Tue 13 Oct 11:05 | JOIN, INNER and LEFT **introduced**. LFILTER **introduced** (through its parent, LEFT). NULL **not_started** | — | — | — |
| 2 | Tue 12:30 | LEFT **introduced**, exam_relevant | — | — | — |
| 3 | Tue 19:41 | LEFT unchanged | — | **open** | — |
| 4 | Tue 20:14 | LEFT **introduced**, claimed_only, exam_relevant. Profile: analogy plus worked example helped once | — | **resolved: learner-confirmed** | — |
| 5 | Thu 15 Oct 14:21 | LFILTER **developing** (a failure is blamed on the most specific topic). LEFT **developing** (attempted, but its outcome not yet judged) | — | unchanged | — |
| 6 | Thu 14:50 | LEFT **working**, weak_part, includes_ai_judged, exam_relevant. LFILTER **developing** | **recurring** | unchanged | — |
| 7 | Thu 15:52 | LFILTER **developing**: it has a qualifying success (E10), but M1 is still active. LEFT **working**, weak_part (includes_ai_judged cleared, because E10 is auto-checked) | **addressed** | unchanged | **answered** |
| 8 | Thu 16:15 | LFILTER **developing**. LEFT **working**, weak_part. Profile: contrast plus visuals helped once | **addressed**, with 1 counter (E11 is a retry, so it doesn't count) | unchanged | **answered**. E10 overlaps the topic but isn't specific to the question, so it doesn't resolve Q2 |
| 9 | Thu 22 Oct 19:42 | LFILTER **secure**, includes_ai_judged. LEFT **secure**, includes_ai_judged (weak_part cleared) | **apparently_resolved** (counters on q5, chat:E12 and chat:E13, in sessions after S2) | **resolved: demonstrated** (E12) | **resolved: demonstrated** (E12) |
| 10 | Tue 17 Nov 18:35 | LFILTER **durable** (26 days since last contact, no teaching recorded). LEFT **durable**. includes_ai_judged cleared on both (q5 and the quiz are auto-checked) | **resolved_retained** | unchanged | unchanged |
| 11 | Wed 20 Jan 2027 12:00 (no new events) | LFILTER and LEFT **durable**, needs_review (since 16 Jan: 60 days after last contact). NULL **not_started**, reported as untested | unchanged | unchanged | unchanged |
| 12 | Wed 3 Feb 19:43 | LFILTER **developing**, regressed (the regression starts at E16; the cause isn't judged yet, so it counts as a real failure). LEFT **durable**, weak_part (E15 retained after 78 days) | unchanged: no exhibit judged yet | unchanged | unchanged |
| 13 | Wed 20:47 | LFILTER **developing**, regressed. LEFT **durable**, weak_part, exam_relevant | **addressed**, resurfaced 1 time | **resolved: demonstrated** | **answered**, resurfaced 1 time (open at 20:15, being_answered at 20:17) |

## Reconstruction assertions

**A1. What happened.** Observations E1–E19, in order of `(occurred_at, position)`, with the learner's exact words.

**A2. What the learner believed, in their own words.**
- E2: confused about LEFT vs INNER.
- E5: it clicked (Q1).
- E17: forgot (LFILTER).
- The WHERE placement written in E6, E7 and E16.

**A3. What the system inferred.** Claims C1–C25, plus the setup claims. Each carries its method, confidence and review. For example, M1 was created by C7 (the interpreter) and accepted under `policy@1` because it appeared on two different tasks (q3 and q4).

**A4. What is unresolved at checkpoint 13.**
- M1 is active: addressed, and resurfaced once.
- Q2 has been answered but not resolved.
- LFILTER is regressed.
- NULL is `not_started`. It is a prerequisite of LEFT, but it has never been tested, and briefs describe it as unknown.

**A5. How understanding changed over time.** This uses the current view, where each change is placed at the time the evidence that caused it occurred. LFILTER went through these stages:

| Date | State |
|---|---|
| Tue 13 Oct 10:00 | introduced |
| Thu 15 Oct 14:12 | developing |
| Thu 22 Oct 19:12 | secure |
| Tue 17 Nov 18:05 | durable |
| Sat 16 Jan 2027 | durable, needs_review |
| Wed 3 Feb 19:42 | developing, regressed |

LFILTER never reached `working` on its own. When it first succeeded, M1 was still active. By the time M1 was resolved, the conditions for `secure` were already met.

**A6. What the brain believed at an earlier time.** Replaying by `position` gives answers that stay fixed however many events arrive later:
- At Tue 13 Oct 20:14: Q1 was resolved (learner-confirmed), and LEFT was claimed_only.
- At Thu 15 Oct 14:21: LFILTER was developing, and no misconception had been identified.

## Variants

**V1. Undoing an automatic question match.**

*Recorded:* after checkpoint 13, at Wed 21:00, the learner records C26, a `reviews` that rejects C21 because the questions are not the same.

*Expected:*
- The pair (E18, Q2) is blocked from being matched again.
- The resolver then creates:
  - C27, which defines Q3 ("Why does a date condition in WHERE drop the unmatched rows?");
  - C28, recording that E18 refers to Q3.
- Q2 goes back to **resolved: demonstrated**, resurfaced 0 times.
- Q3 is **being_answered**. E19 responded to its ask, but the answer hasn't been judged for Q3; C25 judged it for Q2.

**V2. An event that arrives late.**

*Recorded:* suppose E13's tool call failed on 22 Oct, and the chat model retried at Fri 23 Oct 08:30 with `when: yesterday evening`. E13 then has `occurred_at` of 22 Oct (precision: day), is recorded at 23 Oct 08:30, and belongs to a new session, S9. The interpreter re-runs C19 at 09:00.

*Expected:*
- **The current view is unchanged from the main replay.** LFILTER is secure as of 22 Oct.
- **What the brain believed as of 22 Oct 19:42:** LFILTER was **working**, not secure, because it had only one apply task. M1 was already **apparently_resolved** (counters on q5 and chat:E12).
- **From 23 Oct 09:00** the brain believes LFILTER is **secure**.
- **Checkpoint 10 is unchanged.** Retention is measured from E13's `occurred_at`.

**V3. Redaction.**

*Recorded:* on 16 Oct the learner redacts `E6.content.answer` because it contained a connection string with a password that the scanner missed.

*Expected:*
- **What remains:** E6's envelope and body (incorrect, auto-checked).
- **What is removed:**
  - the answer text;
  - its mention offsets;
  - any rationale in C8 that quoted it;
  - E6's point in Qdrant;
  - summaries built from E6, which are rebuilt without it.
- **What else happens:**
  - the brief cache is cleared;
  - an entry is written to the redaction ledger.
- **Derived state at every checkpoint is exactly as in the main replay.** The evidence stands; only the words are gone.
- **Afterwards:**
  - Backups keep the text for up to 35 days.
  - The learner is told to change the password.
