# ViStud · M6.8 (Gemini attempt): handoff

Branch `claude-latest`. The plan is [docs/specs/mcp-connections-plan.md](../specs/mcp-connections-plan.md) (section 4, M6.8). Goal: find out whether Gemini can sign in. That needs Gemini and the public server, so this phase is the part that can be proven here.

## What was built

**A log line for every sign-in and token attempt** (`App\Http\Middleware\LogOAuthAttempt`, appended for every request, and it only writes when the path is `oauth/authorize` or `oauth/token`). The line is `oauth.attempt` with the request id and the path. No query, no body, no code, no token.

**State.** A 1,400-character `state` is sent through Passport's authorize redirect and comes back byte for byte. No extra cache was added. Passport already keeps it on the session.

**Redirects.** Six paths on `https://claude.ai` register. `https://unknown.googleusercontent.com/callback` is refused with 400, and it is not added to `redirect_domains`. The plan says to add the hosts from the first real refused registration, whose error names them. There has been no such registration, so none were guessed.

**The extra scope** `ACCESS_VIEW_MANAGE_MCP_CONTENT` was not added. The plan says to add it only when Google's own trace asks for it. There is no trace.

**The Connections page** (`resources/views/livewire/study/connections.blade.php`) now says Gemini sign-in may stop before ViStud sees it, and points to Prompt for another AI on a study session, then Save from the chat. The word "briefing" is not used: students never see that word.

## What Abel tests (plain steps)

1. On the public server, at gemini.google.com: Settings, Connected apps, Custom apps. Add `https://vistud.app/mcp`. Sign in. Ask what is due.
2. If `/oauth/token` never shows in the log, stop and write that down. The fallback is Prompt for another AI, or Antigravity at the desk.
3. If registration is refused, the error names the redirect host. Add that host to `config/mcp.php` `redirect_domains` and try once more. Do not guess hosts.
4. A re-test every quarter belongs in STATUS.md, with the two forum threads, once the first attempt has a result.

## What is left

- The live Gemini sign-in. It cannot be done from this computer.
- The extra scope, until a trace asks for it.
- Relay hosts, until a refusal names them.

## Rollback order

Revert the M6.8 commit. No migration. The log line goes away with it.
