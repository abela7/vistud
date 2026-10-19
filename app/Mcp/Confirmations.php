<?php

namespace App\Mcp;

use App\Audit\AuditAction;
use App\Audit\AuditLog;
use App\Platform\Access\Guard;
use App\Platform\Access\Principal;
use App\Platform\Database\LearnerTables;
use App\Platform\Errors\NotFound;
use App\Platform\Errors\Unprocessable;
use App\Platform\Ids;
use App\Study\Folders;
use App\Study\MarkdownDoc;
use App\Study\Modules;
use App\Study\Notes;
use App\Study\Topics;
use App\Study\TutorMemories;
use App\Study\Workspaces;
use Illuminate\Support\Facades\DB;

/**
 * Changes a chat app may ask for but only the student can make (docs/specs/mcp-connections-plan.md §3.4, M6.5): a rename,
 * forgetting or remembering a line about the student, or replacing a whole note. The app's tool stores the change here
 * and answers with a link; nothing happens until the student opens that ViStud page and presses Yes. The page shows the
 * stored change read-only (opening it, or a preview of the link, changes nothing); the change runs at the POST, once,
 * on the target as the student has it then. There is no tool that confirms, and the model calling again is never consent.
 */
final class Confirmations
{
    /** How long a link stays good. */
    public const MINUTES = 10;

    /** The longest text a replaced note or a remembered line may carry. */
    public const MAX_TEXT = 12_000;

    /**
     * Each tool: what it works on (`target`), the input that names it (`id`), its new words (`text`), and whether the
     * page also asks for the password (what lands in every future prompt, or cannot be undone).
     *
     * @var array<string, array{target: string, id: string, text: ?string, password: bool, verb: string}>
     */
    public const TOOLS = [
        'rename_topic' => ['target' => 'topic', 'id' => 'topic_id', 'text' => 'new_name', 'password' => false, 'verb' => 'Rename the topic'],
        'rename_module' => ['target' => 'module', 'id' => 'module_id', 'text' => 'new_title', 'password' => false, 'verb' => 'Rename the module'],
        'rename_folder' => ['target' => 'folder', 'id' => 'folder_id', 'text' => 'new_name', 'password' => false, 'verb' => 'Rename the folder'],
        'rename_course' => ['target' => 'course', 'id' => 'course_id', 'text' => 'new_name', 'password' => false, 'verb' => 'Rename the course'],
        'rename_note' => ['target' => 'note', 'id' => 'note_id', 'text' => 'new_title', 'password' => false, 'verb' => 'Rename the note'],
        'forget_memory' => ['target' => 'memory', 'id' => 'memory_id', 'text' => null, 'password' => true, 'verb' => 'Forget this line about you'],
        'remember' => ['target' => 'remember', 'id' => 'replaces_memory_id', 'text' => 'text', 'password' => true, 'verb' => 'Remember this about you'],
        'replace_note' => ['target' => 'note', 'id' => 'note_id', 'text' => 'text', 'password' => true, 'verb' => 'Replace the whole text of the note'],
    ];

    public function __construct(
        private Topics $topics,
        private Modules $modules,
        private Folders $folders,
        private Workspaces $workspaces,
        private Notes $notes,
        private TutorMemories $memories,
        private AuditLog $audit,
    ) {}

    /**
     * Stores a change for the student to confirm, after checking it is theirs and well formed.
     *
     * @param  array<string, mixed>  $input
     * @return array{id: string, url: string}
     */
    public function ask(Principal $by, object $connection, string $tool, array $input): array
    {
        $spec = self::TOOLS[$tool] ?? throw new \InvalidArgumentException("No confirmed change called {$tool}.");
        $input = $this->cleaned($spec, $input);
        $this->describe($by, $tool, $input);
        $scope = Guard::learner($by);
        $id = Ids::new();
        LearnerTables::insert($scope, 'mcp_confirmations', [
            'id' => $id,
            'connection_id' => $connection->id,
            'tool' => $tool,
            'target_id' => $input[$spec['id']] ?? null,
            'input' => json_encode($input, JSON_THROW_ON_ERROR),
            'input_hash' => self::hash($input),
            // elapsed: the link is good for ten minutes of real time.
            'expires_at' => now()->addMinutes(self::MINUTES),
            'created_at' => now(),
        ]);

        return ['id' => $id, 'url' => route('connections.confirm', $id)];
    }

    /**
     * What the confirm page shows: the change, with the target's name as it is now. Changes nothing.
     *
     * @return array{id: string, tool: string, verb: string, target: ?string, before: ?string, after: ?string, password: bool, provider: string}
     */
    public function show(Principal $by, string $id): array
    {
        $row = $this->open($by, $id);
        $input = json_decode((string) $row->input, true) ?: [];
        $about = $this->describe($by, (string) $row->tool, $input);
        $provider = LearnerTables::query(Guard::learner($by), 'mcp_connections')->where('id', $row->connection_id)->value('provider');

        return $about + [
            'id' => $id,
            'tool' => (string) $row->tool,
            'verb' => self::TOOLS[$row->tool]['verb'],
            'password' => self::TOOLS[$row->tool]['password'],
            'provider' => Provider::company((string) $provider),
        ];
    }

    /** Whether the page must ask for the password before the change runs. */
    public function needsPassword(Principal $by, string $id): bool
    {
        return self::TOOLS[$this->open($by, $id)->tool]['password'] ?? false;
    }

