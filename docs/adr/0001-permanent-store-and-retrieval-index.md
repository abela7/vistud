# ADR 0001: Permanent store and retrieval index

- **Status:** PROVISIONAL. It becomes ACCEPTED only when validation gates G1–G4 have passed. If a gate fails, this ADR is revised or replaced.
- **Date:** 2026-09-25
- **Revised:** 2026-09-25. Added phasing, canonical file storage and the processor boundary. Removed the encrypted rebuild cache. Changed the status wording.
- **Decision owner:** Project owner (project manager and system architect)

## Context

The Study Brain is a persistent learning memory that lives outside any LLM. ChatGPT, Claude, Gemini, local models and future LLMs read from it and propose updates to it through MCP and an HTTP API. The chat is disposable; the memory is not. The brain must cover years of a learner's education and grow into a multi-learner platform with subject and age plugins.

This ADR builds on memory-architecture principles agreed in earlier design discussion:

1. **Source material is never changed.** Everything the brain claims cites it.
2. **The source of truth is an append-only journal.** It holds observations (what happened) and recorded interpretations (judgements by an AI, a rule or a person, each saying who or what made it). Corrections, merges and reinterpretations are added as new events; nothing is overwritten.
3. **Learner state is always recalculated from the journal.** It is a pure function of the observations plus the interpretations currently in force. Rebuilding it never calls an LLM.
4. **The data model is graph-shaped but stored in relational tables.** Events, learning items and sources are joined by explicit typed links.
5. **LLMs propose changes; the brain validates them.** No LLM writes canonical state directly.
6. **Everything derived can be thrown away and rebuilt.**
7. **A canonical export can rebuild the brain without any of today's products.** It consists of versioned JSON Lines records, the source files and a written specification of the format.

**Constraints:**
- self-hosted;
- no required recurring cost for storing data;
- Laravel/PHP;
- MySQL chosen by the owner;
- web first, with a Flutter app and offline capture later;
- external LLMs connect through MCP and an API;
- plugins in future;
- platform scale, planned for 10,000 learners over several years.

**Non-negotiable criteria:**
- A. student ownership
- B. reconstructability
- C. chronological integrity
- D. evidence provenance
- E. LLM independence
- F. retrieval quality
- G. no required recurring storage cost
- H. Laravel compatibility
- I. mobile/offline readiness
- J. multi-user isolation
- K. plugin extensibility
- L. human inspectability
- M. privacy and erasure
- N. complexity must earn its place
- O. design for years, build for V1

## Decision

### Architecture boundary

| Layer | Choice |
|---|---|
| Application | Laravel, as one application with strict boundaries between modules. REST (for the web app and, later, Flutter) and MCP (for LLMs) call the same application services |
| Permanent structured memory | MySQL 8.4 LTS (MySQL 8.0 reached end of life in April 2026) |
| Permanent source files | Laravel Storage, on a dedicated canonical disk |
| Retrieval index | Qdrant, which can be deleted and rebuilt at any time |
| Embeddings | A local model |
| Data model | Graph-shaped, stored relationally |
| Graph database | None in V1 |
| Graphiti | Not part of the canonical infrastructure |
| Job queue | Laravel's database queue in V1 |

The choice of storage engines is now closed. It changes only if a validation gate fails or one of the revisit triggers below fires. Security hardening follows the phasing below and does not change the learning-memory model.

### Invariants

**I1. Canonical information exists only in MySQL and in canonical file storage.** Everything else is derived and can be thrown away: search indexes, embeddings, keyword tokens, summaries, caches, learner-state tables and any graph copy.

Machine-produced text that evidence cites, such as transcripts, OCR output and slide text, is canonical once it has been recorded. It is versioned and records which tool produced it, because citations must stay stable. Extracting the text again adds a new version and never overwrites the old one.

**I2. Qdrant never holds content.**
- Qdrant stores only fields on a fixed allowlist, and those fields never change and contain no content.
- Qdrant returns record IDs and scores. Content is always read from canonical storage.

**I3. All retrieval goes through one gateway.**
- The retrieval gateway is the only code that talks to Qdrant.
- The learner scope is a required argument.
- Every result is fetched again through the learner-scoped data layer. If a filter is ever missing, the result is fewer results, never another learner's data.

**I4. No readable copy of personal text exists outside canonical storage.** The only exceptions are:
- text held in process memory while it is being processed;
- the learner's own export;
- briefs sent to the LLM the learner chose to use.

