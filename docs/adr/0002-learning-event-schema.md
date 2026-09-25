# ADR 0002: Learning event schema

- **Status:** PROVISIONAL. It becomes ACCEPTED when both of these hold:
  1. the golden replay in [`docs/specs/golden-replay-sql-joins.md`](../specs/golden-replay-sql-joins.md) passes as an automated test against the real projection code;
  2. after six weeks of pilot use, nothing the learner did has needed a workaround to record.
- **Date:** 2026-09-25
- **Decision owner:** project owner (project manager and system architect)
- **Depends on:** [ADR 0001](0001-permanent-store-and-retrieval-index.md)

## Context

ADR 0001 made an append-only journal the source of truth. This ADR decides what goes into that journal:

- the vocabulary of events and claims;
- the exact contents of each claim;
- what happens when judgements disagree;
- the rules that turn the journal into learner state;
- how external chats capture events, and how redaction works.

The scope is the personal pilot. Anything marked **(later)** is defined now so the model doesn't need to change, but it won't be built yet.

## 1. Three layers

| Layer | What it holds | How it changes |
|---|---|---|
| **Observations** | What happened, in the learner's own words where possible | Never edited. It can only be retracted, amended or redacted by an amendment |
| **Claims** | Recorded judgements. Each has a method, an authority, a confidence level and a review state | Never edited. A newer claim or a review replaces it |
| **Derived state** | Topic labels, the state of misconceptions and questions, the learner's profile | Recalculated from observations and accepted claims by versioned rules. It is never stored as truth |

Two further kinds support these layers:
- **Records** hold things and administrative facts.
- **Amendments** are the only way an observation or record ever changes.

## 2. Vocabulary

| Kind | Meaning |
|---|---|
| `exposure` | Teaching reached the learner: a lecture, some reading, a video, an explanation, or feedback |
| `attempt` | The learner produced something that can be judged |
| `question` | The learner asked something |
| `self_report` | The learner said something about their own state |
| `claim` | Any recorded judgement (see §5) |
| `record` | A thing or an administrative fact |
| `amendment` | Retracts, amends or redacts an earlier observation or record |

Each of these fields has a fixed list of values, owned by the core. Plugins **(later)** may add subtypes, but every subtype must map onto one of these values.

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
| `relates` relation | prerequisite_of · part_of · contrasts_with · example_of · related_to · covers · emphasizes · stems_from · addresses |
| record `type` | source · extraction · activity · session · profile · consent |
| amendment `action` | retract · amend · redact |
| observation `origin` | first_hand · reported · material |

## 3. Fields every event carries (the envelope)

| Field | Meaning |
|---|---|
| `id` | A UUIDv7. The web app generates it on the device. For external chats the server generates it (§9) |
| `stream` | `learner:<id>`. In the pilot this is the only kind of stream. `shared:<scope>` comes **(later)** |
| `position` | Assigned by the server and strictly increasing within a stream. It defines what was "recorded before" what |
| `kind`, `type`, `type_version` | The kind (§2), plus a specific type such as `core.attempt` and its version |
| `subject` | The learner |
| `actor` | `{type, id, channel}`. `type` is one of learner · person · chat_client · processor · system. `channel` is one of web · mcp · api · job |
| `origin` | Observations only. `first_hand` means captured as it happened. `reported` means described afterwards. `material` means it comes from course material |
| `occurred_at`, `occurred_until`, `precision`, `tz` | When it happened in the learner's life, how precisely we know that, and their time zone |
| `recorded_at`, `received_at` | When the device recorded it (not trusted), and when the server received it (trusted) |
| `session`, `activity` | Grouping |
| `links` | Links made at capture time. `about` points to a topic, question or misconception, with a role of primary or secondary. `cites` points to a source and a location in it. `responds_to` and `triggered_by` point to another event |
| `mentions` | `[{n, field, start, end}]`: character positions in a content field. The mentioned text itself exists only in the content field |
| `body` | Core fields specific to the kind (§4, §5) |
| `payload` | Fields specific to the type, used by plugins **(later)** |
| `content` | Named free-text fields. They are stored separately, never queried by SQL, and can be redacted |

