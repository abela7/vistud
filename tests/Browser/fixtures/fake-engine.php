<?php

/*
 * A stand-in for the engine's service in browser tests (tests/Browser/chat.spec.js), served by `php -S`. It
 * speaks the OpenAI chat format as OpenRouter does: /models lists one model; a chat request with
 * `stream: true` is answered as server-sent events, a few words every quarter second, so a test can see
 * the answer arrive in pieces. Nothing here is used by the app itself.
 */

$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (str_ends_with($path, '/models')) {
    header('Content-Type: application/json');
    echo json_encode(['data' => [[
        'id' => 'fake/tutor', 'name' => 'Fake tutor', 'context_length' => 100000,
        'pricing' => ['prompt' => '0.000001', 'completion' => '0.000002'],
        'supported_parameters' => ['tools'], 'architecture' => ['input_modalities' => ['text', 'image']],
    ]]]);

    return;
}

$request = json_decode((string) file_get_contents('php://input'), true) ?: [];
$messages = $request['messages'] ?? [];
$last = end($messages) ?: [];
$asked = '';
$picture = false;
foreach (array_reverse($messages) as $message) {
    if (($message['role'] ?? '') === 'user') {
        $content = $message['content'] ?? '';
        // With a picture, the words are the first part and the picture follows.
        $picture = is_array($content) && in_array('image_url', array_column($content, 'type'), true);
        $asked = is_array($content) ? (string) ($content[0]['text'] ?? '') : (string) $content;
        break;
    }
}
// The helper's and the reader's jobs (tests/Browser/helper.spec.js): one plain answer each, never streamed.
$system = (string) (($messages[0]['role'] ?? '') === 'system' ? ($messages[0]['content'] ?? '') : '');
$quick = null;
if (str_contains($system, 'You set up a course with a student')) {
    // The course guide, setting a course up (the `guide:` tests in chat.spec.js): a pasted module page gets a proposal (with
    // modules in it, which the app leaves out: they are the other talk's), anything else a question.
    $quick = str_contains($asked, 'About the Module')
        ? json_encode(['reply' => 'I found what the course is about. Tick what to add.', 'proposal' => [
            'about' => 'Operating systems and the technologies under them.',
            'assessment' => [['name' => 'Coursework 1', 'kind' => 'assignment', 'weight' => 40, 'due_on' => null]],
            'modules' => [['title' => 'Week 1: Not for this talk']],
        ]])
        : json_encode(['reply' => 'How is it assessed?', 'proposal' => null]);
} elseif (str_contains($system, 'You add modules to a course with a student')) {
    // The course guide, adding modules: weeks told get a proposal, anything else a question.
    $quick = str_contains($asked, 'Week 1')
        ? json_encode(['reply' => 'I found three weeks. Tick the ones to add now.', 'proposal' => [
            'modules' => [['title' => 'Week 1: OS Structure | Processes & Threads'], ['title' => 'Week 2: Concurrency & Scheduling | Memory Management'], ['title' => 'Week 3: Virtual Memory | Storage & IO']],
        ]])
        : json_encode(['reply' => 'Which weeks do you want to add now?', 'proposal' => null]);
} elseif (str_contains($system, "You make flashcards from a student's material")) {
    $quick = json_encode(['cards' => [
        ['front' => 'What does the scheduler decide?', 'back' => 'Which process runs next.'],
        ['front' => 'What is round robin?', 'back' => 'Each process gets a quantum in turn.', 'topic' => 'Scheduling'],
    ]]);
} elseif (str_contains($system, 'You write study notes from one file')) {
    $quick = "## Scheduling\n\n- The **scheduler** picks the next process.";
} elseif (str_contains($system, "You answer a student's question from their own notes")) {
    $quick = json_encode(['found' => true, 'answer' => 'The fixed slice of CPU time a process gets.', 'from' => ['Lecture 3 notes']]);
} elseif (str_contains($system, "You read one file of a student's course")) {
    $quick = json_encode(['summary' => 'How the CPU picks the next process.', 'outline' => [['page' => 1, 'heading' => 'Scheduling']], 'topics' => ['Round robin', 'Priority scheduling'], 'language' => 'English']);
} elseif (str_contains($system, "ViStud's quick helper")) {
    $quick = match (true) {
        str_contains($asked, 'Improve this flashcard') => "Front: What does a LEFT JOIN keep?\nBack: Every row of the left table, matched or not.",
        str_contains($asked, 'flashcard shorter') => "Front: LEFT JOIN keeps?\nBack: The left rows.",
        str_contains($asked, 'Fix the wording of this flashcard') => "Front: What does a LEFT JOIN keep?\nBack: Every row of the left table.",
        str_contains($asked, 'two more flashcards') => "Front: What does a RIGHT JOIN keep?\nBack: Every row of the right table.\n\nFront: What does a CROSS JOIN make?\nBack: Every pair of rows.",
        str_contains($asked, 'Rewrite this question') => 'Why does a left join keep the unmatched rows?',
        str_contains($asked, 'asks more than one thing') => "Why does a left join keep unmatched rows?\nWhat does a right join keep?",
        str_contains($asked, 'Shorten this text') => 'Four conditions cause a deadlock.',
        str_contains($asked, 'Explain this simply') => 'A deadlock is when processes wait on each other for ever.',
        str_contains($asked, 'Fix the spelling') => 'Deadlock needs four conditions.',
        str_contains($asked, 'Say which module each') => 'Lecture 3.txt → Week 3: CPU scheduling',
        default => 'You found Deadlocks hard, and Joins confusing.',
    };
}
if ($quick !== null) {
    header('Content-Type: application/json');
    echo json_encode(['model' => 'fake/tutor', 'choices' => [['message' => ['content' => $quick], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 10, 'cost' => 0.0001]]);

    return;
}
$words = ['A ', 'left ', 'join ', 'keeps ', 'every ', 'row ', 'of ', 'the ', '**left** ', 'table.'];
// Acting in the course: "make cards" saves two cards, "jot" writes in the study note, "mark it" sets the topic's status; each answers after its tool ran.
$acts = ['mark it' => ['set_topic_status', ['status' => 'understood', 'reason' => 'Two right answers in a row.']], 'make cards' => ['make_flashcards', ['cards' => [['front' => 'What is an OS?', 'back' => 'The layer between hardware and apps.'], ['front' => 'What is a kernel?', 'back' => 'The core of the OS.']]]], 'jot more' => ['write_note', ['text' => '- System calls ask the kernel for help.']], 'jot' => ['write_note', ['text' => "## Kernels\n\n- The kernel is the core of the OS."]]];
$act = null;
foreach ($acts as $trigger => $call) {
    if (str_contains($asked, $trigger)) {
        $act = $call;
        break;
    }
}
if ($act !== null && ($last['role'] ?? '') === 'user') {
    header('Content-Type: text/event-stream');
    echo 'data: '.json_encode(['model' => 'fake/tutor', 'choices' => [['index' => 0, 'delta' => ['tool_calls' => [['index' => 0, 'id' => 'call_act', 'type' => 'function', 'function' => ['name' => $act[0], 'arguments' => json_encode($act[1])]]]], 'finish_reason' => 'tool_calls']]])."\n\n";
    echo "data: [DONE]\n\n";

    return;
}
if ($act !== null) {
    $words = match ($act[0]) {
        'make_flashcards' => ['Saved ', 'two ', 'cards.'],
        'set_topic_status' => ['You ', 'have ', 'this.'],
        default => ['Noted.'],
    };
} elseif (str_contains($asked, 'draw')) {
    $words = ['Here ', 'it ', 'is:', "\n\n```mermaid\nflowchart LR\n  A[New] --> B[Ready]\n  B --> C[Running]\n```\n\n", 'And ', 'energy: ', '$$E = mc^2$$'];
} elseif ($picture) {
    $words = ['I ', 'can ', 'see ', 'the ', 'picture.'];
} elseif (str_contains($asked, "[Attached: the student's note")) {
    $words = ['I ', 'have ', 'your ', 'note.'];
}

// "busy" fails once (a 429, as a busy provider answers), then works: for "Try again".
$flag = sys_get_temp_dir().'/vistud-fake-engine-busy';
if (str_contains($asked, 'busy') && ! file_exists($flag)) {
    touch($flag);
    http_response_code(429);
    header('Content-Type: application/json');
    echo json_encode(['error' => ['message' => 'Rate limited', 'code' => 429]]);

    return;
}
if (str_contains($asked, 'busy')) {
    @unlink($flag);
    $words = ['Back ', 'now: ', 'joins ', 'combine ', 'rows.'];
}
// "notes" asks for a look-up first, then answers after a pause (so the look-up can be seen).
$lookUp = str_contains($asked, 'notes') && ($last['role'] ?? '') === 'user';
if (str_contains($asked, 'notes') && ! $lookUp) {
    usleep(1_000_000);
    $words = ['Your ', 'notes ', 'cover ', 'joins.'];
}
if (! ($request['stream'] ?? false)) {
    header('Content-Type: application/json');
    echo json_encode(['model' => 'fake/tutor', 'choices' => [['message' => ['content' => implode('', $words)], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 10, 'cost' => 0.0001]]);

    return;
}

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
while (ob_get_level() > 0) {
    ob_end_flush();
}
$send = function (array $chunk) {
    echo 'data: '.json_encode($chunk)."\n\n";
    flush();
};
echo ": OPENROUTER PROCESSING\n\n";
flush();
if ($lookUp) {
    $send(['model' => 'fake/tutor', 'choices' => [['index' => 0, 'delta' => ['tool_calls' => [['index' => 0, 'id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'notes', 'arguments' => '{}']]]], 'finish_reason' => 'tool_calls']]]);
    $send(['choices' => [], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'cost' => 0.00005]]);
    echo "data: [DONE]\n\n";
    flush();

    return;
}
foreach ($words as $i => $word) {
    $send(['model' => 'fake/tutor', 'choices' => [['index' => 0, 'delta' => ['content' => $word], 'finish_reason' => $i === count($words) - 1 ? 'stop' : null]]]);
    usleep(250_000);
}
$send(['choices' => [], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 10, 'cost' => 0.0001]]);
echo "data: [DONE]\n\n";
flush();