**I5. Private data does not leave our infrastructure without explicit configuration and the learner's consent.** See [Processor boundary](#processor-boundary).

## Phasing

**The rule:** anything that shapes stored data is built on day one. Security mechanisms that can be applied to existing data later are built before other people's data arrives.

Deferring them is safe because, until then, the only data belongs to the owner. The owner can approve a one-time migration and let backups taken before the migration expire.

### Day-one design rules

These cost almost nothing now, and they are what make the deferred work a migration rather than a redesign.

1. **Personal free text lives only in designated content fields, stored separately from event metadata.** No SQL query may filter, search or sort on a content field. Encrypting that text later is then a column-level migration.
2. **Every private record carries the learner ID.** Every source and learning item is marked as shared or private.
3. **All retrieval goes through the gateway.** Qdrant fields are restricted to the allowlist, and Qdrant returns only IDs and scores.
4. **Every processor is called through the processor boundary.**
5. **Queue jobs carry IDs, not text, and logs never contain personal text.**
6. **Private files are stored per learner, through one canonical file service.** Identical files are not shared between learners (no cross-learner deduplication).

### What is built when

| Component | Day one (owner pilot) | Before any other real learner joins | Only if evidence requires it |
|---|---|---|---|
| Qdrant | ✓ | | |
| Semantic embeddings | ✓ (G1 depends on them) | | |
| Tokeniser and hashed keyword tokens | ✓, using one key for the whole installation | Per-learner keys (an index rebuild) | |
| Processor boundary | ✓ | | |
| Deleting a single record removes it from Qdrant and file storage | ✓ (also needed for corrections and redactions) | | |
| Canary isolation test | ✓, minimal: two synthetic learners tested through the gateway, the brief builder and MCP | The full G2 suite | |
| Application-level encryption of personal text and private files | | ✓ | |
| Per-learner keys and a separate key database | | ✓ | |
| Full learner erasure: the erasure job, the erasure log and a backup policy, verified by G3 | | ✓ | |
| G4 scale test | | Before going beyond about 50 learners | |
| Encrypted embedding rebuild cache | | | Only if G4 shows rebuilds from canonical text are too slow |
| Per-learner rotation of embedding vectors | | | Only if G3 shows the leftover data warrants it |

The work in the "before any other real learner" column is days of effort using Laravel's built-in encryption. It is not an enterprise key-management system.

## Retrieval privacy design

Personal free text is encrypted in canonical storage from the "before any other real learner" phase onward. This covers questions, notes, mistakes, explanations, chat excerpts, mentions, private sources and personal concept names.

Encrypted text cannot be full-text indexed by any database. Keyword search over it therefore has to live in the retrieval index, and that must not quietly create a readable second copy.

### Options considered

| | A. Plaintext in Qdrant | B. Minimal Qdrant, keyword search elsewhere | C. Keyed keyword tokens in Qdrant (chosen) |
|---|---|---|---|
| Exact search quality | Best | Best with a plaintext search engine. Strong but costly with encrypted per-learner index files | Equal for words, code identifiers and error codes. Phrases work through word-pair tokens plus a check against the real text. Weaker for prefix and typo matching |
| Sensitive data copied | A full plaintext copy | A plaintext copy (separate engine), or none (encrypted per-learner files) | No plaintext. The keyed tokens cannot be read without the learner's key |
| Erasure | Points are deleted, but plaintext stays on disk until Qdrant rewrites its storage | Varies | Points are deleted and the key is destroyed, so any leftover tokens can no longer be linked to anyone |
| Backups and snapshots | Snapshots are plaintext copies | Varies | No Qdrant snapshots are taken |
| Rebuildability | Yes | Yes | Yes, from canonical text and the learner's key |
| Operational complexity | Lowest | An extra service, or thousands of encrypted index files | A tokeniser and keyed hashing in PHP, with no new service |

**Why not A:** it makes encrypting the canonical text pointless.

**Why not B:** every place to put keyword search has a problem.
- MySQL full-text search cannot index encrypted text.
- A separate plaintext search engine is a readable copy plus another service.
- Encrypted per-learner index files mean decrypting a learner's index on every query and managing thousands of files. This remains the fallback if G1 shows that prefix and typo matching over personal text matter a great deal.
- Decrypting and scanning text in memory works well, but only for small sets. It is used alongside C, as described below.

