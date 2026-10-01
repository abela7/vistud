<?php

namespace App\Http\Controllers\Api\V1;

use App\Identity\PrincipalFactory;
use App\Platform\Access\Guard;
use App\Platform\Errors\NotFound;
use App\Platform\Ids;
use App\Study\Files;
use App\Study\Input;
use App\Study\NoteImages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Number;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Pictures in a note: POST /api/v1/notes/images keeps one (pasted, dropped
 * or chosen in the editor) and answers with its address; GET
 * /notes/images/{id} shows it, to its owner only. Only PNG, JPEG, WebP and
 * GIF, checked by their bytes, so nothing that could run a script (SVG,
 * HTML) is ever shown from here.
 */
class NoteImageController
{
    private const MAX_BYTES = 10 * 1024 * 1024;

    public function __construct(private PrincipalFactory $principals) {}

    public function store(Request $request): JsonResponse
    {
        $scope = Guard::learner($this->principals->fromRequest($request));
        $file = $request->file('image');
        $path = $file?->isValid() ? $file->getRealPath() : false;
        $size = $path ? (int) @filesize($path) : 0;
        $max = min(self::MAX_BYTES, (int) config('vistud.files.max_bytes'));
        Input::refuse(match (true) {
            $size === 0 => ['image' => 'Choose a picture.'],
            $size > $max => ['image' => 'Pictures can be up to '.Number::fileSize($max).'.'],
            default => [],
        });
        $type = @getimagesize($path)[2] ?? null;
        Input::refuse(isset(NoteImages::TYPES[$type]) ? [] : ['image' => 'Use a PNG, JPEG, WebP or GIF picture.']);

        $id = Ids::new();
        $stream = fopen($path, 'rb');
        try {
            Files::disk()->writeStream(NoteImages::key($scope->learnerId, $id, NoteImages::TYPES[$type][1]), $stream) ?: throw new \RuntimeException('The picture could not be stored.');
        } finally {
            fclose($stream);
        }

        return response()->json(['id' => $id, 'url' => route('notes.images.show', $id)], 201);
    }

    public function show(Request $request, string $id): StreamedResponse
    {
        $scope = Guard::learner($this->principals->fromRequest($request));
        $found = NoteImages::find($scope->learnerId, $id);
        if ($found !== null) {
            [$key, $mime, $extension] = $found;

            return Files::disk()->response($key, "{$id}.{$extension}", [
                'Content-Type' => $mime,
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, max-age=604800, immutable',
                'Cross-Origin-Resource-Policy' => 'same-origin',
                'Content-Security-Policy' => "default-src 'none'; sandbox",
            ], 'inline');
        }

        throw new NotFound;
    }
}