## 4. Observation, record and amendment contracts

**exposure**
- `body`:
  - `format` (required);
  - `approach[]`;
  - `by`: `{type: lecturer · chat_ai · person · self_study, id?}`;
  - `duration_min?`.
- `links`: `activity?`, `responds_to?` (the question being answered), `cites?`.
- `content`:
  - `summary`: at most three sentences. Required when the explanation comes from a chat LLM;
  - `example?`.
- **Pilot rule:** every substantial explanation from a chat LLM is recorded as an exposure.

**attempt**
- `body`:
  - `task`: `{key (required), activity?, part?}`;
  - `form`, `support`, `setting`, `outcome`, `judged_by`;
  - `score?`: `{raw, max}`;
  - `results?`: batched attempts **(later)**.
- `content`:
  - `answer`: the learner's own words or code, verbatim after filtering;
  - `output?`: what a checker returned, or the error message;
  - `prompt?`: the task text, when no source holds it.
- **Task keys:**
  - a lab task uses the task key from the activity record;
  - a task set in chat uses `chat:<event id>`, so it is always distinct;
  - a quiz or past paper uses `<source>/<question>`.

**question**
- `links`: `about` (hints about topics), `responds_to?`.
- `content`: `text` (verbatim).

**self_report**
- `body`: `stance`.
- `links`:
  - `about`: at least one topic or question. A self-report can be about a question itself;
  - `triggered_by?`.
- `content`: `text?` (verbatim).

**record** (the types used in the pilot)

| Type | Fields |
|---|---|
| `source` | kind, title, storage key, hash, scope (private) |
| `extraction` | source, extractor and version, version number, segments with their locations |
| `activity` | kind (lecture · lab · assignment · quiz · exam · problem_set), title, `starts_at`, `ends_at?`, and tasks as `[{key, title?, topics[]}]` |
| `session` | channel, client, `started_at`, `ended_at` |
| `profile` | the learner's settings |
| `consent` | the learner's consents |

A revision of a record is appended as a new version.

**amendment**
- `action`: retract · amend · redact.
- `targets`: the events it applies to.
- `fields?`: the content fields to redact, or the fields to replace when amending.
- `replacement?`: the new values, when amending.
- `reason`: wrong_capture · secret · personal_information · not_learning · learner_request · other.
- An amendment **never contains the redacted text.**

## 5. Claim contracts

Every claim has this structure:

```yaml
claim:
  type:          defines | refers_to | same_as | splits_into | relates | judges | reviews
  targets:       [ref, ...]          # what the claim is about (per type below)
  value:         { ... }             # per type below
  confidence:    0..1 | null         # null for person and rule claims
  method:        { kind: person | auto | interpreter | chat_ai | self | rule,
                   id, version, prompt? }
  derived_from:  [ref, ...]          # the evidence it rests on
  supersedes:    [claim_ref, ...]    # optional
  review:        { state: accepted | pending, by?: policy@version | person }
content (optional): rationale | phrasing | statement | definition
```

**Kinds of reference:**
- `event:<id>` and `claim:<id>`;
- `topic:<id>`, `question:<id>` and `misconception:<id>`;
- `activity:<id>` and `task:<activity>/<key>`;
- `source:<id>#<locator>`, which points to a location within a source;
- `mention:<event>/<n>`, the nth mention in an event.

**The `method` kinds:**
- `auto` is a deterministic answer checker.
- `rule` is deterministic system logic, such as the resolver or the acceptance policy.
- `interpreter` is our local interpreting model.
- `chat_ai` is an external chat LLM.

### `defines`: creates or revises a topic, misconception or question

- `targets`: `[entity]`. A new ID is created by the claim itself.
- `value`:
  - `entity_type`: topic · misconception · question;
  - `status`: active · retired;
  - for a topic: `{kind: concept · skill · procedure · fact, aliases[]}`;
  - for a misconception: `{topics[]}`.
- `content`:
  - topic: `definition?`;
  - misconception: `statement` (required);
  - question: `phrasing` (required).