### How option C works

**Tokenising.** The tokeniser has a version number. It:
- normalises Unicode and lower-cases text;
- stems words by language, keeping both the exact form and the stem;
- splits code identifiers into their camelCase or snake_case parts, and keeps the whole identifier too;
- keeps numbers and error codes as tokens;
- adds pairs of adjacent words so phrases can match;
- drops stop words.

**Keyed hashing.** Each token is hashed with HMAC under a search key. On day one there is one key for the whole installation. From the "before any other real learner" phase, the key is derived from the learner's own key, and switching over means rebuilding the index. The hash is cut down to the 32-bit number Qdrant uses for keyword entries. Rare collisions only add extra candidates, and the check against the real text removes them.

**Learner pseudonym.** Qdrant's field for separating learners holds a keyed pseudonym, not the learner ID.

**What Qdrant stores for each record:**
- an embedding;
- a keyword vector of hashed tokens;
- allowlisted fields only: the learner pseudonym, record type, course ID, shared or private, when it happened, when it was recorded, language, and the embedding-model and tokeniser versions.

**Shared course material** goes through the same pipeline with a key for the whole platform, in a separate collection.

**Answering a query:**
1. The gateway tokenises the query and hashes it with the learner's search key.
2. It runs embedding search and keyword search in Qdrant, filtered to that learner.
3. It combines those results with structured MySQL results and linked concepts from the graph.
4. It fetches the candidate records from canonical storage and decrypts them in memory.
5. Where the query needs an exact phrase, it checks the phrase against the real text.
6. It ranks the results.

**Direct scan for narrow searches.** Sometimes structured filters already narrow the candidates to a small set, as in "yesterday" or "the last lab". The gateway then also decrypts those records and scans them directly, which gives exact substring, phrase and fuzzy matching without any index.

**Isolation bonus.** Once keys are per learner, keyword matching cannot cross between learners even if a filter were missing.

**Rebuilding.** If Qdrant is lost, it is rebuilt from canonical text by computing the embeddings and tokens again. No rebuild cache is kept. One would be added only if G4 shows rebuilds are too slow.

### What remains imperfect

- **Embeddings are partly reversible.** Published research (for example Vec2Text, 2023) reconstructs most short texts from some embedding models. Embeddings are therefore treated as personal data: they are deleted on erasure and never kept in long-lived backups. This applies to any semantic search, including pgvector.
- **Keyed tokens reveal frequencies.** They hide which words occur, but not how often each hidden token occurs. With language statistics, an attacker could guess some common words.
- **Qdrant deletes lazily.** It only removes deleted points from disk when it rewrites its storage segments. The erasure job forces that rewrite or waits for it, and G3 measures how long leftovers survive.
- **Optional hardening, not in V1:** a secret per-learner rotation of the embeddings. Search results stay mathematically the same, but stolen or leftover vectors cannot be decoded with public tools and become meaningless once the key is destroyed. This is obfuscation, not encryption. It will be decided after G3.

## Source files

- **Where files live.** Canonical files sit on a dedicated Laravel Storage disk.
  - Private files are stored per learner and never shared between learners. A content hash is recorded to check integrity.
  - Shared course files can be deduplicated.
- **Encryption.** Private files are encrypted with the learner's content key, streamed as they are written. This happens in the same phase as encrypting personal text.
- **Extracted text.** Transcripts, OCR output and slide text are canonical once recorded, as stated in I1. Personal extracted text is treated as content and encrypted like any other personal text.
- **Derived file artifacts.** Previews, thumbnails and the audio segments cut up for transcription go on a separate derived disk. They are never backed up and can be deleted at any time.
- **Temporary files during processing.** These follow the processor-boundary rules below.
- **Erasure.** Live files and derived artifacts are deleted. Copies in storage backups expire under the retention policy. After the encryption phase, those copies become unreadable once the key backups expire.

## Processor boundary

Processors are the tools that work on learner data in the background: embedding models, transcription, OCR and text extraction, and background LLMs that write interpretations and summaries.

**Registry.** Every processor is registered with:
- its ID and version;
- a boundary: **local**, meaning it runs on our infrastructure, or **external**, meaning data leaves our infrastructure;
- where it runs;
- what it retains.

**Every input is classified** as private, shared or synthetic.

