<?php

namespace App\Platform\Files;

use App\Platform\Access\LearnerScope;
use RuntimeException;

/** Trusted service callers supply a scope; disk names never supply a learner key. */
final readonly class FileIdentity
{
    public function __construct(public string $learner, public string $object, public string $kind) {}

    public static function at(LearnerScope $scope, string $path): self
    {
        if (preg_match('#^learners/([^/]+)/(files|previews|note-images)/([a-zA-Z0-9-]{1,64})(?:\.(pdf|docx|pptx|xlsx|png|jpg|webp|gif))?$#D', $path, $m) !== 1 || $m[1] !== $scope->learnerId) {
            throw new RuntimeException('Invalid private file identity.');
        }
        $extension = $m[4] ?? '';
        $kind = match ($m[2]) {
            'files' => $extension === '' ? 'upload' : null,
            'previews' => in_array($extension, ['pdf', 'docx', 'pptx', 'xlsx'], true) ? 'preview:'.$extension : null,
            'note-images' => in_array($extension, ['png', 'jpg', 'webp', 'gif'], true) ? 'note-image:'.$extension : null,
        };
        if ($kind === null) {
            throw new RuntimeException('Invalid private file identity.');
        }

        return new self($scope->learnerId, $m[3], $kind);
    }

    public function aad(string $generation, int $index, int $bytes): string
    {
        return FileCipher::MAGIC.json_encode([1, $this->learner, $this->object, $this->kind, bin2hex($generation), $index, $bytes], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
