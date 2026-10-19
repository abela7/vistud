# ViStud · M6.6 (Panels): handoff

Branch `claude-latest`. The plan is [docs/specs/mcp-connections-plan.md](../specs/mcp-connections-plan.md) (section 4, M6.6; sections 3.5 and 7). Goal: a quiz can look like a quiz in a chat app that draws panels, and an app that only has text still gets the whole quiz.

## What was built

**Five panels** (`App\Mcp\Apps`, one Blade `resources/views/mcp/apps/panel.blade.php`). The plan names four. Flashcards needed their own card, so `FlashcardCard` is the fifth. Each is an `AppResource` (`text/html;profile=mcp-app`), system fonts, the host's colour variables (a hex colour in a view fails the theme check), tap targets at least 44px, no student text in an HTML attribute, no `localStorage`.

| Panel | Opens from |
|---|---|
| `QuizRound` | `show_quiz` |
| `TopicStatusCard` | `set_topic_status` |
| `SessionEndCard` | `end_session` |
| `FlashcardCard` | `flashcards_due`, `answer_flashcard` |
| `ConfirmationCard` | every manage tool (`rename_*`, `forget_memory`, `remember`, `replace_note`) |

`show_quiz` is read-only. It checks `course_id`, the open `session_id` and `topic_id`, echoes the questions, and writes no quiz. The card sends answers to `record_quiz` when the model marked a right choice; otherwise it sends them back to the chat to save. The tool's text is the whole quiz.

A linked tool's answer carries `ui_version` (a clock, then a counter). The card ignores an older one. The text of the answer is unchanged, so Claude Code and Gemini still work.

`ConfirmationCard` only calls `openLink` on the ViStud confirm URL from the tool result. The page HTML does not contain that URL. Nothing is confirmed from the card.

**`ui.domain`.** `ViStudApp::resolvedAppMeta()` hashes `url('/mcp')` (sha256, first 32 hex, `.claudemcpcontent.com`) for every company except OpenAI. OpenAI connections omit `domain` so ChatGPT uses its host default. CSP `connectDomains` is `APP_URL`. The plan's `#[AppMeta(connectDomains: [APP_URL])]` cannot see the config value, so this is `appMeta()` instead.

**Contract.** `ViStudServer::CONTRACT` is `0.5.0`. Full level lists 47 tools (38 was the old default; the default level is now 39, because `show_quiz` is a read). `tests/Feature/Mcp/fixtures/tools.json` gained `show_quiz` only.

**Browser.** `tests/Browser/mcp-apps.spec.js` loads `/mcp/panels/{quiz,topic,session,confirm,cards}` at 320px, light and dark, and runs axe. `PanelPreview` answers that address only when `APP_ENV` is `local` or `testing`. Production returns 404.

## Choices

- One Blade for every panel, chosen by `kind`. The rules are the same; the script is what differs.
- `show_quiz` also takes optional `module_id`, like every other course tool. The required arguments are `course_id`, `session_id`, `topic_id`, `questions`.
- A quiz card has one action, Save answers. Choices are radios, not a row of buttons, so the card stays within two actions. A flashcard shows the answer, then Knew it and Missed it.

## What Abel tests (plain steps)

1. In claude.ai on the phone, ask "quiz me" on an open session. After Allow, a card should show. Each answer should land in ViStud (a quiz on the session). This cannot be checked from this computer.
2. Only if ChatGPT was shown to write in M6.3: open the quiz panel there (PiP on a computer, fullscreen on the phone). Write down whether it renders at all. Not done here.
3. Ask for a rename. The card's button opens the ViStud page. Nothing changes until Yes on that page.

## What is left

- Any test against the real Claude, ChatGPT or Gemini, including the recorded ChatGPT render check.
- The browser spec was not run in this handoff's suite (Playwright needs the app served and a browser). The HTML is covered by `AppsTest`.

## Rollback order

Revert the M6.6 commit. No migration. Installed plugins stamped `0.4.0` keep working; `show_quiz` is new, and the old tools' arguments did not change.