**One dispatch point enforces the rules, and anything not explicitly allowed is refused:**
- Private data goes only to local processors.
- Sending private data to an external processor needs both of these:
  1. an explicit setting in this installation's configuration that names that processor; and
  2. an active consent event from the learner, covering that processor and purpose.
- There is no automatic fallback from a local processor to an external one. If the local processor is unavailable, jobs wait.
- Every time private data is sent to an external processor, it is logged, and the learner can see that log.

**Local runtime requirements:**
- it runs on the same host or private network as the application;
- it has no outbound internet access;
- request and input logging is turned off;
- its filesystem is read-only, apart from memory-backed temporary storage;
- it has no persistent storage except the read-only model files;
- it keeps no cache of inputs or responses;
- it holds text in memory only while handling the request.

G3 checks this by searching the runtime's logs and filesystem for the canary markers.

**Provenance.** Every output a processor produces records the processor's ID and version.

**The learner's own chat LLM is not a processor.** Sending briefs to the chat LLM the learner is using is a disclosure the learner started. The retrieval log records which items were sent to which client.

## Keys (built before any other real learner joins)

- **Learner keys.** Each learner has a random data key, which is encrypted with a master key.
- **Separate keys for separate jobs.** Keys for content, search tokens and caches are derived from the learner's key (using HKDF).
- **The master key** is never stored in any database or database backup.
- **A separate key database.** The encrypted learner keys live in their own database, with a shorter backup retention period than the data backups. If keys sat in the same backups as the data, restoring an old backup would bring the key back and erasure would achieve nothing.
- **Rotation.** Learner keys are not rotated in place. The master key is rotated by re-encrypting the learner keys under the new master key.
- **Erasure order.** The erasure job works out the learner's Qdrant pseudonym before it destroys the key.

## Options considered for the stack

### MySQL 8.4 + Qdrant (chosen)

1. **The permanent store was chosen on permanent-memory requirements alone.** On those, MySQL 8.4 and Postgres both pass. MySQL's gaps are inconveniences for this design, not blockers:
   - no row-level security;
   - no foreign keys on partitioned tables;
   - no built-in cycle detection in recursive queries;
   - weak built-in full-text search.
2. **When capabilities tie, the owner's existing choice and experience decide.** Running a database well for years is part of long-term maintainability.
3. **A separate retrieval system is needed whichever database is chosen,** because free MySQL (Community Edition) has no vector search at all.
4. **Encrypting personal text rules out in-database keyword search in any database.** The retrieval system must therefore handle both embedding search and keyword search.
5. **Qdrant provides what retrieval needs in one self-hosted service:**
   - embedding search filtered by learner, course and time;
   - keyword search through sparse vectors;
   - separate indexing per learner;
   - combining several result lists into one ranking.

   It is licensed under Apache-2.0, and Laravel talks to it over REST, so no new application runtime is needed.
6. **Keeping Qdrant physically separate has three further benefits:**
   - the line between canonical and derived data is enforced by structure, not discipline;
   - search load cannot slow down journal writes;
   - embeddings stay out of long-lived database backups.

**Costs accepted:**
- **A second store where learner isolation must hold.** This is reduced by I2, I3 and G2.
- **An indexing pipeline to build and run:**
  - a transactional outbox, meaning a to-do record written in the same transaction as the event;
  - an embedding worker;
  - retries;
  - monitoring of how far indexing lags behind;
  - a regular check that MySQL and Qdrant agree.
- **Qdrant's memory sizing and upgrades** have to be managed.

### Postgres + pgvector (rejected)

**The strongest case for it:**
- one system to secure and back up;
- combined keyword, vector and relational searches in a single SQL query against current data;
- row-level security;
- the richest feature set of any single database;
- switching is cheapest now, before any data exists.

**Why it was rejected:**
- **The reason for switching was a search concern.** Once search is a separate system that can be rebuilt, the requirements for the permanent store no longer favour Postgres.
- **pgvector's main advantage fades as the platform grows:**
  - filtering searches down to a single learner needs careful tuning;
  - large vector indexes compete with journal writes for memory and disk;
  - at hundreds of millions of vectors they would move to a separate server or engine anyway.
- **Embeddings would sit in the main database's long-lived backups.** Embeddings must stay searchable, so they cannot be encrypted with the learner's key, and destroying that key would not remove them.
- **Row-level security would guard only one of several ways data could leak.** Laravel must enforce isolation regardless.
- **It would reverse a deliberate owner decision** and require new operating skills.

