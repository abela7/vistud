<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Identity\PrincipalFactory;
use App\Platform\Errors\NotFound;
use App\Platform\Errors\Unprocessable;
use App\Study\ClassRecordings;
use App\Study\ClassSessions;
use App\Study\Files;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** One piece of a class recording, sent while it is being recorded. */
final class ClassRecordingController
{
    public function chunk(Request $request, PrincipalFactory $principals, ClassSessions $classes, ClassRecordings $recordings, string $workspace, string $module, string $classSession, string $recording): JsonResponse
    {
        $by = $principals->fromRequest($request);
        $class = $classes->find($by, $classSession);
        ($class->workspaceId === $workspace && $class->moduleId === $module) || throw new NotFound;

        $upload = $request->file('chunk');
        $index = $request->input('index');
        $mime = $request->input('mime');
        if (! $upload instanceof UploadedFile || ! $upload->isValid()) {
            throw new Unprocessable('validation_failed', 'Some fields need attention.', ['fields' => ['chunk' => ['Send a piece of the recording.']]]);
        }
        if (! is_string($index) || preg_match('/^[1-9][0-9]*$/', $index) !== 1) {
            throw new Unprocessable('validation_failed', 'Some fields need attention.', ['fields' => ['index' => ['That piece of the recording is not valid.']]]);
        }

        $elapsed = $request->input('elapsed');
        $seconds = is_string($elapsed) && preg_match('/^[0-9]{1,6}$/', $elapsed) === 1 ? (int) $elapsed : null;

        $part = $recordings->accept($by, $class->id, $recording, (int) $index, $upload->getRealPath(), is_string($mime) ? $mime : '', $seconds);

        return response()->json(['chunks' => $part->chunks, 'bytes' => $part->bytes]);
    }

    /** The finished audio, so the student can listen and mark a stretch. */
    public function audio(Request $request, PrincipalFactory $principals, ClassSessions $classes, ClassRecordings $recordings, string $workspace, string $module, string $classSession, string $recording): BinaryFileResponse
    {
        $by = $principals->fromRequest($request);
        $class = $classes->find($by, $classSession);
        ($class->workspaceId === $workspace && $class->moduleId === $module) || throw new NotFound;
        $part = $recordings->playable($by, $class->id, $recording);

        return response()->file(Files::disk()->path((string) $part->path), [
            'Content-Type' => $part->mime,
            'Content-Disposition' => 'inline; filename="class.'.$part->extension.'"',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Cache-Control' => 'private',
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
