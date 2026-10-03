# Golden replay: SQL JOINs

This is the reference scenario for [ADR 0002](../adr/0002-learning-event-schema.md). It is an executable test, `tests/Acceptance/Brain/GoldenReplayTest.php`: the test feeds the entries below to the projector in `position` order and checks the expected state at every checkpoint. The edge cases at the end of this document have their own test files, which are named there.

## Fixture settings

| Setting | Value |
|---|---|
| Learner | `L1` |
| Time zone | Europe/London. Clocks go back on Sun 25 Oct 2026. All times below are local. In the entry format, every time carries its offset (`+01:00` before the change, `+00:00` after) and every entry sets `tz: Europe/London` |
| Rules and policy | `rules@1`, `policy@1` |
| Precision | Every observation has precision `exact` unless stated otherwise |
| Learner's own claims | Method `person` (id `L1`), accepted |

**Claim methods used in this fixture:**

| Method kind | Name and version |
|---|---|
| `rule` | `question-resolver@1`, `task-resolver@1` |
| `interpreter` | `local-interpreter@1`, with the prompts `transcript-emphasis@1`, `judge-exposure@1`, `diagnose@1` and `judge-attempt@1` |

Capture outcomes marked "auto" come from the lab checker, recorded as `checker: {id: lab-checker, version: 1, key_source: course_material}` (ADR 0002 §4 requires a trusted checker for `judged_by: auto`). Claims marked accepted without a named reviewer were accepted by `policy@1`.

## Setup: positions 1–34, Mon 12 Oct 2026, 20:00, by the learner

| Pos | ID | Entry |
|---|---|---|
| 1 | A-L7 | Activity record: lecture "Lecture 7", Tue 13 Oct 10:00–11:00 |
| 2 | A-LAB4 | Activity record: lab "Lab 4", Thu 15 Oct 14:00–16:00 |
| 3 | A-Q1711 | Activity record: quiz, Tue 17 Nov |
| 4 | A-P25 | Activity record: past paper 2025 |
| 5–10 | TK-Q3, TK-Q4, TK-Q5, TK-QZ2, TK-P6A, TK-P6B | Task records with keys `A-LAB4/q3`, `A-LAB4/q4`, `A-LAB4/q5`, `A-Q1711/q2`, `A-P25/q6a`, `A-P25/q6b` |
| 11–15 | K1–K5 | Define the topics T-JOIN, T-INNER, T-LEFT, T-NULL and T-LFILTER ("Filtering a LEFT JOIN: ON vs WHERE") |
| 16–20 | K6–K10 | T-INNER *part_of* T-JOIN · T-LEFT *part_of* T-JOIN · T-LFILTER *part_of* T-LEFT · T-NULL *prerequisite_of* T-LEFT · T-INNER *contrasts_with* T-LEFT |
| 21–23 | K11–K13 | A-L7 *covers* T-JOIN, T-INNER and T-LEFT |
| 24–34 | K14–K24 | *exercises*: TK-Q3, TK-Q4, TK-Q5 and TK-QZ2 each exercise T-LEFT and T-LFILTER. TK-P6A exercises T-LEFT. TK-P6B exercises T-LEFT and T-LFILTER |

## Timeline

Sessions:

| Session | Channel | Client |
|---|---|---|
| S0, S2, S4, S6, S7 | web | learner |
| S1, S3, S5, S8 | mcp | `chatgpt` |

Chat observations are `first_hand`.

