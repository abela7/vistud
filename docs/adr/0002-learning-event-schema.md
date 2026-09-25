# ADR 0002: Learning event schema

- **Status:** PROVISIONAL. It becomes ACCEPTED when both of these hold:
  1. the golden replay in [`docs/specs/golden-replay-sql-joins.md`](../specs/golden-replay-sql-joins.md), together with its edge cases, passes as an automated test against the real projection code;
  2. after six weeks of pilot use, nothing the learner did has needed a workaround to record.
- **Date:** 2026-09-25
- **Revised:** 2026-09-25.
  - Task identity is now separate from attempt identity, and repeats are classified as immediate or delayed.
  - Disputes and rejections follow one set of rules.
  - Times are treated as intervals.
  - A question can be reopened by a self-report.
  - Redaction blocks content immediately, and file clean-up retries until it succeeds.
- **Decision owner:** project owner (project manager and system architect)
- **Depends on:** [ADR 0001](0001-permanent-store-and-retrieval-index.md)

## Context

ADR 0001 made an append-only journal the source of truth. This ADR decides:
- what goes into the journal, and exactly what each claim contains;
- what happens when judgements disagree;
- which rules turn the journal into learner state;
- how external chats capture events, and how redaction works.

The scope is the personal pilot. Anything marked **(later)** is defined now so that the model won't need to change, but it won't be built yet.

## 1. Three layers

| Layer | What it holds | How it changes |
|---|---|---|
| **Observations** | What happened, in the learner's own words where possible | Never edited. It can only be retracted, amended or redacted, and only by an amendment |
| **Claims** | Recorded judgements. Each has a method, an authority, a confidence and a review state | Never edited. A newer claim or a review replaces it |
| **Derived state** | Topic labels, the states of misconceptions and questions, and the learning profile | Recalculated from observations and effective claims by versioned rules. It is never stored as truth |

Two more kinds support these layers:
- **Records** hold things and administrative facts.
- **Amendments** are the only way an observation or a record ever changes.

## 2. Vocabulary

| Kind | Meaning |
|---|---|
| `exposure` | Teaching reached the learner: a lecture, some reading, a video, an explanation, or feedback |
| `attempt` | The learner tried a task and produced something that can be judged |
| `question` | The learner asked something |
| `self_report` | The learner said something about their own state |
| `claim` | Any recorded judgement (see §5) |
| `record` | A thing or an administrative fact |
| `amendment` | Retracts, amends or redacts an earlier observation or record |

Each of the following fields has a fixed list of values owned by the core. Plugins **(later)** may add subtypes, but every subtype must map onto one of these values.

| Field | Values |
|---|---|
| attempt `form` | recognise · recall · apply · explain |
| attempt `support` | unaided · hinted · guided · with_reference · followed_example |
| attempt `setting` | practice · homework · lab · quiz · exam · chat |
| attempt `outcome` | correct · partial · incorrect · unjudged |
| attempt `judged_by` | auto · person · ai · self · none |
| self_report `stance` | confused · unsure · stuck · confident · clicked · forgot |
| exposure `format` | lecture · reading · video · explanation · worked_example · demonstration · feedback |
| exposure `approach` | definition · analogy · visual · worked_example · step_by_step · contrast · counterexample · real_world · questioning |
| claim `type` | defines · refers_to · same_as · splits_into · relates · judges · reviews |
| `relates` relation | prerequisite_of · part_of · contrasts_with · example_of · related_to · covers · emphasizes · stems_from · addresses · exercises |
| `reviews` decision | accept · reject · dispute · withdraw |
| record `type` | source · extraction · activity · task · session · profile · consent |
| amendment `action` | retract · amend · redact |
| observation `origin` | first_hand · reported · material |
| `precision` | exact · minute · day · week |

## 3. Fields every event carries (the envelope)