- **Revising** means a new `defines` claim that supersedes the previous one.
- **Withdrawing** means a `reviews` reject of the `defines` claim.

### `refers_to`: this ask, or this mention, means this entity

- `targets`: `[ask event | mention]`.
- `value`: `{entity, role?: primary · secondary}`.
- Each claim names exactly one entity. Several claims about the same target are allowed, which is how one ask covers two questions.
- It is reversed by a `reviews` reject. Once a (target, entity) pair has been rejected, it is never proposed again automatically.

### `same_as`: two entities are the same thing

- `targets`: `[A, B]`, both of the same entity type.
- `value`: `{survivor: B}`.
- **Effect:** all of A's evidence counts as B's, and A is shown as merged.
- **Reversible:** a `reviews` reject restores A. The pair is then blocked from being proposed again automatically.

### `splits_into`: one entity turns out to be several

- `targets`: `[A]`.
- `value`: `{into: [B, C, …], assignments: [{ref, to}]}`.
  - Every piece of evidence attached to A must be assigned to one of the new entities.
  - B, C and so on each need their own `defines` claim in the same run.
- **Effect:** A is marked as split and becomes inactive.

### `relates`: a typed link between two things

- `targets`: `[from, to]`.
- `value`:
  - `relation`;
  - `qualifiers?`: `{emphasis: exam_relevant · key_idea · common_trap}`;
  - `status`: active · ended.
- **Allowed pairs:**

| Relation | From | To | Extra requirement |
|---|---|---|---|
| prerequisite_of, part_of, contrasts_with, example_of, related_to | topic | topic | — |
| covers | activity or source | topic | — |
| emphasizes | activity or source | topic | `derived_from` must cite a location in a source |
| stems_from | question or misconception | topic or misconception | — |
| addresses | exposure | question, misconception or topic | — |

### `judges`: a verdict on one observation

- `targets`: `[one attempt | one exposure]`.
- `value`: a set of independent facets. Each facet is optional, but at least one is required.

```yaml
# about an attempt
overall:        correct | partial | incorrect
topics:         [{ topic, outcome: correct | partial | incorrect }]   # blame the most specific topic
support:        unaided | hinted | guided | with_reference | followed_example
own_words:      true | false                                         # explain attempts only
misconceptions: [{ misconception, present: true | false }]           # false only where the task could show it
demonstrates:   [question]      # shows the specific understanding that question asked for
cause:          forgot | slip | misconception | missing_prerequisite | harder_variant | unclear
# about an exposure
answer:         [{ question, adequacy: full | partial | none }]
effect:         [{ target, effect: helped | no_effect | confused }]  # target: topic | question | misconception
```

- `content`: `rationale?`. If the rationale quotes the learner, it is redacted along with the learner's words.

### `reviews`: accepts or rejects another claim

- `targets`: `[claim]`.
- `value`:
  - `decision`: accept · reject;
  - `reason`: confirmed · wrong_entity · not_same · wrong_diagnosis · wrong_outcome · duplicate · other.
- `method`: person (in the pilot, the learner) or rule (`policy@version`).

## 6. Authority and conflicts

### Order of authority

For verdicts on outcomes and evidence, authority runs from highest to lowest:

1. **person**: a human other than the learner. There is none in the pilot.
2. **auto**: a deterministic answer checker.
3. **interpreter**: our local model.
4. **chat_ai**: an external chat LLM.
5. **self**: the learner judging their own work.

An attempt's own `outcome` counts as a verdict on the `overall` facet, with the authority of its `judged_by` value (`ai` counts as `chat_ai`).

### Working out the effective value

Each facet is resolved on its own. That applies to `overall`, each topic, `support`, `own_words`, each misconception, each question listed under `demonstrates` or `answer`, each target of `effect`, and `cause`.

1. Consider only accepted verdicts.
2. The highest authority wins.
3. If authorities are equal, an explicit `supersedes` wins. Otherwise the most recently recorded verdict wins.

### When no verdict names a specific topic

The task's overall outcome is used instead:

- **Overall correct:** every topic of the task counts as correct. A correct answer shows every skill it needed.
- **Overall incorrect or partial:** only the task's most specific topics get that outcome. These are the topics with no sub-topics among the task's topics. Broader topics stay unjudged until the interpreter judges them.

### Disagreements

- **A lower authority disagrees with a higher one.** The lower verdict is kept and shown as overridden. If the interpreter disagrees with `auto`, the attempt is flagged `checker_suspect` for inspection. Derived state doesn't change.
- **The learner disagrees with a higher authority.** That facet becomes `disputed`, and the attempt stops counting as evidence for it, either as a success or as a failure. This lasts until one of these happens:
  - a verdict of equal or higher authority than the disputed one is recorded after the dispute. For an `auto` verdict, that means running the checker again;
  - the learner withdraws the dispute.
- **The learner's reviews outrank rules.**
  - The learner can reject any claim, including an AI verdict or diagnosis. The facet then falls back to the next accepted verdict.
  - The learner can never raise their own outcome: rejecting a verdict removes it, but the attempt's own recorded outcome still stands.

## 7. Rules for deriving state (`rules@1`)

### Provisional thresholds

These will be tuned after six weeks of pilot use.

| Parameter | Value |
|---|---|
| Retention gap | 21 days |
| Needed for `secure` | 2 different apply tasks across 2 sessions |
| Needed for `apparently_resolved` | Counters on 2 different tasks, at least one in a later session than the latest exhibit |
| Review interval | 30 days for `secure`, 60 days for `durable` |
| Question counts as dormant after | 21 days |
| A session ends after | 30 minutes of inactivity |
| The interpreter runs | when a session ends |

### Definitions

Events are processed in order of `(occurred_at, position)`.

- **Attempt on T:** an attempt is on T if T is one of its task's topics, or if a verdict about the attempt names T.
- **Exposure about T:** an exposure is about T if one of its `about` links points to T. When the exposure belongs to an activity, the capture step copies the topics that activity covers into its `about` links.
- **Contact with topic T:** any attempt on T that hasn't been retracted, or any exposure about T or a broader topic that contains T.
- **Teaching recorded on T:** any exposure about T or a broader topic that contains T. We only know about teaching that was recorded, so briefs say "no teaching recorded", never "no teaching".
- **Retry:** an attempt on a task key the learner has attempted before. Retries appear in the history but never count towards any threshold.
- **Qualifying success on T:** an attempt that meets all of these:
  - its effective outcome on T is correct;
  - its effective support is `unaided`;
  - its authority is not `self`;
  - it isn't disputed, retracted or a retry.
- **Unaided failure on T:** an attempt whose effective outcome on T is incorrect or partial, with `unaided` support, that isn't disputed or retracted. Retries count here.
- **Regression point:** an unaided failure on T whose cause is not `slip`, happening after T had reached `working` or better. A failure whose cause hasn't been judged counts too.
- **Window:** only evidence from the latest regression point onwards counts towards the label, including the failure itself. If there has been no regression, all evidence counts. After a regression, the topic has to earn its labels again, though its earlier history stays visible.
- **Retained on T:** a qualifying success that comes at least 21 days after the previous contact with T.
- **Practised:** qualifying successes in at least 3 sessions, spread over at least 21 days. Each practice session is itself a contact, so frequent practice never produces *retained*. Only success after a gap does. Being practised is shown in briefs, and it holds off `needs_review` because contact is recent, but it doesn't make a topic `durable`. While the learner practises regularly, we can't tell whether the knowledge would survive a month off, and while they keep practising, that doesn't matter.
- **Explained:** an attempt with form `explain`, an effective outcome on T of correct, and `own_words` true.
- **Active misconception on T:** a misconception linked to T that is detected, recurring, resurfaced or addressed.

### Topic labels (calculated within the window)

| Label | Rule |
|---|---|
| `not_started` | No contact |
| `introduced` | Exposures only |
| `developing` | Has attempts, and either no qualifying success or an active misconception on T |
| `working` | At least one qualifying success, and no active misconception on T |
| `secure` | Working, plus qualifying successes with form `apply` on at least 2 different task keys across at least 2 sessions, plus either explained or retained |
| `durable` | Secure, plus retained |