| Pos | ID | Local time | Session | Entry |
|---|---|---|---|---|
| 35 | E1 | Tue 13 Oct 10:00–11:00. Recorded 11:05 | S0 | `exposure`: lecture, activity A-L7, about T-JOIN, T-INNER and T-LEFT. Origin `reported` |
| 36 | SRC-7 | 11:20 | — | Source record: recording of Lecture 7 |
| 37 | X-7 | 11:50 | — | Extraction record: transcript of SRC-7, by `local-transcriber@1` |
| 38 | C1 | 12:00 | — | `relates`: A-L7 *emphasizes* T-LEFT {exam_relevant}. Derived from `source:SRC-7#00:34:10`. Interpreter (`transcript-emphasis@1`), 0.9. **Pending** |
| 39 | C2 | 12:30 | — | `reviews`: learner accepts C1 |
| 40 | E2 | 19:40 | S1 | `self_report` confused, about T-LEFT and T-INNER |
| 41 | E3 | 19:41 | S1 | `question`: "Why does LEFT JOIN give me rows full of NULLs?" About T-LEFT |
| 42 | C3 | 19:41 | — | `defines` Q1 (`question-resolver@1`) |
| 43 | C4 | 19:41 | — | `refers_to`: E3 → Q1 (resolver, 1.0) |
| 44 | C5 | 19:41 | — | `relates`: Q1 *stems_from* T-LEFT (resolver, 0.86) |
| 45 | E4 | 19:43 | S1 | `exposure`: explanation by the chat AI, approaches analogy and worked example, about T-LEFT, responding to E3 |
| 46 | E5 | 19:44 | S1 | `self_report` clicked, about Q1, triggered by E4 |
| 47 | C6 | 20:14 | — | `judges` E4: answered Q1 fully; helped Q1 (`judge-exposure@1`, 0.8) |
| 48 | E6 | Thu 15 Oct 14:12 | S2 | `attempt` on TK-Q3: apply, unaided, lab, **incorrect** (auto) |
| 49 | E7 | 14:20 | S2 | `attempt` on TK-Q4: apply, unaided, lab, **incorrect** (auto) |
| 50 | C7 | 14:50 | — | `defines` M1 {topics: T-LFILTER} (`diagnose@1`, 0.8). Accepted by `policy@1`: exhibits on 2 distinct tasks |
| 51 | C8 | 14:50 | — | `judges` E6: T-LEFT correct, T-LFILTER incorrect, M1 present, cause misconception (`judge-attempt@1`, 0.85) |
| 52 | C9 | 14:50 | — | `judges` E7: same facets as C8 |
| 53 | E8 | 15:20 | S3 | `question`: "Why do customers with no orders disappear from my results?" About T-LFILTER |
| 54 | C10 | 15:20 | — | `defines` Q2 (`question-resolver@1`). Q1 scored only 0.62, so this is a new question |
| 55 | C11 | 15:20 | — | `refers_to`: E8 → Q2 (resolver, 1.0) |
| 56 | C12 | 15:20 | — | `relates`: Q2 *stems_from* M1 (resolver, 0.78) |
| 57 | E9 | 15:22 | S3 | `exposure`: feedback by the chat AI, approaches contrast and visual, about T-LFILTER, responding to E8 |
| 58 | E10 | 15:41 | S4 | `attempt` on TK-Q5, the first attempt on this task: apply, unaided, lab, **correct** (auto) |
| 59 | E11 | 15:45 | S4 | `attempt` on TK-Q3: **correct** (auto). This is an **immediate repeat**: the same day as E6, and 23 minutes after teaching |
| 60 | C13 | 15:52 | — | `relates`: E9 *addresses* M1 (interpreter, 0.85) |
| 61 | C14 | 15:52 | — | `judges` E9: answered Q2 fully (`judge-exposure@1`, 0.75) |
| 62 | C15 | 16:15 | — | `judges` E10: T-LEFT and T-LFILTER correct, M1 absent |
| 63 | C16 | 16:15 | — | `judges` E11: correct, M1 absent |
| 64 | C17 | 16:15 | — | `judges` E9: helped M1 (0.6) |
| 65 | TK-C1 | Thu 22 Oct 19:04 | — | Task record created by capture from the chat prompt "Explain in your own words how a filter affects a LEFT JOIN." |
| 66–67 | K25–K26 | 19:04 | — | TK-C1 *exercises* T-LEFT and T-LFILTER (`task-resolver@1`, 0.9) |
| 68 | E12 | 19:05 | S5 | `attempt` on TK-C1: **explain**, unaided, chat, correct (judged by AI) |
| 69 | TK-C2 | 19:11 | — | Task record from the prompt "List every product, including never-ordered ones, with its number of March orders." |
| 70–71 | K27–K28 | 19:11 | — | TK-C2 *exercises* T-LEFT and T-LFILTER (`task-resolver@1`, 0.9) |
| 72 | E13 | 19:12 | S5 | `attempt` on TK-C2: apply, unaided, chat, correct (judged by AI) |
| 73 | C18 | 19:42 | — | `judges` E12: own words, T-LEFT and T-LFILTER correct, M1 absent, **demonstrates Q1 and Q2** |
| 74 | C19 | 19:42 | — | `judges` E13: T-LEFT and T-LFILTER correct, M1 absent |
| 75 | E14 | Tue 17 Nov 18:05 | S6 | `attempt` on TK-QZ2: apply, unaided, quiz, correct (auto) |
| 76 | C20 | 18:35 | — | `judges` E14: correct, M1 absent |
| 77 | E15 | Wed 3 Feb 2027 19:35 | S7 | `attempt` on TK-P6A: apply, unaided, practice, correct (auto) |
| 78 | E16 | 19:42 | S7 | `attempt` on TK-P6B: apply, unaided, practice, **incorrect** (auto) |
| 79 | E17 | 19:43 | S7 | `self_report` forgot, about T-LFILTER |
| 80 | C21 | 20:13 | — | `judges` E15: T-LEFT correct |
| 81 | C22 | 20:13 | — | `judges` E16: T-LEFT correct, T-LFILTER incorrect, M1 present, cause forgot. Derived from E16 and E17 |
| 82 | E18 | 20:15 | S8 | `question`: "Why does putting the date condition in WHERE drop rows again?" |
| 83 | C23 | 20:15 | — | `refers_to`: E18 → Q2 (resolver, 0.88). Accepted and reversible |
| 84 | E19 | 20:17 | S8 | `exposure`: feedback by the chat AI, approaches contrast and visual, about T-LFILTER, responding to E18 |
| 85 | C24 | 20:47 | — | `relates`: E19 *addresses* M1 (interpreter, 0.9) |
| 86 | C25 | 20:47 | — | `judges` E19: answered Q2 fully |