| Field | Meaning |
|---|---|
| `id` | A UUIDv7. The web app generates it on the device; for external chats, the server generates it (§9) |
| `learner` | The learner stream. In the pilot this is the only kind of stream; `shared:<scope>` comes **(later)** |
| `position` | Assigned by the server when the event is appended, and strictly increasing within a learner. It defines what was recorded before what |
| `kind`, `type`, `type_version` | The kind (§2), plus a specific type such as `core.attempt` and its version |
| `actor` | `{type, id, channel}`. `type` is one of learner · person · chat_client · processor · system. `channel` is one of web · mcp · api · job |
| `origin` | Observations only: first_hand, reported or material |
| `occurred_at`, `occurred_until`, `precision`, `tz` | When it happened in the learner's life, as an **interval** (see below) |
| `recorded_at`, `received_at` | When the device recorded it (not trusted), and when the server received it (trusted) |
| `capture_key` | Optional. Makes resending safe (§9) |
| `session`, `activity` | Grouping |
| `links` | Links made at capture time. `about` points to a topic, question or misconception, with a role of primary or secondary. `cites` points to a source plus a location in it. `responds_to` and `triggered_by` point to another event |
| `mentions` | `[{n, field, start, end}]`: character positions inside a content field. The mentioned text exists only in that content field |
| `body` | The core fields for this kind (§4, §5) |
| `content` | Named free-text fields. They are stored separately, never queried by SQL, and can be redacted |

**Occurrence intervals.**
- **Interval.** Every event occupies an interval `[lo, hi]` of real time:

  | Precision | Interval |
  |---|---|
  | `exact` | `lo = hi = occurred_at`, or `hi = occurred_until` when the event has a duration, such as a lecture |
  | `minute` | That minute |
  | `day` | The learner's local calendar day, in `tz` |
  | `week` | The learner's local ISO week (Monday to Monday) |

- **Order.** Events are ordered by `(lo, position)`.
- **Guaranteed gap.** The guaranteed gap from event A to a later event B is `B.lo − A.hi`. Every "at least X apart" rule uses the guaranteed gap.
- **Possibly between.** An event E is *possibly between* A and B if its interval overlaps the span from `A.lo` to `B.hi`. Every "nothing in between" rule treats a possibly-between event as being in between.
- **Consequence.** Uncertainty about when something happened never makes it easier for a topic to move up a label.

## 4. Observation, record and amendment contracts

### exposure
- **`body`:**
  - `format` (required)
  - `approach[]`
  - `by`: `{type: lecturer · chat_ai · person · self_study, id?}`
  - `duration_min?`
- **`links`:**
  - `about`: the topics taught, required for teaching
  - `activity?`
  - `responds_to?`: the ask, or the reopening self-report, that this answers
  - `cites?`
- **`content`:**
  - `summary`: at most three sentences. Required when the explanation comes from a chat LLM
  - `example?`
- When an exposure belongs to an activity, capture copies the topics that activity covers into `about`.

### attempt
- **`body`:**
  - `task`: the ID of a **task record** (required)
  - `form`, `support`, `setting`, `outcome`, `judged_by`
  - `score?`: `{raw, max}`
- **`content`:**
  - `answer`: the learner's words or code, verbatim after filtering
  - `output?`: what a checker returned, or an error message

### Task identity

A **task** is the problem that was posed. An **attempt** is one try at it.
- **Task records** carry:
  - `key`: a natural key that is unique for the learner, such as `A-LAB4/q3`
  - `activity?`
  - `title?`
  - the task's prompt, stored as `content`
- **The topics a task exercises** are recorded as claims: `relates` *task exercises topic*. They can therefore be corrected like any other claim.
- **Tasks from activities** (labs, quizzes, past papers) come from the activity. **Tasks set in chat** are created by the capture service from the prompt (§9). Trying the same chat task again must reuse that task, never create a new one.
- **Task identity is itself a judgement.** If two task records turn out to be the same problem, a `same_as` claim merges them.

### question
- `links`: `about` (topic hints), `responds_to?`
- `content`: `text`, verbatim

### self_report
- `body`: `stance`
- `links`: `about` at least one topic or question; `triggered_by?`
- `content`: `text?`, verbatim

### record

