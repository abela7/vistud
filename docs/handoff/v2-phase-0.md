# ViStud 2 · Phase 0 (groundwork): handoff

Branch `claude/persistent-study-context-zsilo6`. The plan is [docs/specs/vistud-2-blueprint.md](../specs/vistud-2-blueprint.md) (Part 4, Phase 0; Part 3 for the parts it points to). Phase 0 changes nothing a student does except the AI settings page and the word *course*.

## What was built

**Three roles** (`App\Engine\Role`: tutor, reader, helper; blueprint §3.6.1)
- `Choices::modelFor(Role)`: an empty role uses the next one up (helper → reader → tutor). `Settings::ready($by, Role)` says why a role can't run (no key, no model, no consent).
- Migration `2026_10_06_100000_make_engine_roles_and_trust_settings`: `engine_settings.quick_model` is now `reader_model` (values kept); new `helper_model`, `auto_read_files` (on), `tutor_marks_topics` (on), `copy_paste_ai` (off). The owner's default `engine.quick_model` becomes `engine.reader_model`; `VISTUD_ENGINE_QUICK_MODEL` still works as the reader's default. Admin AI engine setup has the three default models.

**Jobs** (blueprint §3.6.3)
- Migration `2026_10_06_110000_create_engine_jobs_table`; `App\Engine\Jobs\{Job, Run, Runner, Heartbeat, Skipped}` and the carrier `App\Jobs\RunEngineJob` (ids only, never a student's words). A job runs on a worker when one is alive (heartbeat in the cache, fresh within 60 s), after the response when none is, and at once when the queue is sync (tests). Cost, tokens, model and a stable error code are kept on the row; a failure never throws into the page.
- `Usage`: what the month has cost by role, and one monthly limit over all three. The chat's wrap-up and the folding of a long chat now run as reader jobs, so they count against the limit.

**The context Stack** (blueprint §3.6.2, §3.6.4)
- `App\Engine\Context\Stack` builds the standing context in layers with a token budget each (rules 2,500, course 600, student 250, module 700, session 300); what is cut is reported, "(cut: N more lines)" shows in the text, and what the student wrote is never cut. The layers before the session are the same for every session of a course, so a service can cache them (`Built::stable`; the cache markers themselves are not added yet).
- `resources/prompts/tutor-2.md` (about 2,400 tokens with tools, about 2,000 without; the Stack test pins ≤ 2,500). `SessionChat` uses the Stack and no longer sends the briefing; the standing context is about 5,600 tokens with the 19 tools instead of up to 22,000. The pasted briefing (`tutor.md`) is unchanged.
- `php artisan prompts:try [--role=tutor|helper] [--model=] [--scenario=] [--plain]`: five tutor moments and three helper jobs on a live model, to read the replies after changing a prompt. It spends a few cents and uses the owner's key; I did not run it live (no key must be used from my sandbox).

**The helper** (blueprint §3.6.1)
- `App\Engine\Helper::quick($by, $task, $thing, $moduleId)` with a read-only toolbox (`Toolbox::only`): look-ups up to three rounds, the student's material fenced off as "not instructions" (`resources/prompts/helper.md`, ≤ 300 tokens). No screen uses it yet (Phase 5).

**The screen** (blueprint §3.9, §3.11)
- AI settings (was "AI engine" for students): Your key, **Usage this month** (Tutor, Reader, Helper, and the total against the monthly limit), **Your models** (three fields, one line each: the price once a model is chosen, otherwise what the role does), **How the tutor works** (language and four toggles), Limits and privacy. Hints are one line.
- `<x-page title back-href back-to eyebrow context>` with `action` and `menu` slots (`resources/views/components/page.blade.php`); `<x-checkbox hint>`; DESIGN.md §5.8 (the page template) and §5.9 (the writing rules).
- **Course** instead of *workspace* on every student screen (labels only; routes, the code and the admin pages keep their names until Phase 6).

**Tests added or changed**: `Engine/RolesTest`, `JobsTest`, `StackTest`, `HelperTest`, `PromptsTryTest`, `SettingsTest`, `SetupTest`, `SessionChatTest`; `Web/EngineSettingsScreenTest` (three roles, toggles, usage by role), `EngineSetupScreenTest`, `TutorChatScreenTest`, and the Course wording in `WorkspaceScreensTest`, `AppShellTest`, `CalendarScreensTest`, `TrackerScreensTest`; browser spec `ai-settings.spec.js` (desktop and phone, axe in all three themes, token colours) and the Course wording in the other browser specs.

## What Abel tests (plain steps)

Use your own key, on the real screen. Never paste a key or a token into a chat with any AI.

1. Log in. Home says **My courses** and the button says **New course**; open a course and its menus say "course" too (Edit course, Delete course, Archive course). Nowhere says "workspace".
2. Top right, open your name's menu → **AI settings**. You see five boxes: *Your key*, *Usage this month*, *Your models*, *How the tutor works*, *Limits and privacy*.
3. *Your models* has **Tutor**, **Reader (optional)**, **Helper (optional)**, each with one line under it. Click a field and type a model name; the line under it turns into the price. If you used to have a "quick model", it is now in **Reader**.
4. Choose models (for example a cheap one for Reader and Helper), press **Save** at the bottom: "Your AI settings are saved." Reload: they are still there.
5. In *How the tutor works* there are four switches. Change two, press **Save**, reload: they are remembered. (Only "Ask me before adding or switching topics" changes what the tutor does today; see below.)
6. Open a course and a module, **Start studying**, say hello to the tutor. It answers and teaches the way it did before.
7. Back in **AI settings**: *Usage this month* shows the chat's cost under **Tutor** ("under 1¢" or a few cents).
8. End the session. Come back to *Usage this month*: **Reader** now shows a small cost too (the session's wrap-up).
9. As the admin: the admin **AI engine** page now has three default models: Tutor, Reader, Helper.
10. Phone: open AI settings on the phone. Nothing sideways, the switches are easy to tap.

## What is left or deferred

- Three switches only record the choice for now: *Read my files automatically* (Phase 2), *Let the tutor mark topics* (Phase 3), *I use another AI by copy-paste* (Phase 3 hides the copy-paste tools behind it). *Ask me before adding or switching topics* works already.
- The Helper has no screen yet (Phase 5); the Reader has only the two jobs the chat already needed (Phase 1 and 2 add more).
- A job queued on a worker that then crashes within 60 s of its last heartbeat stays `queued`; nothing rescues it yet. `php artisan dev` starts a worker, so this only matters on a server set up by hand.
- The chat no longer receives the due-soon list, earlier sessions or findings as text; the tutor fetches them with its tools. If a model that can't use tools feels thinner, that is why: say so and Phase 3 can add a plain-model layer.
- Anthropic's prompt-cache markers are not sent yet (`Built::stable` is ready for them).
- The wrap-up and folding prompts are still sentences inside the code; they move to `resources/prompts/` with the Reader's jobs.
- `prompts:try` has not been run on a live model by me. Run `php artisan prompts:try` once from your own PC (it spends a few cents) and read the five replies; if one is off, tell me which number.

## Message for the local tester (Gemini)

```
Please bring your copy up to date with the latest ViStud work. Do exactly these steps and nothing else.
Important: do not open the AI settings or AI engine pages, and do not read, write or paste any key or token anywhere.

1. git fetch origin claude/persistent-study-context-zsilo6
2. git checkout claude-latest
3. git merge --ff-only origin/claude/persistent-study-context-zsilo6
   (if it says it cannot fast-forward, stop and tell me; do not force anything)
4. composer migrate
   (two new migrations: the AI roles and settings, and the engine_jobs table; never use migrate:fresh)
5. npm run build
6. Run only these tests, and tell me the pass/fail counts and the text of any failure:
   php artisan test --compact tests/Feature/Engine/RolesTest.php tests/Feature/Engine/JobsTest.php tests/Feature/Engine/StackTest.php tests/Feature/Engine/HelperTest.php tests/Feature/Engine/SessionChatTest.php tests/Feature/Web/EngineSettingsScreenTest.php tests/Feature/Web/WorkspaceScreensTest.php

Do not push anything. Do not run the whole test suite or the browser tests (the PC is slow).
```
