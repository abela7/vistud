<?php

namespace App\Platform\Files;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class PrivateFileResponse
{
    public static function make(Request $request, PrivateFileReader $reader, string $name, array $headers, bool $download = false): StreamedResponse
    {
        $headers = array_replace($headers, [
            'Accept-Ranges' => 'bytes', 'ETag' => $reader->etag,
            'Cache-Control' => 'private, no-store',
            'Content-Disposition' => HeaderUtils::makeDisposition($download ? 'attachment' : 'inline', $name, self::ascii($name)),
        ]);
        $start = 0;
        $length = $reader->size;
        $status = 200;
        $range = $request->header('Range');
        if (! $request->isMethod('HEAD') && $range !== null && ($request->header('If-Range') === null || $request->header('If-Range') === $reader->etag)) {
            $valid = preg_match('/^bytes=(\d*)-(\d*)$/D', $range, $parts) === 1 && ($parts[1] !== '' || $parts[2] !== '') && $reader->size > 0;
            if ($valid) {
                if ($parts[1] === '') {
                    $suffix = (int) $parts[2];
                    $start = max(0, $reader->size - $suffix);
                    $end = $reader->size - 1;
                    $valid = $suffix > 0;
                } else {
                    $start = (int) $parts[1];
                    $end = $parts[2] === '' ? $reader->size - 1 : min($reader->size - 1, (int) $parts[2]);
                    $valid = $start < $reader->size && $end >= $start;
                }
            }
            if (! $valid) {
                $reader->close();

                return new StreamedResponse(null, 416, $headers + ['Content-Range' => 'bytes */'.$reader->size, 'Content-Length' => '0']);
            }
            $length = $end - $start + 1;
            $status = 206;
            $headers['Content-Range'] = 'bytes '.$start.'-'.$end.'/'.$reader->size;
        }
        $headers['Content-Length'] = (string) $length;
        if ($request->isMethod('HEAD')) {
            $reader->close();

            return new StreamedResponse(null, $status, $headers);
        }
        try {
            // No successful headers/body for a corrupt selected range. Keep the same
            // descriptor for streaming; service writes publish immutable replacements.
            $reader->verify($start, $length);
        } catch (\Throwable $e) {
            $reader->close();
            throw $e;
        }

        return new StreamedResponse(function () use ($reader, $start, $length) {
            try {
                foreach ($reader->chunks($start, $length) as $chunk) {
                    echo $chunk;
                }
            } finally {
                $reader->close();
            }
        }, $status, $headers);
    }

    private static function ascii(string $name): string
    {
        return preg_replace('/[^A-Za-z0-9._ -]/', '_', str_replace(['/', '\\', '%'], '_', $name)) ?: 'file';
    }
}
