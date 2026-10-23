<?php

namespace App\Http\Controllers;

use App\Engine\Dictation;
use App\Identity\PrincipalFactory;
use App\Platform\Access\Guard;
use App\Platform\Errors\Unprocessable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

/**
 * Voice typing (App\Engine\Dictation): the chat's microphone sends what it recorded, and gets back the words to put in
 * the box. Nothing is kept.
 */
final class DictationController
{
    public function __invoke(Request $request, PrincipalFactory $principals, Dictation $dictation): JsonResponse
    {
        $by = $principals->fromRequest($request);
        Guard::learner($by);
        $file = $request->file('audio');
        if (! $file instanceof UploadedFile || ! $file->isValid()) {
            // A phone's recording that never arrived whole: what came, for the log; never the audio.
            Log::warning('dictation failed', ['code' => 'audio', 'upload_error' => $file instanceof UploadedFile ? $file->getError() : 'no file',
                'content_length' => (int) $request->header('Content-Length'), 'agent' => mb_substr((string) $request->userAgent(), 0, 160)]);

            return self::refused('audio', 'Nothing was recorded. Try again.');
        }
        // The browser names the recording by what it made: webm on most phones and computers, mp4 on an iPhone.
        $extension = strtolower($file->getClientOriginalExtension() ?: (string) $file->guessExtension());
        try {
            return response()->json(['text' => $dictation->transcribe($by, (string) file_get_contents($file->getRealPath()), $extension)]);
        } catch (Unprocessable $e) {
            // A refused field says why in its own words; anything else, in the error's.
            $fields = $e->details['fields'] ?? [];

            return self::refused($fields !== [] ? (string) array_key_first($fields) : $e->errorCode, $fields !== [] ? (string) (array_values($fields)[0][0] ?? $e->getMessage()) : $e->getMessage());
        }
    }

    private static function refused(string $code, string $message): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], 422);
    }
}
