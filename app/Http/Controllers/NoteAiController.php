<?php

namespace App\Http\Controllers;

use App\Engine\Helper;
use App\Identity\PrincipalFactory;
use App\Platform\Errors\Unprocessable;
use App\Platform\Files\UploadTemps;
use App\Study\Files;
use App\Study\Notes;
use App\Study\Workspaces;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ephemeral in-note AI assistant for highlighted text.
 * Answers quick questions focused directly on the selected line, sentence, or paragraph.
 * Nothing is stored or persisted when the student dismisses the popup.
 */
class NoteAiController
{
    public function __invoke(
        Request $request,
        PrincipalFactory $principals,
        Workspaces $workspaces,
        Notes $notes,
        Helper $helper,
        string $workspace
    ): JsonResponse {
        $by = $principals->fromRequest($request);
        $ws = $workspaces->find($by, $workspace);

        $selection = trim((string) $request->input('selection', ''));
        if ($selection === '') {
            return response()->json(['error' => 'No text selected.'], 422);
        }

        $prompt = trim((string) ($request->input('prompt') ?: $request->input('question') ?: ''));
        if ($prompt === '') {
            $prompt = 'Explain this selected text simply and clearly.';
        }

        $noteTitle = trim((string) $request->input('note_title', ''));
        $noteId = $request->input('note_id');
        $folderId = null;
        $moduleId = null;

        if (is_string($noteId) && $noteId !== '') {
            try {
                $note = $notes->find($by, $noteId);
                if ($note->workspaceId === $ws->id) {
                    $noteTitle = $noteTitle ?: $note->displayTitle();
                    $folderId = $note->folderId;
                    $moduleId = $note->moduleId;
                }
            } catch (\Throwable) {
                // Ignore missing note
            }
        }

        $task = "You are an expert tutor answering a student's quick in-note question about a specific text they highlighted in their study notes.

Selected text from note:
\"\"\"
{$selection}
\"\"\"
Note title: ".($noteTitle ?: 'Study note')."

Student's question: {$prompt}

Guidelines:
- Focus directly on the highlighted line, sentence, or paragraph.
- Keep your answer direct, clear, and easy to understand (2-4 sentences or clear concise bullet points).
- Do not repeat the whole note or add unnecessary filler.
- Be straightforward and helpful.";

        $pictures = [];
        $files = [];

        if ($request->hasFile('attachments')) {
            $uploadedList = (array) $request->file('attachments');
            foreach ($uploadedList as $uploadedFile) {
                if ($uploadedFile && $uploadedFile->isValid()) {
                    $mime = (string) $uploadedFile->getMimeType();
                    $name = (string) $uploadedFile->getClientOriginalName();
                    $bytes = UploadTemps::bytes($uploadedFile, Files::maxBytes());
                    if (str_starts_with($mime, 'image/')) {
                        $pictures[] = ['bytes' => $bytes, 'mime' => $mime, 'name' => $name];
                    } else {
                        $files[] = ['bytes' => $bytes, 'name' => $name, 'extension' => $uploadedFile->getClientOriginalExtension()];
                    }
                }
            }
        }

        $rawAttachments = $request->input('attachments');
        if (is_array($rawAttachments)) {
            foreach ($rawAttachments as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $name = (string) ($item['name'] ?? 'attachment');
                $dataUrl = (string) ($item['data'] ?? '');
                if (preg_match('/^data:([^;]+);base64,(.*)$/s', $dataUrl, $m)) {
                    $mime = $m[1];
                    $bytes = base64_decode($m[2]);
                    if ($bytes !== false) {
                        if (str_starts_with($mime, 'image/')) {
                            $pictures[] = ['bytes' => $bytes, 'mime' => $mime, 'name' => $name];
                        } else {
                            $files[] = ['bytes' => $bytes, 'name' => $name, 'extension' => pathinfo($name, PATHINFO_EXTENSION)];
                        }
                    }
                }
            }
        }

        $chosenModel = trim((string) $request->input('model', ''));
        $chosenModel = $chosenModel !== '' ? $chosenModel : null;

        try {
            $answer = $helper->quick(
                by: $by,
                task: $task,
                thing: $selection,
                folderId: $folderId,
                moduleId: $moduleId,
                workspaceId: $ws->id,
                pictures: $pictures,
                files: $files,
                maxTokens: 600,
                model: $chosenModel
            );

            return response()->json([
                'answer' => $answer,
                'status' => 'ok',
                'model' => $chosenModel,
            ]);
        } catch (Unprocessable $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'The AI tutor could not answer right now. Try again.',
            ], 500);
        }
    }
}
