<?php

namespace App\Study;

use Illuminate\Support\Number;

/** An uploaded file, as the screens see it. $moduleId and $folderId say where it is; both null is the workspace's top level. */
final readonly class FileDetails
{
    public function __construct(
        public string $id,
        public string $workspaceId,
        public ?string $moduleId,
        public ?string $folderId,
        public string $name,
        public string $extension,
        public string $kind,
        public string $mime,
        public int $size,
        public int $position,
        public string $uploadedAt,
        public ?string $trashedAt,
    ) {}

    public function fileName(): string
    {
        return "{$this->name}.{$this->extension}";
    }

    /** "PDF", "Word document", "PowerPoint"… */
    public function typeLabel(): string
    {
        return FileTypes::TYPES[$this->extension][2] ?? strtoupper($this->extension);
    }

    public function humanSize(): string
    {
        return Number::fileSize($this->size, precision: $this->size < 1024 * 1024 ? 0 : 1);
    }

    /** Shown in the browser itself; the rest are downloaded. */
    public function previewable(): bool
    {
        return in_array($this->kind, ['pdf', 'image', 'text'], true);
    }

    public function icon(): string
    {
        return ['pdf' => 'file-text', 'document' => 'file-text', 'slides' => 'presentation', 'spreadsheet' => 'file-spreadsheet', 'text' => 'file', 'image' => 'file-image'][$this->kind] ?? 'file';
    }

    public function placeKey(): string
    {
        return Input::placeKey($this->workspaceId, $this->moduleId, $this->folderId);
    }
}
