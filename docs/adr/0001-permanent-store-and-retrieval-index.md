# ADR 0001: Permanent store and retrieval index

- **Status:** Accepted, provisional. It becomes final only when validation gates G1–G4 pass (see [Validation gates](#validation-gates)).
- **Date:** 2026-09-25
- **Decision owner:** Project owner (project manager and system architect)

## Context

The Study Brain is a persistent learning memory that lives outside any LLM. ChatGPT, Claude, Gemini, local models and future LLMs read from it and propose updates to it through MCP and an HTTP API. The chat is disposable; the memory is not. It must cover years of a learner's education and grow into a multi-learner platform with subject and age plugins.

This ADR relies on memory-architecture principles already agreed in design discussion:

1. **Sources never change.** Uploaded material is never modified, and every claim cites it.
2. **The source of truth is an append-only journal.** Two kinds of event go into it:
   - *observations*: what happened;
   - *recorded interpretations*: judgements made by an AI, a rule or a person, each with its provenance.

   Corrections, merges and reinterpretations are recorded as new events.
3. **Learner state is recalculated, never edited.** It is worked out from observations plus the interpretations currently in force. Rebuilding it never calls an LLM.
4. **The data model is shaped like a graph but stored in relational tables.** Events, learning items and sources are joined by explicit typed links, so a graph engine can be added later as a copy built from those tables.
5. **LLMs propose; the brain validates.** No LLM writes canonical state directly.
6. **Everything derived can be thrown away and rebuilt.** This covers learner state, summaries, embeddings, search indexes and graph copies.
7. **A canonical export can rebuild the brain without any of today's products.** It consists of versioned JSON Lines, the source files and a written format specification.

**Constraints:**
- self-hosted;
- no required recurring cost for storing data;
- Laravel/PHP;
- MySQL chosen by the owner;
- web first, then Flutter with offline capture;
- external LLMs connect through MCP and an API;
- future plugins;
- platform scale, planned for 10,000 learners over several years.

**Non-negotiable criteria** from the decision review:
- A. Student ownership
- B. Reconstructability
- C. Chronological integrity
- D. Evidence provenance
- E. LLM independence
- F. Retrieval quality
- G. No required recurring storage cost
- H. Laravel compatibility
- I. Mobile and offline readiness
- J. Multi-user isolation
- K. Plugin extensibility
- L. Human inspectability
- M. Privacy and erasure
- N. Complexity must earn its place
- O. Design for years, build for V1

## Decision

| Concern | Choice |
|---|---|
| Permanent (canonical) store | MySQL 8.4 LTS. MySQL 8.0 reached end of life in April 2026 |
| Raw source files | Laravel Storage: local disk at first, S3-compatible storage later. Private files are encrypted with the learner's key before they are stored |
| Retrieval index (derived) | Qdrant, holding embeddings, keyed keyword tokens and metadata that contains no content |
| Application | Laravel, as one application with strict module boundaries |
| Embeddings | A local model. Paid APIs are only an optional speed-up, and private text is never sent to one without the learner's opt-in |
| Graph engine | Not deployed in V1. The data model is graph-shaped so a graph copy can be added later |
| Graphiti | Not canonical infrastructure |
| Job queue | Laravel's database queue in V1 |

### Invariants

**I1. Qdrant only ever holds derived data.** Nothing stored only in Qdrant may be needed to reconstruct a Study Brain:
- Canonical text, evidence and provenance live in MySQL and Laravel Storage.
- Qdrant holds retrieval material only.
- Everything in Qdrant can be regenerated from MySQL, the source files and the learner's keys.

**I2. Qdrant never holds content text.**
- Qdrant's per-record metadata may only contain fields on a fixed allowlist, all of which never change and contain no content.
- Qdrant returns record IDs and scores. Content is always read from MySQL.

**I3. There is exactly one retrieval gateway.**
- Only the retrieval gateway talks to Qdrant.
- The learner scope is a required argument.
- Every result is re-fetched from MySQL through the learner-scoped data layer. A missing filter therefore returns fewer results, never another learner's data.

**I4. No readable copy of personal text exists outside the canonical store.** The only exceptions are:
- text held briefly in process memory;
- the learner's own export;
- briefs sent to the LLM the learner chose to use.

## Retrieval privacy design

Personal free text is encrypted in MySQL with a per-learner key. This covers questions, notes, mistakes, explanations, chat excerpts, mentions, private sources and personal concept names. Encrypted text cannot be full-text indexed by MySQL or any other database, so keyword search over it has to live in the retrieval index. That must not quietly create a readable second copy.

### Options considered

| | A. Plaintext in Qdrant | B. Minimal Qdrant, keyword search elsewhere | C. Keyed keyword tokens in Qdrant (chosen) |
|---|---|---|---|
| Exact search quality | Best | Best with a plaintext engine; strong but costly with encrypted per-learner index files | Equal for words, code identifiers and error codes. Phrases work through word-pair tokens plus a check against the real text. Weaker for prefix and typo matching |
| Sensitive data copied | A full plaintext copy | A plaintext copy (separate engine), or none (encrypted per-learner files) | No plaintext. Keyed tokens cannot be read without the learner's key |
| Erasure | Points are deleted, but plaintext stays on disk until Qdrant rewrites its storage, and stays in snapshots | Varies | Points are deleted and the key is destroyed, so any leftover tokens can no longer be linked to anyone |
| Backups and snapshots | Snapshots are plaintext copies | Varies | No Qdrant snapshots. An encrypted rebuild cache is used instead |
| Rebuildability | Yes | Yes | Yes. It needs the learner's key, which exists unless the learner has been erased |
| Operational complexity | Lowest | An extra service, or thousands of encrypted index files | A tokeniser and keyed hashing in PHP. No new service |

**Why A and B were rejected:**
- **Option A** makes encrypting text in MySQL meaningless.
- **Option B** would put keyword search in one of these places, each with a problem:
  - *MySQL full-text:* impossible on encrypted text without a plaintext shadow copy.
  - *A separate plaintext search engine:* a readable copy plus another service.
  - *Encrypted per-learner index files:* strong privacy, but every query must open and decrypt a learner's index, and there are thousands of files to manage. This is kept as a fallback if G1 shows that prefix and typo matching over personal text matter a great deal.
  - *Decrypting and scanning in memory:* perfect quality, but only for small sets. It is adopted as a supplement to C, described below.

### How option C works

- **Tokenising.** The tokeniser is versioned. It:
  - applies Unicode normalisation and lower-casing;
  - stems words by language, keeping both the exact form and the stem;
  - splits code identifiers, keeping the whole identifier as well as its camelCase or snake_case parts;
  - keeps numbers and error codes as tokens;
  - adds adjacent word pairs so phrases can be matched;
  - drops stop words.
- **Keyed hashing.** Each token is hashed with HMAC using a search key derived from the learner's key. The result is shortened to the 32-bit numbers Qdrant uses for keyword (sparse) vectors. The same word therefore hashes differently for every learner. Rare hash collisions only add extra candidates, and the check against the real text removes them.
- **Learner pseudonym.** Qdrant's tenant field holds a keyed pseudonym for the learner, not the learner's ID.
- **What Qdrant stores per record:**
  - an embedding;
  - a sparse vector of keyed tokens;
  - allowlisted metadata: learner pseudonym, record type, course ID, shared or private, when it happened, when it was recorded, language, embedding model version and tokeniser version.
- **Shared course material** is not personal. It goes through the same pipeline with a platform-wide search key, in a separate collection.
- **Query path:**
  1. The gateway tokenises and hashes the query with the learner's key.
  2. It runs embedding search and keyword search in Qdrant, filtered to that learner.
  3. It combines those results with structured MySQL results and graph neighbours.
  4. It fetches the candidates from MySQL and decrypts them in memory.
  5. Where the query asks for an exact phrase, it checks the phrase against the real text.
  6. It ranks the results.
- **Direct scan for narrow searches.** Sometimes the structured filters already narrow the search to a small set, as in "yesterday" or "the last lab". The gateway then also decrypts and scans those records directly, which gives exact substring, phrase and fuzzy matching without an index.
- **Isolation bonus.** Keyword matching cannot cross learners even if a learner filter were missing, because each learner's tokens are hashed with a different key.

### What remains imperfect

- **Embeddings are partly reversible.** Published research (for example Vec2Text, 2023) reconstructs most short texts from some embedding models. Embeddings are therefore treated as personal data: they are deleted on erasure and never kept in long-lived backups. This applies to any semantic search, pgvector included.
- **Keyed tokens reveal frequency.** They hide which words occur, but not how often each hidden token occurs. With language statistics, an attacker could guess some common words.
- **Qdrant deletes lazily.** It only removes deleted points from disk when it rewrites its storage segments. The erasure job forces that rewrite or waits for it, and G3 measures how long leftovers survive.
- **Optional extra protection, not in V1: a secret per-learner rotation of embeddings.**
  - Search results are mathematically unchanged.
  - Stolen or leftover vectors cannot be decoded with public tools and become meaningless once the key is destroyed.
  - This is obfuscation, not encryption. It will be decided after G3.

### Keys

- **The learner's key.** Each learner has a random data key, which is itself encrypted by a master key.
- **Separate keys for separate jobs.** Keys for content encryption, search tokens and cache encryption are derived from the learner's key (using HKDF).
- **The master key** is never stored in any database or database backup.
- **A separate key database.** The encrypted learner keys live in their own database, with a shorter backup retention period than the data backups. If keys sat in the same backups as the data, restoring an old backup would restore the key too, and deleting the key ("crypto-shredding") would not actually erase anything.
- **Key rotation.** Learner keys are not rotated in place. The master key is rotated by re-encrypting the learner keys under a new master key.
- **Erasure order.** The erasure job works out the learner's Qdrant pseudonym before it destroys the key.

### Keeping plaintext out of side channels

- Queue jobs carry record IDs, never text.
- Logs never contain personal text.
- Caches that hold personal text are either encrypted with the learner's cache key or kept for only a short time, and they are cleared on erasure.
- Retrieval logs and test labels are encrypted like any other personal text.

### Fast rebuild without snapshots

A per-learner rebuild cache holds each record's embedding and token weights, encrypted with that learner's cache key and kept in Laravel Storage. It lets Qdrant be rebuilt without recomputing embeddings. The cache is derived and disposable, and destroying the learner's key makes it unreadable.

### How I1 and I2 are enforced

- The gateway rejects any Qdrant metadata field that is not on the allowlist.
- No code path reads content from Qdrant.
- A regular drill deletes Qdrant, rebuilds it from canonical data, and re-runs the G1 test set.
- The canonical export contains no vectors, tokens or Qdrant snapshots.

## Options considered for the stack

### MySQL 8.4 + Qdrant (chosen)

The reasoning, in order:

1. **The permanent store was chosen on permanent-memory requirements alone.** On those, MySQL 8.4 and Postgres both pass. MySQL's gaps are inconveniences for this design, not blockers:
   - no row-level security;
   - no foreign keys on partitioned tables;
   - no built-in cycle detection in recursive queries;
   - weak built-in full-text search.
2. **When capabilities tie, the owner's existing choice and experience decide.** Running a database well for years is part of long-term maintainability.
3. **Retrieval needs something MySQL Community Edition lacks entirely.** A retrieval subsystem is needed whichever database is chosen.
4. **Encrypting personal text rules out keyword search inside any database.** The retrieval subsystem therefore has to handle both embedding search and keyword search.
5. **Qdrant covers the retrieval needs in one service.** It offers filtered embedding search, keyword (sparse vector) search, per-learner indexing and result fusion. It is self-hosted, Apache-2.0 licensed, and Laravel reaches it over REST, so no new application runtime is needed.
6. **Physical separation has benefits of its own.** It makes the boundary between canonical and derived data structural rather than a matter of discipline. It keeps search load away from journal writes. It keeps embeddings out of long-lived database backups.

**Costs accepted:**
- A second store in which learner isolation must hold. This is mitigated by I2, I3 and G2.
- An indexing pipeline, made of:
  - a to-do record written in the same transaction as the event (a transactional outbox);
  - an embedding worker;
  - retries;
  - monitoring of indexing lag;
  - a regular check that MySQL and Qdrant agree.
- Sizing Qdrant's memory and managing its upgrades.

### Postgres + pgvector (rejected)

**The strongest case for it:**
- one system to secure and back up;
- combined keyword, vector and relational searches in one SQL statement against current data;
- row-level security;
- the richest feature set of any single database;
- switching is cheapest now, before any data exists.

**Why it was rejected:**
- **It solved a search problem, not a storage problem.** Once search is a disposable subsystem, the permanent-store requirements no longer favour Postgres.
- **Its main advantage fades as the platform grows.** Keeping vectors beside the data is pgvector's selling point, but:
  - filtering searches down to one learner needs careful tuning;
  - large vector indexes compete with journal writes for memory and disk;
  - at hundreds of millions of vectors, they would move to a separate server or engine anyway.
- **Embeddings would sit in the main database's long-lived backups.** Embeddings have to stay searchable, so they cannot be encrypted with the learner's key, and destroying that key would not erase them.
- **Row-level security would cover only one of several leak paths.** Laravel must enforce isolation regardless.
- **It would reverse a deliberate owner decision** and require new operating skills.

**It would be reconsidered if:**
- before real users arrive, the team decides it would rather run Postgres;
- an institutional customer requires isolation enforced by the database itself;
- the core comes to need rich queries inside plugin JSON data.

### MySQL with embeddings stored in MySQL and exact search in PHP (rejected as the target)

It works at pilot scale but becomes too slow at platform scale. It needs throwaway PHP search code, including keyword search over encrypted text. It also puts embeddings in database backups.

It remains acceptable as a temporary fallback, because moving to Qdrant later means rebuilding an index, not migrating data.

### Deferring embeddings (rejected, subject to G1)

Vague references and questions repeated in different words are central to the product (criterion F). Matching mentions to concepts also needs similarity by meaning from the first day of real use. G1 can overturn this decision.

### A graph database as the source of truth (rejected)

- The journal's workload is a log, not a graph.
- Free editions lack operational features needed to look after years of irreplaceable data.
- Laravel would still need a relational database, leaving two primary databases.

### Graphiti (not canonical)

- An LLM decides what the facts are and when they stop being true, which conflicts with the evidence model.
- Rebuilding requires paid LLM re-extraction that does not give the same result twice.
- It adds a Python service and a graph database.

It may later be evaluated as an interpreter whose outputs enter the journal as proposals.

## Consequences

**Positive**

- The permanent store matches the owner's experience.
- The line between canonical and derived data is a physical boundary between systems.
- Erasing a learner reaches every long-lived copy of their content through key destruction, and Qdrant holds no plaintext.
- Search can scale independently of the permanent store.
- Qdrant, the rebuild cache and every other derived component can be deleted and rebuilt.

**Negative, and obligations for V1**

- **V1 must build all of these:**
  - the retrieval gateway and metadata allowlist;
  - the outbox, embedding worker and consistency check;
  - the tokeniser and keyed hashing;
  - the key database and key hierarchy;
  - the erasure job and erasure log;
  - the encrypted rebuild cache;
  - the canary isolation test suite;
  - retrieval logging and labelling.
- Prefix and typo matching over personal text is weaker than a plaintext search engine would give.
- There are two stores to secure and monitor.
- Losing the key database without a backup loses all personal content. Its backups must be robust even though they are kept only briefly.

## Validation gates

The pass thresholds below are proposals. The owner confirms them before any data is collected, so results cannot move the bar.

### G1: Retrieval value

- **Question:** do embeddings materially improve retrieval compared with structured filters plus keyword search?
- **Data:** the owner's real study activity.
  - Retrieval logs are captured automatically.
  - After study sessions, the owner labels test cases, marking which items must be retrieved, which must not be, and the category.
- **Categories:**
  1. vague or time-relative references ("that backwards thing from yesterday");
  2. repeated questions phrased differently;
  3. recurring misconceptions;
  4. prerequisite gaps across weeks;
  5. lecturer emphasis ("what the lecturer said would be on the exam");
  6. exact references to error messages, code identifiers or named terms. This is a control that checks keyword quality.
- **Method:**
  - Each case is replayed against the brain as it was at the original time. Every indexed record carries the time it was recorded, which makes this possible.
  - Two setups are compared on the same cases:
    - **Without embeddings (L):** structured filters, keyed keyword search, direct scans and graph neighbours.
    - **With embeddings (L+E):** everything in L plus embedding search.
  - Both get the same brief size limit, and the comparison is paired case by case.
  - Graph neighbours are included in both setups, so the comparison isolates what embeddings add.
- **Measures:**
  - the share of must-retrieve items that make it into the compiled brief, per category and overall;
  - how often must-not-retrieve items appear;
  - response time for the slowest 5% of requests.
- **Proposed pass:**
  - at least 150 labelled cases, collected over at least four weeks, with at least 20 per category;
  - the setup with embeddings beats the one without by at least 10 percentage points overall, and the lower end of a 95% bootstrap confidence interval is above zero;
  - at least 15 points better in at least two of categories 1–3;
  - no category more than 3 points worse;
  - no increase in must-not-retrieve items.
- **When:** after about 4–6 weeks of pilot use.
- **If it fails:** remove embeddings from V1 retrieval, and reassess whether Qdrant is still worth running for keyword search alone. The permanent-store decision is unaffected.

### G2: Tenant isolation

- **Question:** can any route put one learner's private data into another learner's context?
- **Setup:**
  - Synthetic learners A and B, plus a learner C who shares a course with both.
  - Each learner has unique random marker strings in every kind of personal text: questions, notes, mistakes, code, error messages, private sources, personal concept names and summaries.
  - Each learner also has a distinctive made-up concept, so that matching by meaning is tested as well as exact text.
  - A's queries are written to be close to B's content both in wording and in meaning.
- **Routes covered:**
  - every MCP tool and every HTTP endpoint;
  - the brief builder;
  - every part of the retrieval gateway: embedding search, keyword search, direct scans and graph neighbours;
  - caches, warmed as B and then queried as A;
  - background jobs such as summaries, interpretations and consolidation. A fake LLM provider records every prompt they send out, and the prompts are scanned;
  - long-running workers handling interleaved concurrent requests.
- **Assertions:**
  - B's markers never appear in any response, brief, outgoing LLM prompt, cache value or log line produced while acting as A.
  - Shared course material from C's course is retrievable by all three learners.
- **Testing the test:** test builds that deliberately break isolation must make the suite fail. The deliberate breaks are:
  - removing the learner filter;
  - turning off learner scoping when results are re-fetched;
  - reusing a cache key across learners.
- **Proposed pass:** zero leaks, ever. The suite runs in CI on every change that touches data access or retrieval, plus a nightly randomised run of at least 10,000 queries.
- **When:** from the first retrieval code onward.
- **If it fails:** releases are blocked. A leak caused by the design itself reopens the design of the component concerned.

### G3: Erasure drill

- **Question:** after a learner is erased, what remains, where, for how long, and why?
- **Setup:** a test learner with:
  - events and private sources;
  - embeddings and keyed tokens;
  - summaries and retrieval logs;
  - cache entries;
  - a graph copy, if one exists;
  - backups taken according to policy.
- **Procedure:**
  1. Run the erasure job.
  2. Search every store, file, log and cache for the learner's plaintext markers, learner ID and Qdrant pseudonym.
  3. Confirm that Qdrant returns no points for the pseudonym, and measure how long deleted data physically survives in Qdrant's storage.
  4. Restore the latest data backup into a scratch environment. Confirm that the erasure log is re-applied, and that the learner's content cannot be decrypted once the key backups have expired.
  5. Rebuild Qdrant and confirm the learner does not reappear.
- **Output:** the table of what remains after erasure (below), confirmed or corrected with measured values.
- **Proposed pass:**
  - After the job completes, no plaintext content remains anywhere, except the learner's own export and briefs already sent to external LLMs.
  - Everything that remains matches the documented policy and time limits.
- **When:** before the second real learner joins, again after any change to storage, backups or caching, and at least every quarter.
- **If it fails:** fix the affected path. If Qdrant's leftovers cannot be kept within a time limit, adopt the embedding rotation, or force storage rewrites or rebuilds.

### G4: Synthetic scale

- **Question:** does the MySQL + Qdrant split scale cleanly to the platform target?
- **Setup:**
  - A generator creates realistic multi-year histories:
    - heavy and light learners;
    - bursts of activity around exams;
    - a few concepts used very often and many rarely;
    - realistic text lengths and code snippets;
    - both shared and private courses.
  - It runs in stages, first 1,000 learners and then the 10,000-learner target.
  - It runs on agreed hardware that a self-hosting owner could realistically run.
- **Measures:**
  - accuracy of filtered retrieval, taking a full per-learner comparison of every vector as the correct answer;
  - retrieval time and brief-building time at increasing numbers of simultaneous requests, for typical, slow (95th percentile) and slowest (99th percentile) requests;
  - Qdrant memory and disk use, with and without vector compression;
  - how far indexing falls behind during a burst, and how long it takes to catch up;
  - rebuild time for one learner and for everyone, both from the encrypted cache and by recomputing embeddings;
  - MySQL write times with and without indexing load;
  - how long the erasure job takes.
- **Proposed pass:**
  - at least 95% of the correct top-10 results are found, compared with the full comparison;
  - 95% of retrievals finish within 300 ms, and 95% of briefs within 1 s, at 100 simultaneous requests;
  - during a burst of 5× normal activity, 95% of records are indexed within 60 s;
  - rebuilding one learner takes at most 1 minute, and rebuilding everyone from the cache at most 12 hours;
  - indexing load makes MySQL's slower writes (95th percentile) at most 10% slower;
  - memory use fits the agreed hardware.
- **When:** before opening beyond a small pilot group (proposed: 50 learners).
- **If it fails:**
  - Use Qdrant's own scaling options first: vector compression, storing vectors on disk, indexing larger chunks per record, and running across several servers.
  - Reopen the choice of search engine only if those options fail.
  - If MySQL writes slow down, fix the indexing pipeline; do not change the permanent store.

## What remains after erasure (the G3 hypothesis)

The retention periods here are proposals, for the owner to confirm. Erasure is the documented exception to append-only: a privileged maintenance process hard-deletes the learner's rows.

| Location | After the erasure job | What remains | For how long | Why |
|---|---|---|---|---|
| MySQL (live) | The learner's rows are hard-deleted, and their encrypted key is deleted | Nothing readable. Freed disk space may hold old encrypted bytes until it is reused | Until reused | Normal database behaviour, and the content is encrypted |
| MySQL change logs (binlogs) | — | Row changes: encrypted content plus non-content metadata | Change-log retention (proposed: 7 days) | Needed for replication and restoring to a point in time |
| MySQL data backups | — | Encrypted content, plus non-content metadata such as IDs, times, event types, links to shared concepts and outcomes | Backup retention (proposed: 35 days) | Disaster recovery. The content becomes unreadable once the key backups expire, and the erasure log is re-applied after any restore |
| Key database backups | — | The learner's encrypted key | Key backup retention (proposed: 7 days) | Once this expires, the learner's content in every data backup is permanently unreadable |
| Laravel Storage (sources, rebuild cache) | Deleted | Encrypted copies in storage backups or file versions | Storage backup retention (proposed: 35 days) | Unreadable once the key backups expire |
| Qdrant (live) | Points deleted by learner pseudonym | Deleted data still on disk until the storage is rewritten, plus Qdrant's write-ahead log | Measured by G3, and cut short by the erasure job | Tokens cannot be linked to anyone without the key. Embeddings are partly reversible, so this window must be short |
| Qdrant snapshots | None are taken, by policy | — | — | Qdrant is rebuilt from the encrypted cache instead |
| Caches | The learner's cache entries are cleared | Nothing, or encrypted entries until they expire | Up to the cache lifetime (proposed: 24 hours) | Performance |
| Job queue | — | Pending jobs hold only IDs, and fail harmlessly | Until processed | — |
| Logs | — | IDs and pseudonyms, never content | Log retention (proposed: 30 days) | Operations and security |
| Graph copy (if one exists) | Rebuilt without the learner | Nothing | — | It is never backed up |
| Erasure log | The learner's ID is added | The ID itself | Indefinitely | Needed to re-apply erasure after any restore |
| External LLM providers | Outside our control | Briefs already sent during chats | The provider's policy | Disclosed to learners |
| The learner's own export | Outside our control | The learner's copy | The learner's choice | The learner owns it |

## When to revisit this decision

- A validation gate fails. Each gate says what it reopens.
- Before real users arrive, the team decides it would rather run Postgres.
- An institution requires isolation enforced by the database itself.
- The core comes to need queries inside plugin JSON data.
- A graph-copy trigger from the design discussion fires. That adds a graph copy; it does not change this decision.

## Open parameters for the owner

- the pass thresholds for G1–G4;
- the retention periods in the table above;
- the target hardware for G4;
- how many learners the pilot group may have before G4 must pass;
- whether private text may ever be embedded by a paid API, with the learner's opt-in.