**Flags:**

| Flag | Rule |
|---|---|
| `regressed` | The window starts at a regression point, and T hasn't reached `working` again since |
| `claimed_only` | A `confident` or `clicked` self-report about T, or about a question that stems from T, and no attempts on T |
| `overconfident` | The latest `confident` or `clicked` self-report about T was followed by an unaided failure, with no qualifying success since |
| `underconfident` | The latest `confused`, `unsure` or `stuck` self-report about T was followed by at least 2 qualifying successes |
| `practised` | As defined above |
| `needs_review` | The label is `secure` or `durable`, and the time since the last contact is longer than the review interval |
| `exam_relevant` | There is an accepted `emphasizes` claim marking T as exam-relevant |
| `weak_part` | A topic that is part of T is `developing` or `regressed`, while T itself is `working` or better |
| `includes_ai_judged` | The label's conditions are only met with the help of evidence whose effective authority is `interpreter` or `chat_ai` |

### Misconceptions

- **Exhibit:** an attempt, not retracted or disputed, where the misconception's effective facet is `present: true`.
- **Counter:** an attempt with `present: false` that is also a qualifying success on one of the misconception's topics.

| State | Rule |
|---|---|
| `detected` | Exhibits on one task key |
| `recurring` | Exhibits on at least 2 different task keys |
| `resurfaced` | An exhibit after the misconception had been `apparently_resolved` or `resolved_retained`. The resurfaced count goes up by one |
| `addressed` | An accepted `addresses` exposure after the latest exhibit |
| `apparently_resolved` | After the latest exhibit: counters on at least 2 different task keys, at least one of them in a later session than the latest exhibit |
| `resolved_retained` | `apparently_resolved`, plus a counter at least 21 days after the previous contact with its topics |
| `withdrawn` | Its `defines` claim was rejected. Its exhibits go back to being ordinary incorrect attempts |

**Precedence:** after the latest exhibit, the first matching state in this order wins: resolved_retained, apparently_resolved, addressed, resurfaced, recurring, detected.

**Active states:** detected, recurring, resurfaced and addressed.

### Questions

- **Asks:** the `question` events that effectively refer to Q, after any merges and splits.
- **Answers:** exposures that respond to an ask of Q, or that carry an accepted `answer` facet for Q.

Check these in order, looking only at evidence after the last ask. The first match wins.

1. **`resolved · demonstrated`**: an attempt that meets all of these:
   - it has an accepted `demonstrates` facet naming Q;
   - it is unaided;
   - its effective overall outcome is correct;
   - it isn't disputed.

   Overlap between topics is never enough on its own.
2. **`resolved · learner-confirmed`**: after an answer, a `clicked` or `confident` self-report about Q itself, with no later `confused`, `unsure` or `stuck` self-report about Q. If that answer has been judged `partial` or `none`, the state is `partially_answered` instead, with the flag `learner_thought_resolved`.
3. **`answered`**: an answer judged `full`.
4. **`partially_answered`**: an answer judged `partial` or `none`, or a `confused`, `unsure` or `stuck` self-report about Q after an answer.
5. **`being_answered`**: an answer that hasn't been judged yet.
6. **`open`**: none of the above.

**Flags:**
- `resurfaced_count`: how many asks arrived while Q was in a resolved state.
- `dormant`: Q is in state 4, 5 or 6 and has had no evidence for 21 days.
- `merged` or `split`, from identity claims.

### Learning profile

- For each course and each teaching approach, the profile counts accepted `effect` verdicts: helped, no effect, or confused.
- It keeps the targets as examples.
- It is presented as "what has worked, and for which topics", never as a learning-style label.

## 8. Pilot acceptance policy (`policy@1`)