    /** The student pressed Yes: the change runs now, once. Returns a sentence saying what was done. */
    public function run(Principal $by, string $id): string
    {
        $scope = Guard::learner($by);

        return DB::transaction(function () use ($by, $scope, $id) {
            $row = LearnerTables::query($scope, 'mcp_confirmations')->where('id', $id)->lockForUpdate()->first();
            if ($row === null || $row->used_at !== null || now()->greaterThan($row->expires_at)) {
                throw new NotFound;
            }
            $input = json_decode((string) $row->input, true) ?: [];
            hash_equals((string) $row->input_hash, self::hash($input)) || throw new NotFound;
            $live = LearnerTables::query($scope, 'mcp_connections')->where('id', $row->connection_id)->whereNull('revoked_at')->exists();
            $live || throw new NotFound;
            $about = $this->describe($by, (string) $row->tool, $input);

            $done = $this->apply($by, (string) $row->tool, $input);
            LearnerTables::query($scope, 'mcp_confirmations')->where('id', $id)->update(['used_at' => now()]);
            $this->audit->record($by, AuditAction::MCP_DESTRUCTIVE_CONFIRMED, 'mcp_connection', (string) $row->connection_id, ['tool' => $row->tool]);

            return $done !== '' ? $done : ($about['verb'] ?? 'Done').'.';
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{target: ?string, before: ?string, after: ?string, verb?: string}
     */
    private function describe(Principal $by, string $tool, array $input): array
    {
        $spec = self::TOOLS[$tool];
        $id = is_string($input[$spec['id']] ?? null) ? $input[$spec['id']] : null;
        $text = $spec['text'] !== null && is_string($input[$spec['text']] ?? null) ? $input[$spec['text']] : null;
        $before = match ($tool) {
            'rename_topic' => $this->topics->find($by, $id ?? throw new NotFound)->name,
            'rename_module' => $this->modules->find($by, $id ?? throw new NotFound)->title,
            'rename_folder' => $this->folders->find($by, $id ?? throw new NotFound)->name,
            'rename_course' => $this->workspaces->find($by, $id ?? throw new NotFound)->name,
            'rename_note', 'replace_note' => $this->notes->find($by, $id ?? throw new NotFound)->displayTitle(),
            'forget_memory' => $this->memories->find($by, $id ?? throw new NotFound)->text,
            'remember' => $id === null ? null : $this->memories->find($by, $id)->text,
            default => throw new NotFound,
        };
        if ($tool === 'remember' && is_string($input['course_id'] ?? null)) {
            $this->workspaces->find($by, $input['course_id']);
        }

        return ['target' => $tool === 'remember' ? null : $before, 'before' => $before, 'after' => $text];
    }

    /** @param  array<string, mixed>  $input */
    private function apply(Principal $by, string $tool, array $input): string
    {
        $id = (string) ($input[self::TOOLS[$tool]['id'] ?? ''] ?? '');
        $text = (string) ($input[self::TOOLS[$tool]['text'] ?? ''] ?? '');

        switch ($tool) {
            case 'rename_topic':
                $this->topics->rename($by, $id, Topics::cleanMarkdown($text));

                return 'The topic is renamed.';
            case 'rename_module':
                $this->modules->update($by, $id, ['title' => $text]);

                return 'The module is renamed.';
            case 'rename_folder':
                $this->folders->rename($by, $id, $text);

                return 'The folder is renamed.';
            case 'rename_course':
                $this->workspaces->update($by, $id, ['name' => $text]);

                return 'The course is renamed.';
            case 'rename_note':
                $this->notes->rename($by, $id, $text);

                return 'The note is renamed.';
            case 'forget_memory':
                $this->memories->forget($by, $id);

                return 'That line is forgotten.';
            case 'remember':
                $course = is_string($input['course_id'] ?? null) ? $input['course_id'] : null;
                $this->memories->add($by, $course, $text, 'tutor', replacesId: $id !== '' ? $id : null);

                return 'Remembered.';
            case 'replace_note':
                $this->notes->replace($by, $id, MarkdownDoc::blocks($text));

                return 'The note\'s text is replaced. Its earlier versions are kept.';
        }

        return '';
    }

    /**
     * @param  array{target: string, id: string, text: ?string, password: bool, verb: string}  $spec
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    private function cleaned(array $spec, array $input): array
    {
        $out = [];
        foreach (array_filter([$spec['id'], $spec['text'], 'course_id']) as $key) {
            $value = $input[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $out[$key] = $key === $spec['text'] ? mb_substr(trim($value), 0, self::MAX_TEXT) : mb_substr(trim($value), 0, 64);
            }
        }
        if (! isset($out[$spec['id']]) && $spec['id'] !== 'replaces_memory_id') {
            throw new Unprocessable('id_required', "missing: {$spec['id']}. Call the tool that lists it for the id.");
        }
        if ($spec['text'] !== null && ! isset($out[$spec['text']])) {
            throw new Unprocessable('text_required', "missing: {$spec['text']}.");
        }

        return $out;
    }

    /** The open, unused, unexpired row of this student, or NotFound: one answer for wrong, expired, used and other people's. */
    private function open(Principal $by, string $id): object
    {
        $row = LearnerTables::query(Guard::learner($by), 'mcp_confirmations')->where('id', $id)->first();
        if ($row === null || $row->used_at !== null || now()->greaterThan($row->expires_at)) {
            throw new NotFound;
        }
        if (! LearnerTables::query(Guard::learner($by), 'mcp_connections')->where('id', $row->connection_id)->whereNull('revoked_at')->exists()) {
            throw new NotFound;
        }

        return $row;
    }

    /** @param  array<string, mixed>  $input */
    private static function hash(array $input): string
    {
        ksort($input);

        return hash('sha256', (string) json_encode($input));
    }
}
