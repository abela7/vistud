<?php

namespace App\Http\Controllers\Api\V1;

use App\Identity\PrincipalFactory;
use App\Study\FileReading;
use App\Study\Files;
use App\Study\Folders;
use App\Study\Input;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;

/**
 * POST /api/v1/files (docs/api/openapi.json): one uploaded file, into a workspace's top level, a module or a
 * folder, and, for a file from an uploaded folder, into the folders it was in (`folder`, "Week 1/Lectures"),
 * made where they don't exist yet. A file into a module is also read by the AI, when the student's settings say so (App\Study\FileReading). The upload dialog sends a student's files one at a time, so each has its
 * own progress and its own answer (the owner's review, 2026-09-30: a whole batch in one request went over
 * PHP's limits). The file is checked by its bytes (App\Study\FileTypes) before it is kept.
 */
class FileController
{
    public function __construct(private Files $files, private Folders $folders, private PrincipalFactory $principals, private FileReading $reading) {}

    public function store(Request $request): JsonResponse
    {
        $by = $this->principals->fromRequest($request);
        $type = in_array($request->input('place_type'), ['workspace', 'module', 'folder'], true) ? $request->input('place_type') : null;
        $placeId = is_string($request->input('place_id')) && preg_match('/^[A-Za-z0-9-]{1,64}$/', $request->input('place_id')) ? $request->input('place_id') : null;
        Input::refuse($type === null || $placeId === null ? ['place' => 'Send where the file goes.'] : []);

        $upload = $request->file('file');
        Input::refuse(match (true) {
            $upload === null || is_array($upload) => ['file' => 'Choose a file.'],
            in_array($upload->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) => ['file' => 'Files can be up to '.Number::fileSize(Files::maxBytes()).'.'],
            ! $upload->isValid() => ['file' => 'The file didn\'t arrive whole. Try it again.'],
            default => [],
        });

        // One transaction: a file that's refused leaves no new empty folders behind.
        [$file, $type, $placeId] = DB::transaction(function () use ($by, $type, $placeId, $request, $upload) {
            [$type, $placeId] = $this->folders->ensurePath($by, $type, $placeId, self::folderPath($request->input('folder')));

            return [$this->files->upload($by, $type, $placeId, (string) $upload->getRealPath(), $upload->getClientOriginalName()), $type, $placeId];
        });

        // A file in a module is read by the reader at once, if the student's settings say so (docs/specs/vistud-2-blueprint.md §3.6.6).
        $this->reading->afterUpload($by, $file);

        return response()->json([
            'id' => $file->id,
            'name' => $file->fileName(),
            'kind' => $file->kind,
            'size' => $file->size,
            'place' => ['type' => $type, 'id' => $placeId],
            'url' => route('workspaces.files.show', [$file->workspaceId, $file->id]),
        ], 201);
    }

    /**
     * The folders a file was in, inside the uploaded folder: "Week 1/Lectures" (or with \ from Windows) is
     * ['Week 1', 'Lectures']. Empty parts and ones that name no folder ("." and "..") are left out.
     *
     * @return list<string>
     */
    private static function folderPath(mixed $path): array
    {
        if (! is_string($path) || mb_strlen($path) > 2000) {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', preg_split('#[/\\\\]+#', $path) ?: []),
            fn (string $part) => $part !== '' && $part !== '.' && $part !== '..',
        ));
    }
}
