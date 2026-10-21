# S8: joining, and a student's own AI key

No migration, key conversion, or erasure was run against Abel's installed
database or private files. `vistud:encrypt:ai-keys` was not run. No push.
The second-learner gate stays closed. S7 replaces the privacy placeholder
and finishes the gate's wording.

## What a student can do

An admin makes a class link with a number of places and a time it stops.
Someone opening it accepts the current privacy note, creates an account, and
must confirm their email before they can continue. The link counts a use when
the account is created. A full, expired, or switched-off link is refused.
Emailed invitations still work, one person each, for 72 hours.

After the email is confirmed, the student adds their own AI key. A normal
login still opens home. Only that joining step sends them to add a key.

`docs/legal/privacy.md` is a short draft. It starts with the line that it is a
placeholder until S7 and needs a human legal review. Joining records the
version it showed. When S7 replaces the file, that version changes and a new
student has to accept the new text. That is expected.

## Keys

`Settings::credential()` is the only place that chooses a key. A student with
their own key uses that. An admin with none uses the installation key. Anyone
else gets an empty credential. OpenRouter does not fill a missing key in from
setup. A call with no key fails as `engine_key` ("Add your key in your AI
settings first.").

Doctor, the models command, Prompts try, and the admin engine test pass the
installation key themselves. The admin settings page still says "The owner's
key is in use."

A saved key is sealed as `vsk1:` under the learner's content key. A row still
in the old APP_KEY form is refused until the offline conversion. Erasure
clears a provider's private model list from that sealed key while the learner
key still exists. An OpenRouter key shares the installation catalogue, so
erasure does not drop that shared list.

The class recorder says one of two lines. With no key: "Add your AI key in
settings to write this class down." With a key whose provider cannot hear
audio: "Your AI provider can't write down recordings. An OpenRouter key can."

## Drafts after erasure

The editor sends `X-Account-Id` with each draft save. Auth rejects a missing
user before the rest of the pipeline, so a save whose login is gone is turned
into `401 account_deleted` when that account is in `erasure_log` (the session
login or the header). The editor then drops that account's drafts. G3 passes
without edits to `tests/Acceptance`.

## Choices

The acceptance world names its learners "Student A" and so on, then chats.
That file is the validator's and was not edited. `CreatesAccounts::student()`
gives a key of their own only to those names, so the suite still runs and
never sends the installation key. Any other `student()` stays without one.

Fortify's confirm-email address is `/email/verify/{id}/{token}`. The hash is
`token` because the G2 route sweep can fill that name and cannot fill `hash`.
The link is still signed.

The gate command now checks a synthetic non-admin with no key and still prints
`result: closed` and exits with a failure. The older "keys loaded: no" lines
are unchanged for S7.

## Setup for Abel (not executed here)

During local setup, and only while the app is offline and workers are stopped,
run `php artisan vistud:encrypt:ai-keys`. Do not run it before that, and do
not run it against a database that is serving students. `--dry-run` prints
counts only. `--decrypt` rolls a `vsk1:` row back to the old form, also only
while offline.

Apply the class-link migration with the usual test or local migrate. It was
not applied to the installed `vistud` database.

## Left for S7

Replace `docs/legal/privacy.md` with the real policy and have a person check
it. Then make the gate report what is actually true (per-learner keys, stored
text and files, G2 and G3 passing) and only then consider opening it. S8 does
not open it.

## Rollback

While no class link has been used and no account has a privacy acceptance,
the migration's `down` removes the new columns. After either of those exists
it refuses, so those rows are kept on purpose. Key conversion rolls back with
`vistud:encrypt:ai-keys --decrypt` while offline. Reverting this commit
returns OpenRouter to filling in the installation key.

## Validation

PHP 8.4 from `.tools/php`, `DB_PORT=3307`, and a temp directory outside the
checkout (the bundled `php.ini` points `sys_temp_dir` inside the tree, which
the erasure ledger rejects). Synthetic databases `vistud_test` and
`vistud_keys_test` only.

S8 tests, G3, and the G2 HTTP surface passed on their own. The full suite:
**1,580 tests, 15,607 assertions, 1 skipped.** The only error is the known
`AssignmentsTest::test_its_folder_follows_its_name_and_its_module_and_stays_with_its_files_when_it_goes`.
No other failure. `npm run build` was run after the draft-save script change.
Built files stay gitignored.
