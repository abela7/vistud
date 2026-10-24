<?php

/*
| ViStud's MCP server (docs/specs/mcp-connections-plan.md §3.2, M6.1): Claude, ChatGPT and Gemini sign in to it over
| OAuth (Passport) and call its tools as the student. laravel/mcp reads the first four keys; the rest are ViStud's.
*/

return [

    // Where a chat app may send the student back after sign-in. Never '*'. http://localhost and http://127.0.0.1 let
    // a program on this computer (Claude Code) use a loopback port. Gemini's relay hosts are added from the first
    // refused registration, whose error names them, never guessed.
    'redirect_domains' => [
        'https://claude.ai',
        'https://chatgpt.com',
        'https://antigravity.google',
        // Gemini's web app (2026-10-24, from its "Copy redirect URI"): /r/user_bound_custom-mcp-<account>-<server>.
        'https://oauth-redirect.googleusercontent.com/r/',
        'http://localhost',
        'http://127.0.0.1',
    ],

    'custom_schemes' => [],

    'authorization_server' => null,

    'tool_search' => [
        'max_tool_calls' => 10,
        'max_output_bytes' => 65_536,
    ],

    'clients' => [],

    // The address chat apps connect to, written into the install packages (vistud:mcp:plugins). Empty: APP_URL.
    'public_url' => env('MCP_PUBLIC_URL'),

    // The kill switch: false answers every /mcp call with 503 and refuses new sign-ins, without touching connections.
    'enabled' => (bool) env('MCP_ENABLED', true),

    // TUNNEL=1 while the app is reached through a tunnel for a test: every other path answers 404 (McpTunnelOnly).
    'tunnel' => (bool) env('TUNNEL', false),

    // Calls a minute per student, and new client registrations and token requests a minute per IP.
    'per_minute' => (int) env('MCP_PER_MINUTE', 60),
    'register_per_minute' => (int) env('MCP_REGISTER_PER_MINUTE', 10),
    'token_per_minute' => (int) env('MCP_TOKEN_PER_MINUTE', 30),

    // How long a connection may sit unused: marked after `stale_days`, revoked after `revoke_days`.
    'stale_days' => 30,
    'revoke_days' => 90,
];
