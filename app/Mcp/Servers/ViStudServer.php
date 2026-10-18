<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\MeTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

/**
 * ViStud over MCP (docs/specs/mcp-connections-plan.md): one server for Claude, ChatGPT and Gemini. M6.1 has the door
 * and `me`; the study tools arrive in M6.2 (reads) and M6.3 (sessions and writes).
 */
#[Name('ViStud')]
#[Version('0.1.0')]
#[Instructions('ViStud is the student\'s study platform: their courses, modules, topics, notes, files and study sessions live there, and it is the record. Use these tools to read it and, when allowed, to save to it. Say only what a tool returned; never claim something was saved unless a tool said so.')]
final class ViStudServer extends Server
{
    protected array $tools = [
        MeTool::class,
    ];

    protected array $resources = [];

    protected array $prompts = [];
}
