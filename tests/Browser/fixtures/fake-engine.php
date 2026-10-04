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
$words = ['A ', 'left ', 'join ', 'keeps ', 'every ', 'row ', 'of ', 'the ', '**left** ', 'table.'];
// What was attached, said back, so a test can see it arrived.
if (str_contains($asked, 'draw')) {
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