| Claim | Accepted automatically when | Otherwise |
|---|---|---|
| `refers_to` (an ask to a question, or a mention to a topic) | Confidence is at least 0.85. It stays reversible | Pending. The ask stays unassigned, or the mention unresolved |
| `defines` a question (for an ask that matches nothing) | Always | — |
| `same_as` between questions | Confidence is at least 0.90. It stays reversible | Pending |
| `splits_into` (any entity), or `same_as` between topics | Never | Pending |
| `defines` a topic (from uploaded material or from chat) | Never | Pending. The learner confirms topics, in bulk for a whole course |
| `defines` a misconception | There are exhibits on at least 2 different task keys | Pending. A pending misconception doesn't affect derived state |
| `judges` by the interpreter | Always | — |
| `judges` proposed by a chat LLM through MCP | Never. The interpreter uses them as hints | — |
| `relates` with stems_from, addresses or covers | Confidence is at least 0.70 | Pending |
| `relates` with emphasizes, prerequisite_of, part_of, contrasts_with or example_of | Never | Pending |
| Any claim made by the learner | Always | — |

## 9. Capture from external chats

**MCP tools:**
- `get_context`;
- `record_question`, `record_attempt`, `record_self_report` and `record_explanation`, which records an exposure;
- `propose`, for claim hints;
- `session_recap`, an optional catch-up the LLM can call when the learner wraps up.

The server authenticates the chat client and works out the learner from the token. It then:
1. runs the capture filter (§11);
2. works out the time;
3. assigns the session;
4. creates the event ID;
5. appends the event;
6. runs the resolver;
7. returns an acknowledgement.

**The acknowledgement:**

```yaml
status:   recorded | duplicate | filtered | rejected
event_id: <id>                     # absent when rejected
stored:   { kind, fields, removed: [{ field, reason: secret | personal_information | not_learning }] }
linked:   [{ entity, label, confidence, state: accepted | pending }]
session:  <id>
note:     "Saved your question about LEFT JOIN."   # one sentence the LLM may show the learner
reason:   not_learning | sensitive | invalid | not_allowed   # only when rejected
```

- **Only `recorded`, `filtered` or `duplicate` means the event is stored.** The brief tells the LLM never to tell the learner something was saved unless it received one of these.
- **Resending is safe (idempotency).** A tool call may include a `capture_key`:
  - the same key sent again returns the original acknowledgement and creates nothing;
  - the same key with different content is rejected as `invalid`;
  - with no key, the server builds a fingerprint from the learner, client, kind, normalised content and task key. A repeat within 10 minutes returns the original acknowledgement marked `duplicate`. After 10 minutes it is a new event, because a question asked again is a new ask.
- **Time.**
  - `when: now` sets `occurred_at` to `received_at`.
  - Phrases like "earlier today" or "yesterday" are resolved against `received_at` in the learner's time zone.
  - A clock time from the LLM is never trusted as "now", because chat models often don't know the current date.
- **Sessions.** External chats don't report where a conversation starts or ends. A session is therefore one client's captures with gaps of less than 30 minutes. MCP's own session IDs are stored as hints only.
- **Missing events.** The brain only knows what was captured. The LLM may not call the tool, the learner may decline a tool call, or the client may be offline. The design accepts this:
  - No rule treats missing events as evidence of anything. Staleness is the only rule based on time passing.
  - Briefs note when chat capture may have been incomplete.
  - The learner can add or correct events in the web app.
  - `session_recap` offers a catch-up.
  - Importing transcripts comes **(later)** and will be off by default.
- **Late events.** An event can arrive after events that happened later: a retry, a past event reported afterwards, or offline capture on Flutter **(later)**.
  - The event gets its `position` when it arrives.
  - Derived state for every affected topic, question and misconception is recalculated from the late event's `occurred_at`.
  - Earlier claims are never rewritten. The interpreter runs again for the affected entities and may add claims that supersede them.
  - "What did the brain believe at time X" replays by `position`, so a late arrival never changes what the brain believed in the past.

## 10. Redaction

**How it works:**

1. **Request.** The learner asks to redact content fields in an event, or a source file. The secret scanner can also ask, if it finds something after storage.
2. **Record.** An amendment with action `redact` is added. It lists the targets, the fields and a reason code, but never the text itself.
3. **Delete, in the same transaction.**
   - The content fields and any mention positions pointing into them are deleted.
   - The event's envelope stays, marked "content redacted", with the date and reason.
   - For a source, the file, its extracted text and its derived files are deleted. The source record stays, marked as redacted.