## Checkpoints

Each checkpoint takes the belief view: every entry up to that position, with "now" set to the time shown. Anything not listed is unchanged since the previous checkpoint. T-LEFT carries `exam_relevant` from checkpoint 2 onwards, including where a row doesn't repeat it.

**PM-approved clarifications (WP4 review).** These follow ADR 0002 §7 as clarified at the same time, and are reflected in the rows below:
- **`underconfident` on T-LEFT from checkpoint 6.** E2 (confused, about T-LEFT) is followed by E6 and E7, which are qualifying successes on T-LEFT: C8 and C9 judge T-LEFT correct, even though the overall outcomes are incorrect. Qualifying topic-specific successes count whatever the overall outcome.
- **`practised` where it is satisfied, within the current window.** From checkpoint 10, T-LEFT and T-LFILTER have qualifying successes in S4, S5 and S6, spanning 15 Oct to 17 Nov. After T-LFILTER's regression at E16 (checkpoint 12), its window restarts and it is no longer practised; T-LEFT has no regression and stays practised.
- **`needs_review` needs a gap strictly longer than the review interval**, measured from the upper bound of the latest contact's interval (A5).

| CP | Pos | Now | Topics | M1 | Q1 | Q2 |
|---|---|---|---|---|---|---|
| 1 | 35 | Tue 13 Oct 11:05 | JOIN, INNER, LEFT: **introduced**. LFILTER: **introduced** (through its parent). NULL: **not_started** | — | — | — |
| 2 | 39 | 12:30 | LEFT: introduced, exam_relevant | — | — | — |
| 3 | 44 | 19:41 | — | — | **open** | — |
| 4 | 47 | 20:14 | LEFT: introduced, claimed_only, exam_relevant | — | **resolved · learner-confirmed** | — |
| 5 | 49 | Thu 15 Oct 14:21 | LFILTER: **developing**. LEFT: **developing** (attempted, not yet judged, because failures are blamed on the most specific topic first) | — | — | — |
| 6 | 52 | 14:50 | LEFT: **working**, weak_part, includes_ai_judged, exam_relevant, underconfident. LFILTER: **developing** | **recurring** | — | — |
| 7 | 61 | 15:52 | LFILTER: **developing**. E10 is a qualifying success, but M1 is still active. LEFT: **working**, weak_part, underconfident; includes_ai_judged is cleared because E10 was checked by auto | **addressed** | — | **answered** |
| 8 | 64 | 16:15 | LFILTER: **developing**. LEFT: **working**, weak_part, underconfident | **addressed**, 1 counter. E11 is an immediate repeat, so it doesn't count | — | **answered**. E10 overlaps Q2's topics but isn't specific to the question |
| 9 | 74 | Thu 22 Oct 19:42 | LFILTER: **secure**, includes_ai_judged. LEFT: **secure**, includes_ai_judged, underconfident | **apparently_resolved**, from counters on TK-Q5, TK-C1 and TK-C2 | **resolved · demonstrated** | **resolved · demonstrated** |
| 10 | 76 | Tue 17 Nov 18:35 | LFILTER: **durable**, practised. LEFT: **durable**, practised, underconfident. includes_ai_judged is cleared on both, because TK-Q5 and TK-QZ2 were checked by auto. Both are now practised: qualifying successes in S4, S5 and S6, 15 Oct to 17 Nov | **resolved_retained** | — | — |
| 11 | 76 | Wed 20 Jan 2027 12:00 | LFILTER: **durable**, needs_review, practised. LEFT: **durable**, needs_review, practised, underconfident | — | — | — |
| 12 | 79 | Wed 3 Feb 19:43 | LFILTER: **developing**, regressed. The regression starts at E16, whose cause isn't judged yet; the window restarts there, so LFILTER is no longer practised. LEFT: **durable**, weak_part, practised, underconfident | resolved_retained (no exhibit judged yet) | — | — |
| 13 | 86 | Wed 3 Feb 20:47 | LFILTER: **developing**, regressed. LEFT: **durable**, weak_part, exam_relevant, practised, underconfident | **addressed**, resurfaced 1 | **resolved · demonstrated** | **answered**, resurfaced 1 |