| Type | Fields |
|---|---|
| `source` | kind, title, storage key, hash |
| `extraction` | source, extractor and its version, version number, segments with their locations |
| `activity` | kind (lecture · lab · assignment · quiz · exam · problem_set), title, `starts_at`, `ends_at?` |
| `task` | key, activity, title, prompt as content |
| `session` | channel, client, `started_at`, `ended_at` |
| `profile` | — |
| `consent` | — |

Every record has a stable `record_id`. A revision appends a new version with the same `record_id`.

### amendment
- **`action`:** retract, amend or redact.
- **`targets`:** the events the amendment applies to.
- **`fields?`:** the content fields to redact, or the fields to replace.
- **`replacement?`:** the new values, when amending.
- **`reason`:** wrong_capture · secret · personal_information · not_learning · learner_request · other.
- An amendment never contains the redacted text.

## 5. Claim contracts

Every claim has this shape:

```yaml
claim:
  type:          defines | refers_to | same_as | splits_into | relates | judges | reviews
  targets:       [ref, ...]
  value:         { ... }
  confidence:    0..1 | null          # null for person and rule claims
  method:        { kind: person | auto | interpreter | chat_ai | self | rule, id, version, prompt? }
  derived_from:  [ref, ...]
  supersedes:    [claim_ref, ...]
  review:        { state: accepted | pending, by?: policy@version | person }
content (optional): rationale | phrasing | statement | definition
```

**References** take these forms:
- `event:<id>` and `claim:<id>`
- `topic:<id>`, `question:<id>`, `misconception:<id>`, `task:<id>` and `activity:<id>`
- `source:<id>#<locator>`
- `mention:<event>/<n>`

**Method kinds:**
- `auto`: a deterministic answer checker, including one re-run to settle a dispute
- `rule`: deterministic system logic, such as the resolver or the acceptance policy
- `interpreter`: our local model
- `chat_ai`: an external chat LLM

### `defines`
Creates or revises a topic, misconception or question.
- **`targets`:** `[entity]`. A new ID is created by the claim itself.
- **`value`:**
  - `entity_type`: topic, misconception or question
  - `status`: active or retired
  - for a topic: `{kind, aliases[]}`
  - for a misconception: `{topics[]}`
- **`content`:** a misconception needs a `statement`, and a question needs a `phrasing`.

### `refers_to`
This ask, or this mention, means this entity.
- **`targets`:** `[ask event | mention]`
- **`value`:** `{entity, role?}`, with exactly one entity per claim.
- **Reversal:** a rejection undoes it. Once a (target, entity) pair has been rejected, it is never proposed automatically again.

### `same_as`
Two entities of the same type (topic, question, misconception or task) are one.
- **`targets`:** `[A, B]`
- **`value`:** `{survivor: B}`
- **Effect:** A's evidence counts as B's.
- **Reversal:** a rejection undoes the merge and blocks the pair from being proposed automatically again.

### `splits_into`
One entity turns out to be several.
- **`targets`:** `[A]`
- **`value`:** `{into: [B, C, …], assignments: [{ref, to}]}`. Every piece of A's evidence must be assigned, and each new entity needs its own `defines` claim.

### `relates`
A typed link between two things.
- **`targets`:** `[from, to]`
- **`value`:**
  - `relation`
  - `qualifiers?`: `{emphasis: exam_relevant · key_idea · common_trap}`
  - `status`: active or ended
- **Allowed pairs:**

  | Relation | From | To |
  |---|---|---|
  | prerequisite_of, part_of, contrasts_with, example_of, related_to | topic | topic |
  | covers, emphasizes | activity or source | topic |
  | exercises | task | topic |
  | stems_from | question or misconception | topic or misconception |
  | addresses | exposure | question, misconception or topic |

- `emphasizes` must cite a location in a source in `derived_from`.

### `judges`
A verdict on one observation.
- **`targets`:** `[one attempt | one exposure]`
- **`value`:** independent facets.