**We would reconsider if:**
- before real users arrive, the team decides it would rather run Postgres;
- an institutional customer requires isolation enforced by the database itself;
- the core comes to need rich queries inside plugin JSON data.

### MySQL with embeddings stored in MySQL and exact search in PHP (rejected as the target)

**Problems:**
- it works at pilot scale but becomes too slow at platform scale;
- it needs throwaway PHP search code, including keyword search over encrypted text;
- it puts embeddings into database backups.

**It remains an acceptable temporary fallback,** because moving to Qdrant later means rebuilding the index, not migrating data.

### Deferring embeddings (rejected, subject to G1)

Two things need matching by meaning from the first day of real use:
- vague references and questions asked again in different words, which are central to the product (criterion F);
- linking mentions in text to the concepts they refer to.

G1 can overturn this decision.

### A graph database as the source of truth (rejected)

- The journal's workload is a log, not a graph.
- Free editions of graph databases lack the operational features needed to look after years of irreplaceable data.
- Laravel would still need a relational database, which would leave two primary databases.

### Graphiti (not canonical)

**Problems:**
- An LLM decides what the facts are and when they stop being true. That conflicts with the evidence model.
- Rebuilding requires paid LLM re-extraction, which gives a different result each time.
- It adds a Python service and a graph database.

It may later be evaluated as an interpreter whose outputs enter the journal as proposals.

## Consequences

**Positive**

- The permanent store matches the owner's existing experience.
- The line between canonical and derived data is also a physical line between systems.
- Once encryption is in place, erasure reaches every long-lived copy of a learner's content through key destruction, and Qdrant holds no plaintext.
- Search can scale independently of the permanent store.
- Every derived component can be deleted and rebuilt from canonical data.

**Negative, and the obligations it creates**

- **Build on day one:**
  - the retrieval gateway and Qdrant field allowlist;
  - the outbox, embedding worker and MySQL–Qdrant consistency check;
  - the tokeniser and keyed hashing;
  - the processor boundary;
  - propagation of single-record deletions;
  - the minimal canary test;
  - retrieval logging and labelling.
- **Build before any other real learner joins:**
  - encryption of personal text and private files;
  - per-learner keys and the separate key database;
  - the full erasure job and erasure log;
  - the full G2 suite and G3.
- **Weaker keyword matching.** Prefix and typo matching over personal text is weaker than a plaintext search engine would give.
- **More to run.** There are two stores to secure and monitor.
- **The key database is critical.** Once encryption is in place, losing it without a backup means losing all personal content. Its backups must be reliable even though they are kept only for a short time.

## Validation gates

The pass thresholds below are proposals. The owner confirms them before any data is collected, so that results cannot move the bar.

### G1: Retrieval value

**Question:** do embeddings materially improve retrieval compared with structured filters plus keyword search?

**Data:** the owner's real study activity.
- Retrieval logs are captured automatically.
- After study sessions, the owner labels test cases. For each case they mark which items must be retrieved, which must not be, and its category.

**Categories:**
1. vague or time-relative references, such as "that backwards thing from yesterday";
2. repeated questions asked in different words;
3. recurring misconceptions;
4. gaps in prerequisites across weeks;
5. lecturer emphasis, such as "what the lecturer said would be on the exam";
6. exact references to error messages, code identifiers or named terms (a control that checks keyword search quality).

**Method:**
- Each case is replayed against the brain as it was at the original time. This is possible because every indexed record carries the time it was recorded.
- Two setups are compared:
  - **without embeddings:** structured filters, keyed keyword search, direct scans and linked concepts from the graph;
  - **with embeddings:** the same, plus embedding search.
- Both setups get the same brief size limit, and each case is compared across the two.
- Linked concepts from the graph are used in both setups, so the comparison isolates what embeddings add.

**Measurements:**
- the share of must-retrieve items that reach the compiled brief, per category and overall;
- how often must-not-retrieve items appear;
- response time for the slowest 5% of requests.

**Proposed pass:**
- at least 150 labelled cases, collected over at least four weeks, with at least 20 in each category;
- the setup with embeddings retrieves at least 10 percentage points more overall, and the lower end of a 95% bootstrap confidence interval for that difference is above zero;
- at least 15 percentage points more in at least two of categories 1–3;
- no category more than 3 percentage points worse;
- no increase in must-not-retrieve items.

**When:** after about 4–6 weeks of pilot use.