## Reconstruction assertions

**A1. What happened.** Every observation, E1–E19, in `(lo, position)` order.

**A2. What the learner said about themselves.** Confused (E2), clicked on Q1 (E5), forgot LFILTER (E17). The learner's actual SQL answers are the stored content of E6, E7 and E16.

**A3. What was inferred.** Claims C1–C25, each with its method, confidence and review. For example, M1 came from C7 and was accepted under `policy@1` because its exhibits were on 2 distinct tasks.

**A4. What is unresolved at checkpoint 13.**
- M1 is active: addressed, resurfaced once.
- Q2 is answered but not resolved.
- LFILTER is regressed.
- NULL is `not_started`: it is a prerequisite of LEFT, but it has never been tested.

**A5. How understanding changed, in the current view.** This view uses every claim, but only the observations that occurred up to each moment. LFILTER's label changed like this:

| Time | LFILTER |
|---|---|
| Tue 13 Oct 10:00 | introduced |
| Thu 15 Oct 14:12 | developing |
| Thu 22 Oct 19:05 | **working** |
| 19:12 | secure |
| Tue 17 Nov 18:05 | durable |
| Sat 16 Jan 2027, just after 18:05 | durable, needs_review |
| Wed 3 Feb 19:42 | developing, regressed |

The table shows the label, with `needs_review` and `regressed` where they apply; other flags are omitted. **needs_review boundary (PM-approved clarification):** E14's interval ends at 18:05 on 17 Nov, and the review interval for `durable` is 60 days. At exactly 18:05 on 16 Jan the gap equals the interval, so `needs_review` is false; at any later moment, even one second later, it is true. The rule has no rounding to minutes.

The belief view in the checkpoints never shows "working" for LFILTER. The verdicts on E12 and E13 were both recorded at 19:42, so it jumped straight from checkpoint 8 to checkpoint 9. The current view places each piece of evidence at the time it occurred: E12 alone (together with E10) resolves M1 at 19:05, and E13 makes the topic secure.

**A6. What the brain believed at earlier moments.** Replaying up to a position gives these, and later arrivals never change them:
- At position 47: Q1 was resolved · learner-confirmed, and LEFT was claimed_only.
- At position 49: LFILTER was developing, and no misconception existed yet.

## Variants

**V1. Undoing an automatic question match.** Added after position 86:
- C26: the learner *rejects* C23. This is not a performance evaluation, so the rejection is allowed.
- C27: `defines` Q3.
- C28: E18 *refers_to* Q3.

Expected results:
- Q2 is resolved · demonstrated, with resurfaced 0.
- Q3 is being_answered: E19 responded to its opening ask, and has no verdict for Q3.
- The projection reports the rejected pair (E18, Q2), so the resolver never proposes it again.

**V2. An event that arrives late, known only to the day.** E13's tool call failed. On Fri 23 Oct 08:30 the chat model retries with `when: yesterday`. Positions 69–72 and 74 are removed, and these are appended after position 74:
- Task TK-C2 and K27–K28, recorded at 08:29.
- E13: occurred Thu 22 Oct, precision `day`. Its interval is 00:00 on 22 Oct to 00:00 on 23 Oct. Recorded at 08:30 in session S9.
- C19: recorded at 09:00.

Expected results:
- **Belief view at C18 (Thu 22 Oct 19:42).** LFILTER is **working**, not secure, because there is only one apply task. M1 is apparently_resolved, from counters on TK-Q5 and TK-C1.
- **After C19.** LFILTER is **secure**.
- **Ordering.** E13 is placed at the start of its day, before E12. It is still after the latest exhibit, E7, so it counts as a counter.
- **Retention at E14.** This uses E13's interval end, 00:00 on 23 Oct. The guaranteed gap is 25 days 19 hours, so checkpoint 10 is still **durable**.

**V3. Redaction.** This variant runs as a database test, `tests/Acceptance/Brain/RedactionTest.php`. On 16 Oct, `E6.content.answer` is redacted with reason `secret`.