4. **Clean up derived copies.** An outbox tracks each step until it is complete.
   - **Claims based on the event:** their text fields (rationale, phrasing, statement) are redacted too. Their structured values stay, so the brain still knows the attempt happened and was wrong; it just no longer has the words. If the learner wants the evidence itself gone, they also retract the event.
   - **Qdrant:** the event's points are deleted (point IDs are event and chunk IDs), and the storage rewrite from ADR 0001 is forced or awaited.
   - **Summaries:** every summary records the events it was built from. Any summary built from a redacted event is deleted and regenerated without it.
   - **The learner's brief cache** is cleared.
   - **Retrieval logs** store event IDs, not brief text. A query whose own text is the redacted content is deleted.
   - **Plugins (later)** must implement a redaction handler.
5. **Confirm.** A completion record lists every step. Tests in the style of G3 cover the whole flow.

**What remains afterwards:**

- **MySQL's change logs and data backups** keep the text until they expire. The proposed retention is 7 days for the change logs and 35 days for backups.
  - In the pilot, that text is plaintext.
  - After the encryption phase it is still readable while the learner's key exists. Keys are per learner, so destroying a key can erase a whole learner, not a single item.
- **Backups of file storage** keep the file until they expire.
- **Restores.** A redaction ledger records event IDs, fields and dates, but no content. It is kept outside the data backups, and it re-applies every redaction after a restore.
- **Anything already sent to an external chat LLM** is outside our control.
- **A redacted password or other secret should also be changed.**

## 11. What is never captured by default

| Category | Default |
|---|---|
| Passwords, API keys and connection strings | Removed before storage by the scanner, and redacted if found later |
| Wellbeing and health disclosures | Not recorded. Storing study preferences related to health is **deferred** |
| Unrelated personal life, identity documents, location | Not recorded |
| Other people's personal data | Removed ("a classmate explained…") |
| Casual conversation and venting | Not recorded |
| Religion, politics, sexuality or ethnicity disclosed about oneself | Not recorded. The same topics as course subject matter are normal content |

**How this is enforced:**
- MCP accepts only typed learning events. There is no general "remember this" tool.
- Self-report stances are a fixed list.
- The brief tells the chat LLM what it may capture.
- A local capture filter removes offending text and keeps the learning part. When it is unsure, it removes the text.
- The learner can see and redact everything.

## 12. Pilot scope

**What the pilot builds:**
- **Learner streams only.** Course material the learner uploads is private.
- **Sub-topics** through `part_of`.
- **AI verdicts count** towards derived state, and are shown as `includes_ai_judged`.
- **Transcripts off by default,** and no importing of transcripts yet.
- **Automatic question matching is reversible.** That covers `refers_to` pointing at an existing question and `same_as`. Rejecting a match blocks it from being proposed again.

**Deferred:**
- shared streams, and contributions from private to shared;
- storing study preferences related to health;
- plugins;
- batched attempts;
- offline capture on Flutter. The ID scheme and the handling of late events already allow for it.

## 13. Consequences

**Positive**
- Seven kinds cover the whole learning journey in the replay.
- Every judgement records who or what made it and with what authority, and can be reversed.
- Derived state can be replayed exactly, and so can what the brain believed at any earlier time.

**Costs**
- Derived-state rules have to be implemented exactly as written, and tested against the golden replay.
- The interpreter's quality directly affects misconceptions, question resolution and the learning profile. Gate G1 in ADR 0001, and this ADR's six-week review, will measure it.
- Labels are deliberately conservative. For example, a success doesn't count as `working` while a misconception is still active. In the pilot, the learner may find that too strict.

## 14. Open questions

- Should `practised` ever be enough for `durable`? This will be revisited with pilot data.
- How authority works for other people, such as teachers and guardians, once there are any.
- Whether the thresholds hold up. `rules@2` will replace `rules@1` after six weeks if the pilot shows it needs to.