**If it fails:** embeddings are removed from V1 retrieval, and we reassess whether Qdrant is still worth running for keyword search alone. The choice of permanent store is unaffected.

### G2: Tenant isolation

**Question:** can any route put one learner's private data into another learner's context?

**Setup:**
- Synthetic learners A and B, plus learner C, who shares a course with both.
- Each learner has unique random marker strings in every kind of personal text: questions, notes, mistakes, code, error messages, private sources, personal concept names and summaries.
- Each learner also has a distinctive made-up concept, so that matching by meaning is tested, not just exact text.
- A's queries are written to be close to B's content in both wording and meaning.

**Routes tested:**
- every MCP tool and every HTTP endpoint;
- the brief builder;
- each retrieval route in the gateway: embedding search, keyword search, direct scans and linked graph concepts;
- caches, filled while acting as B and then queried as A;
- background jobs, such as summaries, interpretations and consolidation. A fake LLM provider captures every prompt these jobs send out, and the captured prompts are scanned;
- long-running workers handling interleaved, concurrent requests.

**What is checked:**
- B's markers never appear in any response, brief, outgoing LLM prompt, cache value or log line produced while acting as A.
- Shared course material from C's course is retrievable by all three learners.

**Testing the test:** builds with isolation deliberately broken must make the suite fail. The deliberate breaks are:
- removing the learner filter;
- turning off learner scoping when results are fetched again;
- reusing a cache key across learners.

**Proposed pass:** zero leaks, ever.

**When:**
- a minimal version from the first retrieval code onward;
- the full suite before any other real learner joins;
- then on every change that touches data access or retrieval, plus a nightly randomised run of at least 10,000 queries.

**If it fails:** releases are blocked. A leak caused by the design itself reopens the design of that component.

### G3: Erasure drill

**Question:** after a learner is erased, what remains, where, for how long, and why?

**Setup:** a test learner with all of the following, plus backups taken according to policy:
- events;
- private source files and derived file artifacts;
- embeddings and keyed tokens;
- summaries;
- retrieval logs;
- cache entries;
- a graph copy, if one exists.

**Procedure:**
1. Run the erasure job.
2. Search every store, file, log and cache for the learner's plaintext markers, their learner ID and their Qdrant pseudonym. Include the processors' logs and filesystems.
3. Confirm that Qdrant returns no points for the pseudonym, and measure how long deleted data physically survives in Qdrant's storage.
4. Restore the latest data backup into a scratch environment. Confirm that the erasure log is re-applied, and that the learner's content cannot be decrypted once the key backups have expired.
5. Rebuild Qdrant and confirm the learner does not reappear.

