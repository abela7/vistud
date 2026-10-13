<?php

declare(strict_types=1);

namespace App\Study;

/**
 * What a Word, PowerPoint or Excel file says, as App\Study\OfficeReader read it: its pages (real pages, slides, or
 * a sheet's rows in chunks), what each one is, and what the file says about itself.
 */
final readonly class OfficeRead
{
    /**
     * @param  list<string>  $pages  page · slide · part · a chunk of a sheet's rows
     * @param  list<string>  $captions  what each of the pages is ("Sheet “Budget”, rows 1–45"); none, or one each
     * @param  array<string, string|int>  $facts  title, author, dates, counts…: see App\Study\FileText::profile()
     */
    public function __construct(
        public array $pages,
        /** page · slide · part · sheet */
        public string $unit,
        public array $captions = [],
        public array $facts = [],
    ) {}
}