```yaml
# about an attempt: performance facets
overall:        correct | partial | incorrect
topics:         [{ topic, outcome: correct | partial | incorrect }]   # blame the most specific topic
support:        unaided | hinted | guided | with_reference | followed_example
own_words:      true | false
misconceptions: [{ misconception, present: true | false }]
demonstrates:   [question]
cause:          forgot | slip | misconception | missing_prerequisite | harder_variant | unclear
# about an exposure: teaching facets
answer:         [{ question, adequacy: full | partial | none }]
effect:         [{ target, effect: helped | no_effect | confused }]
```

- **`content`:** an optional `rationale`. It is redacted together with anything it quotes.

### `reviews`
Accepts, rejects or disputes another claim, or withdraws an earlier review.
- **`targets`:**
  - for accept, reject or withdraw: `[claim]`. A withdraw targets the reviewer's own earlier review.
  - for dispute: `[claim]` or `[attempt event]`. Disputing an attempt event contests its recorded outcome.
- **`value`:**
  - `decision`: accept, reject, dispute or withdraw
  - `facets?`: dispute only. The default is every performance facet of the target.
  - `reason`: confirmed · wrong_entity · not_same · wrong_diagnosis · wrong_outcome · duplicate · other

## 6. Authority, disputes and rejections

### Two kinds of claim

- **Performance evaluations** assess the learner's own work:
  - every `judges` claim on an attempt
  - an attempt's recorded outcome
  - `defines` claims for misconceptions
- **Everything else is about meaning and organisation:** topics, questions, tasks, relations, identity, and verdicts on how well teaching worked.

### Authority

For performance evaluations, authority runs from highest to lowest:

1. **person:** a human other than the learner. There is none in the pilot.
2. **auto**
3. **interpreter**
4. **chat_ai**
5. **self**

An attempt's recorded outcome counts as a verdict on the `overall` facet, with the authority of its `judged_by` (`ai` counts as `chat_ai`).

For meaning and organisation, **the learner reviews with person authority**.

### What each reviewer may do

| Reviewer | On performance evaluations | On everything else |
|---|---|---|
| The learner | **dispute** or **withdraw** their own dispute, and nothing else | accept, reject or withdraw |
| A reviewer with authority at least that of the target claim | accept or reject | accept or reject |
| A rule | accept at creation, following `policy@1` | accept at creation, following `policy@1` |

The API refuses anything else. For example, if the learner tries to reject a verdict on their own work, the request is refused with a pointer to `dispute`. The projection ignores any such review that reaches the journal anyway, as a second line of defence.

### Effective value of a facet

Each facet is resolved on its own, in this order:

