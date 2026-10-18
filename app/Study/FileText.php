<?php

namespace App\Study;

/**
 * What a file says, as App\Study\FileTexts read it: its pages (a PDF's pages, a PowerPoint's slides, a Word file's
 * pages or parts, an Excel file's sheets in chunks, or a text file cut into parts), what each one is, and what the
 * file says about itself; or why there are none: still being converted, a picture, or not readable here.
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

    /** How each fact about a file is put: the sentence with its value in; a counted one has its singular and plural. */
    private const WORDS = [
        'title' => 'title “%s”',
        'subject' => 'subject “%s”',
        'author' => 'author %s',
        'created' => 'created %s',
        'last_saved_by' => 'last saved by %s',
        'modified' => 'last saved %s',
        'application' => 'made with %s',
        'headings' => ['%s heading', '%s headings'],
        'tables' => ['%s table', '%s tables'],
        'charts' => ['%s chart', '%s charts'],
        'pictures' => ['%s picture', '%s pictures'],
        'formulas' => ['%s formula', '%s formulas'],
        'hidden_slides' => ['%s hidden slide', '%s hidden slides'],
        'slides_with_notes' => ['%s slide with speaker\'s notes', '%s slides with speaker\'s notes'],
        'sheets' => 'sheets: %s',
        'sheets_not_read' => ['%s more sheet than can be read here', '%s more sheets than can be read here'],
    ];

    /**
     * @param  list<string>  $pages
     * @param  list<string>  $captions  what each page is ("Sheet “Budget”, rows 1–45"), or none
     * @param  array<string, string|int>  $facts  what the file says about itself
     */
    public function __construct(
        public FileDetails $file,
        public string $state,
        public array $pages = [],
        /** page · slide · part · sheet */
        public string $unit = 'page',
        public array $captions = [],
        public array $facts = [],
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

    /** What page $number is: a slide's title, a sheet and its rows; '' when it isn't known. */
    public function caption(int $number): string
    {
        return $this->captions[$number - 1] ?? '';
    }

    /** "18 pages", "1 slide". */
    public function size(): string
    {
        return $this->count().' '.$this->unit.($this->count() === 1 ? '' : 's');
    }

    /**
     * What the file is, for the tutor: its name, kind and size, how long it is, when it was uploaded, and whatever it
     * says about itself (who wrote it, when, how many tables, pictures, charts, formulas, hidden slides, sheets).
     */
    public function profile(): string
    {
        $facts = $this->facts;
        $length = $this->size();
        // Word counts its own pages; a file read in parts is as long as Word says.
        if ($this->unit === 'part' && ($facts['pages'] ?? 0) > 0) {
            $length = $facts['pages'].' '.($facts['pages'] === 1 ? 'page' : 'pages').', read in '.$this->size();
        } elseif (($facts['pages'] ?? 0) > 0 && $this->unit === 'page' && $this->count() !== (int) $facts['pages']) {
            $length .= ' read, '.$facts['pages'].' counted by the program that made it';
        }
        $head = [$this->file->typeLabel(), $this->file->humanSize(), $length];
        if (($facts['words'] ?? 0) > 0) {
            $head[] = number_format((int) $facts['words']).' words';
        }
        $uploaded = substr($this->file->uploadedAt, 0, 10);
        $line = 'File “'.Fence::escape($this->file->fileName()).'”: '.implode(', ', $head).($uploaded !== '' ? '; uploaded '.$uploaded : '').'.';

        $about = [];
        foreach (self::WORDS as $key => $words) {
            if (($facts[$key] ?? '') !== '' && ($facts[$key] ?? 0) !== 0) {
                $about[] = sprintf(is_array($words) ? $words[(int) $facts[$key] === 1 ? 0 : 1] : $words, $facts[$key]);
            }
        }

        return $about === [] ? $line : $line."\nIt says of itself: ".Fence::wrap(implode('; ', $about).'.');
    }
}
