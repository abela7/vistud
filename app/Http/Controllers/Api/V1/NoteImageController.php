<?php

namespace App\Http\Controllers\Api\V1;

use App\Identity\PrincipalFactory;
use App\Platform\Access\Guard;
use App\Platform\Ids;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class NoteImageController
{
    public function __construct(private PrincipalFactory $principals) {}

    public function store(Request $request): JsonResponse
    {
        $scope = Guard::learner($this->principals->fromRequest($request));

        if (! $request->hasFile('image')) {
            return response()->json(['error' => 'No image file uploaded.'], 422);
        }

        $file = $request->file('image');
        if (! $file->isValid()) {
            return response()->json(['error' => 'Invalid file upload.'], 422);
        }

        $mime = $file->getMimeType();
        $allowedMimes = ['image/png', 'image/jpeg', 'image/jpg', 'image/webp', 'image/gif', 'image/svg+xml'];
        if (! in_array($mime, $allowedMimes, true)) {
            return response()->json(['error' => 'Please upload a PNG, JPEG, WebP, GIF or SVG image.'], 422);
        }

        $id = Ids::new();
        $ext = strtolower($file->getClientOriginalExtension() ?: match ($mime) {
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'image/svg+xml' => 'svg',
            default => 'png',
        });

        $filename = "{$id}.{$ext}";
        $dir = "learners/{$scope->learnerId}/note-images";
        Storage::disk('local')->putFileAs($dir, $file, $filename);

        return response()->json([
            'id' => $id,
            'url' => route('notes.images.show', $id),
        ], 201);
    }

    public function show(Request $request, string $id): StreamedResponse|BinaryFileResponse
    {
        $scope = Guard::learner($this->principals->fromRequest($request));
        $dir = "learners/{$scope->learnerId}/note-images";
        $files = Storage::disk('local')->files($dir);

        $matched = null;
        foreach ($files as $filePath) {
            if (basename($filePath, '.'.pathinfo($filePath, PATHINFO_EXTENSION)) === $id) {
                $matched = $filePath;
                break;
            }
        }

        if (! $matched || ! Storage::disk('local')->exists($matched)) {
            abort(404);
        }

        $ext = strtolower(pathinfo($matched, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            default => 'image/png',
        };

        $headers = [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, max-age=604800, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ];

        return Storage::disk('local')->response($matched, basename($matched), $headers, 'inline');
    }
}