1. Consider only effective verdicts: accepted, and not rejected by an authorised reviewer.
2. If a dispute covers the facet, go to [Disputes](#disputes).
3. Otherwise, the highest authority wins. If authorities are equal, an explicit `supersedes` wins, and failing that the latest position.
4. If there is still no verdict for a topic, use the task's `overall` outcome instead:
   - **correct** applies to every topic the task exercises;
   - **incorrect or partial** applies only to the task's most specific topics;
   - **disputed** applies to every topic the task exercises.

**Disagreements:**
- A lower-authority verdict that disagrees is kept and shown as overridden.
- If the interpreter disagrees with `auto`, the attempt is flagged `checker_suspect`.

### Disputes

A dispute **suspends** the evaluation it covers. It never creates favourable evidence, and it never brings back favourable evidence that was overridden.

- **Facets.**
  - Every facet the dispute covers becomes `disputed`. This applies whatever other verdicts were recorded before the dispute; there is no falling back to a lower authority.
  - A disputed facet counts neither as success nor as failure.
  - So if a chat AI marked an attempt correct, the interpreter marked it incorrect, and the learner disputes the interpreter, the attempt counts for nothing. The chat AI's "correct" is not restored.
- **What lifts a dispute.**
  - (a) The learner **withdraws** it.
  - (b) An **adjudication** is recorded. That is a verdict on the same facet that meets all three conditions:
    - it was recorded after the dispute;
    - its `derived_from` includes the dispute;
    - its authority is at least the highest authority among verdicts on that facet recorded before the dispute. For an `auto` verdict, that means running the checker again.
  - When (b) happens, the adjudication becomes the effective value. If there are several adjudications, normal precedence applies among them.
- **Misconceptions.** A disputed misconception `defines` claim puts the misconception in state `disputed`. In that state it is not active, and its exhibits and counters do not drive its lifecycle. The attempts behind it keep their outcomes: failures still count as failures. The dispute is lifted by withdrawal, or by a new exhibit judged on an attempt that occurred after the dispute.
- **Visible consequences.** Suspending negative evidence can let a topic's label rise; for example, a success no longer blocked by a misconception can count. Any label that is higher only because evidence is suspended carries the flag `rests_on_dispute`, so neither the learner nor an LLM mistakes it for a settled result.

### Rejection

A rejection by an authorised reviewer removes the claim, and ordinary precedence applies to whatever remains. The reviewer has the authority to decide, so falling back to the next verdict is intended.

## 7. Derived-state rules (`rules@1`)

### Provisional thresholds

| Parameter | Value |
|---|---|
| Retention gap | 21 days |
| Minimum delay before a repeat can count | 1 day |
| Needed for `secure` | apply tasks: at least 2 distinct task IDs across at least 2 sessions |
| Needed for `apparently_resolved` | counters on 2 distinct tasks, at least one in a later session than the latest exhibit |
| Review interval | 30 days for `secure`, 60 days for `durable` |
| A question becomes dormant after | 21 days |
| A session closes after | 30 minutes of inactivity |

### Definitions

- **Topics of a task:** the topics it exercises, according to effective `exercises` claims, with merged topics redirected.
- **Attempt on T:** T is one of the task's topics, or a verdict about the attempt names T.
- **Exposure about T:** one of its `about` links points to T. It **teaches T** if it is about T or about a broader topic that contains T.
- **Contact with T:** any attempt on T, or any exposure that teaches T, that hasn't been retracted.
- **Repeats.** A repeat is an attempt on a task the learner has attempted before. A repeat is **delayed** only if all three of these hold:
  - it is in a different session from the previous attempt on that task;
  - the guaranteed gap from that previous attempt is at least 1 day;
  - no teaching on the task's topics is possibly within the day before it.

  Every other repeat is **immediate**.
  - **Delayed repeats:**
    - count as qualifying successes and as counters;
    - can show retention;
    - can recover a regressed topic;
    - never add task diversity, because diversity counts distinct task IDs.
  - **Immediate repeats:** never count for anything positive. Their failures still count.
- **Qualifying success on T:** an attempt on T that meets all of these:
  - its effective outcome on T is correct, and not disputed;
  - its effective support is `unaided`;
  - the authority of that outcome is not `self`;
  - it is not retracted, and it is not an immediate repeat.
- **Unaided failure on T:** the effective outcome on T is incorrect or partial (not disputed), support is `unaided`, and the attempt is not retracted. Immediate repeats count here.
- **Regression point:** an unaided failure on T, whose cause is not `slip`, at a moment when T's label was `working` or better. A cause that hasn't been judged yet counts as not a slip.
- **Window:** evidence from the latest regression point onwards, including that failure. If there has been no regression point, the window is all evidence.
- **Retained, on a qualifying success s:** there is at least one earlier contact with T. Every contact c that possibly came before s (`c.lo ≤ s.hi`, with `c ≠ s`) is at least 21 days before s, measured as a guaranteed gap (`s.lo − c.hi`).
- **Practised:** qualifying successes in at least 3 sessions, where the guaranteed gap from the first to the last is at least 21 days. Every practice session is a contact, so frequent practice never produces *retained*. Being practised does hold off `needs_review`.
- **Explained:** a qualifying success with form `explain` and `own_words` true.
- **Active misconception on T:** a misconception whose topics include T, in state detected, recurring, resurfaced or addressed.
- **Topics of a question:** the topics it stems from, plus the topics of any misconception it stems from.

### Topic labels

Labels are calculated within the window. `not_started` and `introduced` look at the whole history.

| Label | Rule |
|---|---|
| `not_started` | No contact |
| `introduced` | Contact, but no attempts |
| `developing` | Attempts, and either no qualifying success or an active misconception on T |
| `working` | At least one qualifying success, and no active misconception on T |
| `secure` | Working, plus qualifying apply successes on at least 2 distinct task IDs across at least 2 sessions, plus either explained or retained |
| `durable` | Secure, plus retained |

### Topic flags

| Flag | Rule |
|---|---|
| `regressed` | The window starts at a regression point, and the label is `developing` |
| `claimed_only` | A `confident` or `clicked` self-report about T, or about a question whose topics include T, and no attempts on T |
| `overconfident` | The latest `confident` or `clicked` self-report about T is followed by an unaided failure on T, with no qualifying success after that failure |
| `underconfident` | The latest `confused`, `unsure` or `stuck` self-report about T is followed by at least 2 qualifying successes |
| `practised` | As defined above |
| `needs_review` | The label is `secure` or `durable`, and `now − (latest contact).hi` is longer than the review interval |
| `exam_relevant` | There is an effective `emphasizes` claim marking T as exam-relevant |
| `weak_part` | A topic that is part of T is `developing`, while T is `working` or better |
| `includes_ai_judged` | The label would be lower if only outcomes judged by `auto` or `person` counted |
| `rests_on_dispute` | The label would be lower if every dispute were ignored |

### Misconceptions

- **Exhibit:** an attempt where the misconception is effectively `present: true`.
- **Counter:** an attempt where it is effectively `present: false`, and which is also a qualifying success on one of the misconception's topics.

| State | Rule |
|---|---|
| `detected` | Exhibits on 1 task |
| `recurring` | Exhibits on at least 2 distinct tasks |
| `resurfaced` | An exhibit came after the misconception had been `apparently_resolved` or `resolved_retained`. The resurfaced count goes up by one |
| `addressed` | An effective `addresses` exposure after the latest exhibit |
| `apparently_resolved` | After the latest exhibit, counters on at least 2 distinct tasks, at least one of them in a different session from the latest exhibit |
| `resolved_retained` | Apparently resolved, plus a counter that is *retained* with respect to the misconception's topics |
| `disputed` | Its `defines` claim is disputed (§6) |
| `withdrawn` | Its `defines` claim was rejected |

After the latest exhibit, the first match in this order wins: resolved_retained, apparently_resolved, addressed, resurfaced, recurring, detected.

The active states are detected, recurring, resurfaced and addressed.

### Questions

- **Opening events:**
  - its asks: `question` events that effectively refer to the question, after merges and splits;
  - any `confused`, `unsure` or `stuck` self-report about the question that happens *while the question is resolved*.

  An opening event while the question is resolved increases `resurfaced_count`. If the opening event is a self-report, it also sets the `reopened` flag.
- **Answers:** exposures that respond to an opening event of the question, or that carry an effective `answer` facet for it.
- **State.** Look at the evidence after the latest opening event, and take the first of these that matches:
  1. **`resolved · demonstrated`**: an attempt that meets all of these:
     - it has an effective `demonstrates` facet naming the question;
     - it is unaided;
     - its effective overall outcome is correct;
     - it is not disputed.

     Overlap between topics never counts.
  2. **`resolved · learner-confirmed`**: after an answer, a `clicked` or `confident` self-report about the question. If that answer was judged partial or none, the state is `partially_answered` instead, with the flag `learner_thought_resolved`.
  3. **`answered`**: an answer judged full.
  4. **`partially_answered`**: an answer judged partial or none, or an uncertain self-report after an answer.
  5. **`being_answered`**: an answer that hasn't been judged.
  6. **`open`**: none of the above.
- **Flags:**
  - `resurfaced_count`
  - `reopened`
  - `dormant`: states 4–6, with no evidence for 21 days
  - `merged` or `split`

### Learning profile

- **Grouping:** in the pilot there are no course records, so the profile groups by teaching approach only.
- **What it counts:** effective `effect` verdicts, as helped, no_effect or confused.
- **What it keeps:** the targets, as examples.

## 8. Pilot acceptance policy (`policy@1`)

| Claim | Accepted automatically when | Otherwise |
|---|---|---|
| `refers_to` (ask to question, mention to topic) | confidence is at least 0.85. It stays reversible | pending |
| `defines` a question, for an ask that matches nothing | always | — |
| `defines` a task created by capture | always | — |
| `same_as` between questions or between tasks | confidence is at least 0.90. It stays reversible | pending |
| `splits_into` for anything, or `same_as` between topics | never | pending |
| `defines` a topic | never | pending; the learner confirms (in bulk for a whole course) |
| `defines` a misconception | there are exhibits on at least 2 distinct tasks | pending, with no effect on state |
| `judges` by the interpreter | always | — |
| `judges` proposed by a chat LLM | never. The interpreter treats them as hints | — |
| `relates` with stems_from, addresses, covers or exercises | confidence is at least 0.70 | pending |
| `relates` with emphasizes, prerequisite_of, part_of, contrasts_with or example_of | never | pending |
| Any claim by the learner, within §6 | always | — |

## 9. Capture from external chats

**MCP tools:**
- `get_context`
- `record_question`, `record_attempt`, `record_self_report` and `record_explanation`
- `propose`
- `session_recap`

The server authenticates the client and works out the learner from its token. It then:
1. filters the content (§11);
2. resolves the time;
3. assigns the session;
4. creates the ID;
5. appends the event;
6. runs the resolver;
7. returns an acknowledgement.

```yaml
status:   recorded | duplicate | filtered | rejected
event_id: <id>                       # absent when rejected
task_id:  <id>                       # record_attempt only; reuse it when retrying
stored:   { kind, fields, removed: [{ field, reason }] }
linked:   [{ entity, label, confidence, state }]
session:  <id>
note:     "Saved your answer to 'List every product…'."
reason:   not_learning | sensitive | invalid | not_allowed
```

- **Only `recorded`, `filtered` or `duplicate` means the event is stored.** The LLM must never tell the learner something was saved without one of these.
- **Tasks set in chat.** `record_attempt` takes either `task_id`, returned in an earlier acknowledgement, or the task's `prompt`.
  - When given a prompt, the server looks for the learner's existing task whose normalised prompt matches exactly. If none matches, it creates a task record and `exercises` claims from the topic hints.
  - The brief tells the LLM to pass `task_id` when the learner retries.
  - If near-identical prompts still create two tasks, the interpreter proposes `same_as`.
  - Repeats are never given a fresh task identity automatically.
- **Resending is safe.**
  - The same `capture_key` returns the original acknowledgement, and the same key with different content is rejected.
  - With no key, a fingerprint made of learner, client, kind, normalised content and task is used. A repeat of it within 10 minutes counts as a duplicate.
- **Time.**
  - `now` means the server's receive time.
  - Relative phrases are resolved in the learner's time zone, with a matching precision. For example, "yesterday" becomes precision `day`.
  - The LLM's clock is never trusted.
- **Sessions.** One client's captures belong to the same session while the gaps between them are under 30 minutes.
- **Missing events.**
  - An event that was never captured is never treated as evidence.
  - Briefs mention that capture may have been incomplete.
  - The learner can add or correct events in the web app.
  - Importing transcripts comes **(later)**.
- **Late events.**
  - A late event gets its `position` when it arrives.
  - Its interval places it in the order.
  - Derived state is recalculated from its `lo`.
  - Claims made earlier are never rewritten. The interpreter runs again for the entities affected.
  - "What did the brain believe at time X" replays by `position`.

## 10. Redaction

### Step 1: immediate blocking, in one MySQL transaction

1. Append the `redact` amendment.
2. Delete the redacted content fields, and the mention positions that point into them.
3. Delete the content fields of claims that are derived from the event: rationales, phrasings and statements. Their structured values stay.
4. Insert **block entries**, for the event, and for a source when a source is redacted.
5. Increase the learner's **cache version**, so every cached brief and summary for that learner becomes unreachable.
6. Mark every summary built from a blocked event as stale.
7. Queue the clean-up tasks in the outbox.

**From the moment this commits:**
- the text is gone from MySQL;
- the retrieval gateway drops blocked IDs when it hydrates results, so Qdrant results that haven't been cleaned up yet are never shown;
- the file service refuses to open blocked sources;
- stale summaries are never served;
- briefs are rebuilt.

### Step 2: durable clean-up, done by outbox workers

- **What gets cleaned up:**
  - the canonical file
  - derived files
  - Qdrant points, followed by the forced storage rewrite from ADR 0001
  - summaries, which are regenerated
- **Every task:**
  - is idempotent, so "not found" counts as done;
  - is checked afterwards by testing whether the target still exists;
  - is retried with backoff: 1 minute, 5 minutes, 30 minutes, 2 hours, 12 hours, then every 24 hours.
- **When clean-up stalls:** after 5 failures, the redaction shows as "clean-up overdue" to the learner and the operator. Blocks stay in force permanently.
- **Files never share the MySQL transaction.** Everything happens in this order:
  1. The redaction ledger entry is written.
  2. The MySQL transaction commits.
  3. The file deletions run as tasks.

  If the process crashes after step 1, reconciliation redoes the redaction. If it crashes after step 2, the tasks retry.
- **Orphan files.** Uploads write the file before its record exists. A sweeper deletes canonical files that have had no source record for 24 hours.

### Protection against restoring old data

- **The redaction ledger** stores the redaction ID, targets, fields and date, and never content. It lives outside MySQL and the file backups, on its own storage, with backups kept longer than any data backup.
- **The ledger-applied marker.** The database records the last ledger entry it has applied.
- **After any restore** of MySQL or of file storage, the brain must not serve until `brain:reconcile-redactions` has run. That command:
  - re-applies every ledger entry the database hasn't seen, idempotently;
  - queues the file deletions again;
  - increases cache versions;
  - requests a rebuild of Qdrant.

  The health check fails while the ledger is ahead of the database.

### What remains after redaction

| Where | Kept for |
|---|---|
| MySQL change logs | 7 days |
| MySQL backups | 35 days |
| File backups | Until their retention expires |
| Briefs already sent to external chat LLMs | Outside our control |

The pilot has no encryption, so these copies are readable plaintext. After the encryption phase, a single redacted item stays readable in backups while the learner's key exists, because keys are per learner. A redacted secret should also be changed.

## 11. What is never captured by default

| Category | Default |
|---|---|
| Passwords, API keys, connection strings | Removed before storage. Redacted if found later |
| Wellbeing and health | Not recorded. Storing study preferences related to health is **deferred** |
| Unrelated personal life, identity documents, location | Not recorded |
| Other people's personal data | Removed |
| Casual chat and venting | Not recorded |
| Religion, politics, sexuality or ethnicity disclosed about oneself | Not recorded. The same topics as course subject matter are fine |

## 12. Pilot scope

**Built in the pilot:**
- learner streams only; course uploads are private;
- sub-topics, through `part_of`;
- AI-judged verdicts counted and flagged;
- transcripts off by default;
- automatic question matches that can be reversed.

**Deferred:**
- shared streams and shared contributions;
- health-related preferences;
- plugins;
- batched attempts;
- offline Flutter capture;
- importing transcripts;
- human reviewers other than the learner.

## 13. Consequences

**Positive:**
- Seven kinds cover the whole learning journey in the replay.
- Every judgement records who made it, with what authority, and can be reversed.
- Derived state can be replayed exactly, and so can what the brain believed at any earlier time.
- Uncertain timing and disputes can hold a label back or be shown openly. They can never quietly raise one.

**Costs:**
- The rules must be implemented exactly as written. The golden replay and its edge cases check them.
- The interpreter's quality determines diagnoses, question resolution and the learning profile. ADR 0001's G1 gate and the six-week review measure it.
- Labels are conservative on purpose. For example, a success doesn't count as `working` while a misconception is active, and a retry the same day doesn't count at all.

## 14. Open questions

- Can being `practised` ever be enough for `durable`?
- How does authority work for teachers and guardians, once there are any?
- `rules@2` will be written after six weeks, if the pilot data shows the thresholds need changing.
