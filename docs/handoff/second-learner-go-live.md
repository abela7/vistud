# Second learner: go-live validation

## Linux CI

The local branch `claude-latest` tracks GitHub branch `claude/persistent-study-context-zsilo6`; querying GitHub for `claude-latest` returns no runs. The [current CI run for aa9b4d3](https://github.com/abela7/vistud/actions/runs/39682862971) failed Pint before PHPUnit and its browser job is still running. The previous run is also in progress.

Six Pint errors were reported: `tests/Concerns/JoiningPolicyFixture.php`, `tests/Feature/Engine/StudentKeysTest.php`, `tests/Feature/Identity/InvitationsTest.php` and `tests/Feature/Web/AcceptInvitationScreenTest.php` were added or made noncompliant in S8 and are formatted in this change. `tests/Feature/Engine/FolderSessionTest.php` and `tests/Feature/Web/ProgressScreenTest.php` failed before S1 and remain unchanged. As long as those two remain, CI Pint still blocks PHPUnit. Local Pint passed on all four changed PHP files; 17 relevant feature tests passed with 119 assertions on the scratch test databases.

The [pre-S1 Linux run](https://github.com/abela7/vistud/actions/runs/39350550113) had 136 browser failures. The [pre-S2 run](https://github.com/abela7/vistud/actions/runs/39484524942) had 135, all with names already in the pre-S1 list, including assignments and background tabs. This is evidence that these named failures predate S1; a matching name alone does not prove later failures have the same cause. The latest Linux run's full comparison is **pending** until its browser job finishes. The exact pre-S1 failure names are listed below.

## Isolated browser validation

Used a separate checkout with its own `.env`, synthetic master key, storage and temp paths; owner connections were verified as `vistud` and `vistud_keys` on port 3308 before migration. The scratch databases were empty before migration. Browser server used port 18080, not the existing server on 8000. The bundled PHP configuration's upload temp points into the installed app; a scratch-only PHP configuration overrode both `upload_tmp_dir` and `sys_temp_dir` before browser testing. AI requests were directed to loopback; no real AI was tested.

- Note autosave and reload: passed.
- Deleted account draft purge: passed against the real middleware after marking a synthetic account deleted; observed `401 account_deleted` and removal of that account's IndexedDB draft. The existing mocked-response test also passed.
- Recording: passed a scratch-only browser scenario using a fake microphone, upload, actual audio playback, a 100-byte HTTP 206 range request and browser seek.
- File upload/PDF preview: the unchanged existing test failed on an obsolete list locator, as it had before S1; after adapting the locators only in the scratch copy to the current page, the browser uploaded the files, rendered a PDF canvas and received decrypted PDF bytes. The unchanged test also expects an iframe where the current viewer uses a canvas. Other formats were not proven by that adjusted scenario.
- The complete scratch browser suite is **running**. Results will be added when it finishes. It found that the S8 class-link date input overflowed the Accounts page at 320 px with 200% text; the field is now constrained and that browser test passes on rerun.

## Server

See [go-live-checklist.md](../development/go-live-checklist.md). No server settings, mail delivery, backup schedules or restore drill were verified here. Abel must arrange the policy review. `vistud:gate:record-acceptance` invokes `vendor/bin/phpunit`; PHPUnit is a Composer dev dependency, so the documented production `composer install --no-dev` by itself cannot run it. The isolated acceptance test databases, test-only credentials and outside-checkout temp directory must also be provisioned and verified on the server. The acceptance child removes variable names defined in the application `.env` and falls back to `phpunit.xml` for database credentials, except for host/port; the production test-credential arrangement is therefore unverified and must be checked before running it. Do not assume production `.env` credentials reach the child. A new commit makes the acceptance record stale; re-record acceptance after the final commit. The already-converted local database dump must travel with its matching learner-key database and original master key, not newly provisioned keys.

## Pre-S1 browser failure names

These are the failures from the pre-S1 run, not a claim that all still fail for the same reason:

- `assignments.spec.js`: axe finds no violations on the assignment pages
- `assignments.spec.js`: plan on a computer › an assignment's plan is made, ticked, checked and changed (computer)
- `assignments.spec.js`: plan on a phone › an assignment's plan is made, ticked, checked and changed (phone)
- `assignments.spec.js`: the plan never scrolls sideways at 320 px, even with 200% text
- `background-tabs.spec.js`: a file opened in a background tab asks for nothing until it is seen
- `background-tabs.spec.js`: when every conversion slot is taken the server says so at once, and the page asks again
- `briefing.spec.js`: axe finds no violations in the briefing and teaching: blossom
- `briefing.spec.js`: axe finds no violations in the briefing and teaching: blossom-dark
- `briefing.spec.js`: axe finds no violations in the briefing and teaching: ember
- `briefing.spec.js`: axe finds no violations in the briefing and teaching: vistud-dark
- `briefing.spec.js`: axe finds no violations in the briefing and teaching: vistud-light
- `briefing.spec.js`: every colour of the briefing and teaching comes from a token: desktop
- `briefing.spec.js`: every colour of the briefing and teaching comes from a token: phone
- `briefing.spec.js`: how the AI teaches is chosen at the start and changed during the session
- `briefing.spec.js`: the briefing is copied and downloaded, with the note the student chose
- `briefing.spec.js`: the briefing never scrolls the page sideways at 320 px, even with 200% text
- `capture.spec.js`: axe finds no violations in Save from the chat: blossom
- `capture.spec.js`: axe finds no violations in Save from the chat: blossom-dark
- `capture.spec.js`: axe finds no violations in Save from the chat: ember
- `capture.spec.js`: axe finds no violations in Save from the chat: vistud-dark
- `capture.spec.js`: axe finds no violations in Save from the chat: vistud-light
- `capture.spec.js`: every colour of Save from the chat comes from a token: desktop
- `capture.spec.js`: every colour of Save from the chat comes from a token: phone
- `capture.spec.js`: the review never scrolls sideways at 320 px, even with 200% text
- `capture.spec.js`: the tutor's marks are pasted, reviewed and saved where they belong
- `chat.spec.js`: the answer streams in as it is written, then stays as a turn
- `devices-v2.spec.js`: computer-1440 › every rebuilt page fits a computer-1440
- `devices-v2.spec.js`: phone-320 › every rebuilt page fits a phone-320
- `devices-v2.spec.js`: phone-390 › every rebuilt page fits a phone-390
- `devices-v2.spec.js`: tablet-1024 › every rebuilt page fits a tablet-1024
- `devices-v2.spec.js`: tablet-768 › every rebuilt page fits a tablet-768
- `devices.spec.js`: phone-320 › everything new works on a phone-320
- `devices.spec.js`: phone-390 › everything new works on a phone-390
- `devices.spec.js`: tablet-1024 › everything new works on a tablet-1024
- `devices.spec.js`: tablet-768 › everything new works on a tablet-768
- `files.spec.js`: Take notes opens the file's own note beside it, in the same place, and moves it to a window saved
- `files.spec.js`: a Markdown file is shown as it was meant to look, and opens as a note
- `files.spec.js`: a file page never scrolls sideways at 320 px, even with 200% text
- `files.spec.js`: a student uploads files; the ones that aren't safe are refused with the reason
- `files.spec.js`: axe finds no violations in uploads and on file pages: blossom
- `files.spec.js`: axe finds no violations in uploads and on file pages: blossom-dark
- `files.spec.js`: axe finds no violations in uploads and on file pages: ember
- `files.spec.js`: axe finds no violations in uploads and on file pages: vistud-dark
- `files.spec.js`: axe finds no violations in uploads and on file pages: vistud-light
- `files.spec.js`: every colour in the upload dialog and on a file page comes from a token: desktop
- `files.spec.js`: every colour in the upload dialog and on a file page comes from a token: phone
- `files.spec.js`: the file page previews a PDF or an image, and offers other files as a download
- `flashcards.spec.js`: a module page lists its topics and a topic is added there: desktop
- `flashcards.spec.js`: a module page lists its topics and a topic is added there: phone
- `flashcards.spec.js`: flashcards never scroll sideways at 320 px, even with 200% text
- `folders.spec.js`: Whole folder starts a session named for the folder, which keeps to its topics and goes back to it
- `folders.spec.js`: a folder is a place to study: the module above it, Study this, its own tabs and topics: desktop
- `folders.spec.js`: a folder is a place to study: the module above it, Study this, its own tabs and topics: phone
- `folders.spec.js`: a module's Topics tab shows its folders, each with its own Study, and a topic moves between them
- `folders.spec.js`: axe finds no violations on a folder's tabs, a module's folders and a folder's session: blossom
- `folders.spec.js`: axe finds no violations on a folder's tabs, a module's folders and a folder's session: blossom-dark
- `folders.spec.js`: axe finds no violations on a folder's tabs, a module's folders and a folder's session: ember
- `folders.spec.js`: axe finds no violations on a folder's tabs, a module's folders and a folder's session: vistud-dark
- `folders.spec.js`: axe finds no violations on a folder's tabs, a module's folders and a folder's session: vistud-light
- `folders.spec.js`: every colour on a folder's tabs, a module's folders and a folder's session comes from a token: desktop
- `folders.spec.js`: every colour on a folder's tabs, a module's folders and a folder's session comes from a token: phone
- `invitations.spec.js`: desktop: an admin invites someone, who accepts the link once in another browser
- `invitations.spec.js`: phone: an admin invites someone, who accepts the link once in another browser
- `mcp-apps.spec.js`: cards at 320px, dark, has no axe violations ─
- `mcp-apps.spec.js`: cards at 320px, light, has no axe violations
- `mcp-apps.spec.js`: confirm at 320px, dark, has no axe violations
- `mcp-apps.spec.js`: confirm at 320px, light, has no axe violations
- `mcp-apps.spec.js`: quiz at 320px, dark, has no axe violations ──
- `mcp-apps.spec.js`: quiz at 320px, light, has no axe violations ─
- `mcp-apps.spec.js`: session at 320px, dark, has no axe violations
- `mcp-apps.spec.js`: session at 320px, light, has no axe violations
- `mcp-apps.spec.js`: topic at 320px, dark, has no axe violations ─
- `mcp-apps.spec.js`: topic at 320px, light, has no axe violations
- `module-page.spec.js`: axe finds no violations on the module page, its files and the file page: desktop, blossom
- `module-page.spec.js`: axe finds no violations on the module page, its files and the file page: desktop, blossom-dark
- `module-page.spec.js`: axe finds no violations on the module page, its files and the file page: desktop, ember
- `module-page.spec.js`: axe finds no violations on the module page, its files and the file page: desktop, vistud-dark
- `module-page.spec.js`: axe finds no violations on the module page, its files and the file page: desktop, vistud-light
- `module-page.spec.js`: axe finds no violations on the module page, its files and the file page: phone, blossom
- `module-page.spec.js`: axe finds no violations on the module page, its files and the file page: phone, blossom-dark
- `module-page.spec.js`: axe finds no violations on the module page, its files and the file page: phone, ember
- `module-page.spec.js`: axe finds no violations on the module page, its files and the file page: phone, vistud-dark
- `module-page.spec.js`: axe finds no violations on the module page, its files and the file page: phone, vistud-light
- `module-page.spec.js`: every colour on the module page and the file page comes from a token: desktop
- `module-page.spec.js`: every colour on the module page and the file page comes from a token: phone
- `module-page.spec.js`: files say whether the AI has read them, and a topic opens on a sheet: desktop
- `module-page.spec.js`: files say whether the AI has read them, and a topic opens on a sheet: phone
- `module-page.spec.js`: the module page has a context line, Study this and five tabs, and the topics say how they stand: desktop
- `module-page.spec.js`: the module page has a context line, Study this and five tabs, and the topics say how they stand: phone
- `module-page.spec.js`: what the reader found is one line: Add all puts the topics in the module: desktop
- `module-page.spec.js`: what the reader found is one line: Add all puts the topics in the module: phone
- `modules.spec.js`: a student adds modules as cards, reorders them, and opens one to build folders inside
- `modules.spec.js`: axe finds no violations in modules: blossom ─
- `modules.spec.js`: axe finds no violations in modules: blossom-dark
- `modules.spec.js`: axe finds no violations in modules: ember ───
- `modules.spec.js`: axe finds no violations in modules: vistud-dark
- `modules.spec.js`: axe finds no violations in modules: vistud-light
- `modules.spec.js`: every colour in modules comes from a token: desktop
- `modules.spec.js`: every colour in modules comes from a token: phone
- `modules.spec.js`: moving, renaming and deleting folders, and the refusals
- `notes.spec.js`: Word shortcuts act once, a picture is kept with the note, and the side panels pass axe
- `notes.spec.js`: a formula from the Σ menu: written in LaTeX, drawn in the note, changed and removed
- `notes.spec.js`: a note moves to a window of its own, beside the study material, and back into ViStud
- `notes.spec.js`: drafts kept at logout come back for the same account; discarded ones do not
- `notes.spec.js`: every colour on a note comes from a token: desktop
- `notes.spec.js`: every colour on a note comes from a token: phone
- `notes.spec.js`: the toolbar menus and the table bar: colours from tokens, and axe finds nothing
- `notes.spec.js`: with the server unreachable the draft survives a reload, and saves once it is back
- `progress.spec.js`: Study on a row starts the session on that topic
- `progress.spec.js`: a topic opens its sheet: who set the status, key points, and removing it
- `questions.spec.js`: a module page's New adds a question, on the module's questions page
- `questions.spec.js`: a question is kept from a session, is on the module's Questions tab, and gets answered there
- `scroll-strips.spec.js`: a row of tabs wider than the screen says so, and moves with the wheel and a mouse drag
- `sessions.spec.js`: a Pomodoro focus period runs out on its own and the break begins, here and in the top bar
- `sessions.spec.js`: a Pomodoro session is started from Home, and the sound can be turned off
- `sessions.spec.js`: a session is started from Home, and its clock runs, pauses, breaks and ends
- `sessions.spec.js`: a session page never scrolls sideways at 320 px, even with 200% text
- `sessions.spec.js`: another tab follows a pause straight away ──
- `sessions.spec.js`: axe finds no violations on a session: blossom
- `sessions.spec.js`: axe finds no violations on a session: blossom-dark
- `sessions.spec.js`: axe finds no violations on a session: ember
- `sessions.spec.js`: axe finds no violations on a session: vistud-dark
- `sessions.spec.js`: axe finds no violations on a session: vistud-light
- `sessions.spec.js`: axe finds no violations on the Pomodoro clock: blossom
- `sessions.spec.js`: axe finds no violations on the Pomodoro clock: blossom-dark
- `sessions.spec.js`: axe finds no violations on the Pomodoro clock: ember
- `sessions.spec.js`: axe finds no violations on the Pomodoro clock: vistud-dark
- `sessions.spec.js`: axe finds no violations on the Pomodoro clock: vistud-light
- `sessions.spec.js`: every colour of a session comes from a token: desktop
- `sessions.spec.js`: every colour of a session comes from a token: phone
- `sessions.spec.js`: every colour of the Pomodoro clock comes from a token: desktop
- `sessions.spec.js`: every colour of the Pomodoro clock comes from a token: phone
- `sessions.spec.js`: starting while a session is open says so, and it can be ended first
- `sessions.spec.js`: the session page is the conversation, with a rail of topics and material, and the top bar follows it on every page
- `sessions.spec.js`: the session topic is changed on the page, to one of the module or a new one
- `sessions.spec.js`: the top-bar timer hides to a pulsing dot, and stays hidden until shown again