**Output:** the table in [What remains after erasure](#what-remains-after-erasure-the-g3-hypothesis), confirmed or corrected with measured values.

**Proposed pass:**
- once the job completes, no plaintext content remains anywhere except the learner's own export and briefs already sent to external LLMs;
- everything that remains matches the documented policy and time limits.

**When:** before any other real learner joins, after any change to storage, backups, caching or processors, and at least every quarter.

**If it fails:** fix the affected path. If Qdrant's leftover data cannot be kept within a time limit, adopt the embedding rotation or force storage rewrites.

### G4: Synthetic scale

**Question:** does the MySQL + Qdrant split scale cleanly to the platform target?

**Setup:**
- A generator produces realistic multi-year histories:
  - heavy and light learners;
  - bursts of activity before exams;
  - a few concepts used very often and many used rarely;
  - text lengths and code snippets drawn from realistic distributions;
  - both shared and private courses.
- The test runs first with 1,000 learners, then with the 10,000-learner target.
- It runs on agreed hardware that someone self-hosting could realistically own.

**Measurements:**
- **Search accuracy:** filtered retrieval compared against an exact search over every one of the learner's records.
- **Speed:** retrieval and brief-building times at increasing numbers of simultaneous requests, for the median, the slowest 5% and the slowest 1%.
- **Resources:** Qdrant memory and disk use, with and without vector compression.
- **Indexing:** how far indexing falls behind during a burst, and how long it takes to catch up.
- **Rebuilds:** time to rebuild from canonical text for one learner and for everyone.
- **MySQL:** write times with and without indexing load.
- **Erasure:** how long the erasure job takes.

**Proposed pass:**
- at least 95% of the correct top-10 results are found, measured against exact search;
- 95% of retrievals finish within 300 ms and 95% of briefs within 1 s, at 100 simultaneous requests;
- during a burst of five times normal activity, 95% of new records are indexed within 60 s;
- rebuilding one learner from canonical text takes at most 5 minutes, and rebuilding everyone at most 48 hours;
- indexing load makes MySQL's slowest 5% of writes at most 10% slower;
- memory use fits the agreed hardware.

**When:** before the platform grows beyond a small pilot group (proposed: 50 learners).

**If it fails:**
- Try Qdrant's own scaling options first:
  - compressing vectors;
  - keeping vectors on disk;
  - indexing larger chunks per record;
  - running Qdrant across several servers.
- Reopen the choice of search engine only if those options fail.
- If rebuilds are too slow, add the encrypted rebuild cache or GPU-accelerated embedding.
- If MySQL writes slow down, fix the indexing pipeline rather than changing the permanent store.

## What remains after erasure (the G3 hypothesis)

This table applies once the encryption phase is in place. Before that, backups hold plaintext until they expire. At that stage the only data is the owner's.

The retention periods are proposals for the owner to confirm. Erasure is the documented exception to append-only: a privileged maintenance process hard-deletes the learner's rows and files.

| Location | After the erasure job | What remains | For how long | Why |
|---|---|---|---|---|
| MySQL (live) | The learner's rows are hard-deleted, and their encrypted key is deleted | Nothing readable. Freed disk pages may still hold old encrypted bytes until they are reused | Until the pages are reused | Normal database behaviour, and the content is encrypted |
| MySQL change logs (binlogs) | — | Row changes: encrypted content plus metadata that isn't content | Change-log retention (proposed: 7 days) | Needed for replication and point-in-time restore |
| MySQL data backups | — | Encrypted content, plus metadata that isn't content: IDs, times, event types, links to shared concepts, outcomes | Backup retention (proposed: 35 days) | Disaster recovery. The content becomes unreadable once the key backups expire, and the erasure log is re-applied on any restore |
| Key database backups | — | The learner's encrypted key | Key backup retention (proposed: 7 days) | Once this expires, the learner's content in every data backup is permanently unreadable |
| Canonical file storage | Deleted | Encrypted copies in storage backups or older file versions | Storage backup retention (proposed: 35 days) | Unreadable once the key backups expire |
| Derived file storage | Deleted | Nothing | — | Never backed up |
| Qdrant (live) | Points deleted by learner pseudonym | Deleted data still on disk until Qdrant rewrites its storage segments, plus its write-ahead log | Measured by G3, and cut short by the erasure job | The tokens cannot be linked to anyone without the key. Embeddings are partly reversible, so this window must be short |
| Qdrant snapshots | None are taken, by policy | — | — | Qdrant is rebuilt from canonical text instead |
| Local processors | — | Nothing (checked by G3) | — | No logging, no persistent storage, memory-only temporary files |
| Caches | The learner's entries are flushed | Nothing, or encrypted entries until they expire | Up to the cache lifetime (proposed: 24 hours) | Performance |
| Job queue | — | Pending jobs hold only IDs, and fail harmlessly | Until they are processed | — |
| Logs | — | IDs and pseudonyms, never content | Log retention (proposed: 30 days) | Operations and security |
| Graph copy, if one exists | Rebuilt without the learner | Nothing | — | Never backed up |
| Erasure log | The learner's ID is added | The ID itself | Indefinitely | Needed to re-apply the erasure after any restore |
| External processors (only with consent) | Outside our control | Whatever was sent, as recorded in the disclosure log | The provider's policy | The learner consented to this explicitly |
| External chat LLMs | Outside our control | Briefs already sent during chats | The provider's policy | The learner started these disclosures, and we tell them so |
| The learner's own export | Outside our control | The learner's copy | The learner's choice | The learner owns it |

## When to revisit this decision

- **A validation gate fails.** Each gate says what it reopens.
- **The team would rather run Postgres.** This counts only if decided before real users arrive.
- **An institution requires isolation enforced by the database itself.**
- **The core needs queries inside plugin JSON data.**
- **A graph-copy trigger fires.** These triggers come from the design discussion. That would add a graph copy of the data; it would not change this decision.

## Open parameters for the owner

- the G1–G4 pass thresholds;
- the retention periods;
- the target hardware for G4;
- how many learners the pilot group may have before G4 must pass;
- whether private text may ever be sent to an external processor, and if so for which purposes.
