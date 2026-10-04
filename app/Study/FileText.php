<?php

namespace App\Study;

/**
 * What a file says, as App\Study\FileTexts read it: its pages (a PDF's pages, a PowerPoint's slides, or a Word or
 * text file cut into parts), or why there are none: still being converted, a picture, or not readable here.
 */
final readonly class FileText
{
    /** The pages are ready to read (some may be empty, as on a scanned page). */
    public const READY = 'ready';

    /** A Word, PowerPoint or Excel file whose PDF is still being made; ask again shortly. */
    public const PREPARING = 'preparing';

    /** A picture: it is seen, not read. */
    public const PICTURE = 'picture';

    /** Not readable here (no LibreOffice for this kind, or the file couldn't be read). */
    public const NONE = 'none';

    /**
     * @param  list<string>  $pages
     */
    public function __construct(
        public FileDetails $file,
        public string $state,
        public array $pages = [],
        /** page · slide · part */
        public string $unit = 'page',
    ) {}

    public function count(): int
    {
        return count($this->pages);
    }

    /** Whether any page has words: a scanned PDF has pages but none. */
    public function hasWords(): bool
    {
        foreach ($this->pages as $page) {
            if (trim($page) !== '') {
                return true;
            }
        }

        return false;
    }

    /** "Page 4 of 18", "Slide 4 of 18", "Part 2 of 5". */
    public function label(int $number): string
    {
        return ucfirst($this->unit).' '.$number.' of '.$this->count();
    }

    /** "18 pages", "1 slide". */
    public function size(): string
    {
        return $this->count().' '.$this->unit.($this->count() === 1 ? '' : 's');
    }
}