Expected results:
- **Unchanged:** the envelope and body of E6 remain, and derived state is identical at every checkpoint.
- **Removed:** the answer text, and any rationale content in C8.
- **Blocked:** a block entry exists for E6, and the learner's cache version has gone up.
- **Queued clean-up tasks:** delete E6's retrieval point, and regenerate summaries.

**V4. The retention boundary with a day-precision event.** Added after position 74: the learner imports a result from their school's online learning platform.
- Task TK-LMS1, which exercises T-LEFT and T-LFILTER.
- An attempt on TK-LMS1: apply, unaided, practice, correct, judged `auto` by the platform. Origin `reported`, occurred on Thu 12 Nov with precision `day`, recorded on Sat 14 Nov.

Expected results:
- **The imported attempt is not retained.** The guaranteed gap from E13 is 00:00 on 12 Nov minus 19:12 on 22 Oct, which is 20 days 5 hours 48 minutes, under 21 days. If the attempt had happened that evening, it would have passed; uncertainty doesn't earn the benefit.
- **E14 is not retained either.** Its previous contact is now the imported attempt, whose interval ends at 00:00 on 13 Nov.
- **At checkpoint 10,** LFILTER is **secure**, not durable, and M1 stays **apparently_resolved**.

**V5. A resolved question reopened by a self-report.** Added after position 74:
- E20, Fri 23 Oct 21:00, session S9: `self_report` unsure, about Q2: "Actually I'm not sure again why the rows vanish." There is no new ask.
- E21, 21:02: an `exposure` of feedback by the chat AI, responding to E20.

Expected results:
- **After E20,** Q2 is **open**, flagged `reopened`, with resurfaced 1.
- **After E21,** Q2 is **being_answered**.
- **LFILTER stays secure.** Self-reports never change labels.

## Edge-case tests

### Task identity (`tests/Acceptance/Brain/TaskIdentityTest.php`)

| Case | Setup | Expected |
|---|---|---|
| X1 | The same task solved correctly on day 1 and again on day 3 (a delayed repeat), plus a correct explanation | **working**, not secure: only 1 distinct apply task. Adding a second task on day 5 makes it **secure** |
| X2 | A task failed, then taught, then the same task answered correctly 20 minutes later | The retry is immediate, so the topic stays **developing** |
| X3 | A regression on day 30, teaching on day 31, then the same failed task answered correctly on day 33 in a new session | This is a delayed repeat, so the topic **recovers to working** and the regressed flag clears. An immediate retry on day 30 would not recover it |
| X4 | The same task solved on day 1 and on day 25, with nothing in between | The day-25 attempt is **retained**. By contrast, weekly successes on days 1, 8, 15 and 22 are **practised**, never retained |
| X5 | Two task records merged with `same_as` | Their attempts count as one task. If the merge is rejected, they count as two |

### Disputes (`tests/Acceptance/Brain/DisputeTest.php`)

| Case | Setup | Expected |
|---|---|---|
| D1 | The chat AI records an attempt as correct, and the interpreter judges it incorrect. The learner tries to **reject** the interpreter's verdict | The writer refuses the rejection, and the projection ignores it if it's in the journal anyway. The overall outcome is incorrect; the topic is **developing** |
| D2 | The learner **disputes** the interpreter's verdict instead | Overall and topic outcomes are `disputed`. The topic stays **developing**: the chat AI's "correct" is **not** restored |
| D3 | The learner withdraws the dispute | The outcome is incorrect again |
| D4 | After a new dispute, three later verdicts arrive: one from the interpreter that doesn't cite the dispute, one from the chat AI that does, and one from the interpreter that does | Only the interpreter verdict citing the dispute settles it. With that verdict saying "correct", the topic becomes **working** |
| D5 | An auto-checked "incorrect" outcome is disputed | An interpreter adjudication is ignored, because the interpreter ranks below auto. A re-run of the checker citing the dispute settles it |
| D6 | An active misconception blocks a topic that has a qualifying success, and the learner disputes the misconception's definition | The misconception becomes `disputed`, and the topic becomes **working** with the flag `rests_on_dispute`. A new exhibit, on an attempt made after the dispute, makes the misconception active again, and the topic goes back to **developing** |

### Redaction and restore (`tests/Acceptance/Brain/RedactionTest.php`)

| Case | Setup | Expected |
|---|---|---|
| R1 | V3 above | As described in V3 |
| R2 | Redacting a source file while file storage fails twice | Opening the file is refused **immediately**. Deletions retry after 1 minute, then after 5 minutes. The third attempt succeeds, is verified, and completes the redaction |
| R3 | A restore that brings back redacted content and loses the redaction rows | The health check reports that the ledger is ahead of the database. `brain:reconcile-redactions` deletes the content again, restores the block, re-queues clean-up and updates the marker |
