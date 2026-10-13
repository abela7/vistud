<?php

declare(strict_types=1);

namespace App\Study;

use DateTimeImmutable;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use RuntimeException;
use Throwable;
use XMLReader;
use ZipArchive;

/**
 * Word, PowerPoint and Excel files (Office Open XML: .docx, .pptx, .xlsx) read straight from the file, with no
 * LibreOffice, for the tutor (App\Study\FileTexts): what the file says, in the order and the pages the student sees,
 * and what it says about itself.
 *
 *  - Word: headings, lists and tables kept, cut into the pages Word itself drew (it notes where each began) or, when
 *    it didn't, at the file's own page breaks; otherwise in parts.
 *  - PowerPoint: slides in the order they are shown (not the order of the file's parts), each with its title, text,
 *    tables, chart data, picture descriptions, speaker's notes, and whether it is hidden.
 *  - Excel: every sheet, with the row numbers and column letters, the formulas beside their results, and dates as
 *    dates, in chunks.
 *
 * Nothing in a file is run, and nothing is fetched: only its XML is read, with no external entities, and only so
 * much of it (a part that is too big is read as plain text, or only partly).
 */
final class OfficeReader
{
    private const NS = [
        'w' => 'http://schemas.openxmlformats.org/wordprocessingml/2006/main',
        'wp' => 'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing',
        'a' => 'http://schemas.openxmlformats.org/drawingml/2006/main',
        'p' => 'http://schemas.openxmlformats.org/presentationml/2006/main',
        'c' => 'http://schemas.openxmlformats.org/drawingml/2006/chart',
        'dgm' => 'http://schemas.openxmlformats.org/drawingml/2006/diagram',
        'r' => 'http://schemas.openxmlformats.org/officeDocument/2006/relationships',
        's' => 'http://schemas.openxmlformats.org/spreadsheetml/2006/main',
        'cp' => 'http://schemas.openxmlformats.org/package/2006/metadata/core-properties',
        'dc' => 'http://purl.org/dc/elements/1.1/',
        'dcterms' => 'http://purl.org/dc/terms/',
        'ep' => 'http://schemas.openxmlformats.org/officeDocument/2006/extended-properties',
    ];

    /** A part bigger than this once unpacked isn't read as a tree (that takes about ten times its size in memory). */
    private const TREE_BYTES = 8 * 1024 * 1024;

    /** Nor is a part bigger than this read at all, except a sheet, which is only ever streamed. */
    private const PART_BYTES = 20 * 1024 * 1024;

    /** The most rows of a sheet that are read, the most columns, the most sheets, and the most chunks in all. */
    public const SHEET_ROWS = 1_500;

    private const SHEET_COLUMNS = 60;

    private const SHEETS = 40;

    private const CHUNKS = 300;

    /** A chunk of a sheet is cut at about this many characters (a page is kept to App\Study\FileTexts::PAGE_CHARS). */
    private const CHUNK_CHARS = 4_800;

    /** One level of a list's or a slide's indentation: em spaces, which tidying a page's spaces leaves alone. */
    private const INDENT = "\u{2003}\u{2003}";

    /** A cell's text is kept to this long. */
    private const CELL_CHARS = 200;

    // ---------- Word ----------

    public static function docx(string $path): OfficeRead
    {
        $zip = self::open($path);
        try {
            $facts = self::facts($zip);
            $xml = self::part($zip, 'word/document.xml', self::TREE_BYTES);
            $x = self::xpath($xml);
            if ($x === null) {
                // Too big to read as a tree, or not XML we can read: its paragraphs as plain text.
                return new OfficeRead(FileTexts::parts(self::plain(self::part($zip, 'word/document.xml', self::PART_BYTES), 'w')), 'part', facts: $facts);
            }
            $rels = self::rels($zip, 'word/document.xml');

            $pages = [];
            $page = [];
            $breaks = 0;
            $rendered = 0;
            $tables = 0;
            $pictures = 0;
            $headings = 0;
            $closePage = function () use (&$pages, &$page, &$breaks): void {
                $text = trim(implode("\n", $page));
                $page = [];
                if ($text !== '') {
                    $pages[] = $text;
                    $breaks++;
                }
            };

            $inline = null;
            $inline = function (DOMNode $node, string &$line, callable $onBreak) use (&$inline, &$rendered, &$pictures, $x, $zip, $rels): void {
                foreach ($node->childNodes as $child) {
                    if (! $child instanceof DOMElement) {
                        continue;
                    }
                    switch ($child->localName) {
                        case 't':
                            $line .= $child->textContent;
                            break;
                        case 'tab':
                            $line .= ' ';
                            break;
                        case 'br':
                        case 'cr':
                            if ($child->getAttributeNS(self::NS['w'], 'type') === 'page') {
                                $onBreak();
                            } else {
                                $line .= "\n";
                            }
                            break;
                        case 'lastRenderedPageBreak':
                            $rendered++;
                            $onBreak();
                            break;
                        case 'drawing':
                        case 'pict':
                            $line .= self::drawing($x, $child, $zip, $rels, $pictures);
                            break;
                        case 'rPr':
                        case 'pPr':
                        case 'del':
                        case 'instrText':
                        case 'Fallback':
                            break;
                        default:
                            $inline($child, $line, $onBreak);
                    }
                }
            };

            $paragraph = function (DOMElement $p) use (&$page, &$headings, $closePage, $inline, $x): void {
                $line = '';
                $prefix = self::wordPrefix($x, $p, $headings);
                $flush = function () use (&$line, &$prefix, &$page, $closePage): void {
                    if (trim($line) !== '') {
                        $page[] = $prefix.trim($line);
                        $prefix = '';
                    }
                    $line = '';
                    $closePage();
                };
                if ($x->query('./w:pPr/w:pageBreakBefore', $p)->length > 0) {
                    $flush();
                }
                $inline($p, $line, $flush);
                if (trim($line) !== '') {
                    $page[] = $prefix.trim($line);
                }
                // A section that starts on a new page.
                $section = $x->query('./w:pPr/w:sectPr', $p)->item(0);
                if ($section !== null) {
                    $type = $x->query('./w:type', $section)->item(0);
                    if ($type instanceof DOMElement && $type->getAttributeNS(self::NS['w'], 'val') !== 'continuous') {
                        $closePage();
                    }
                }
            };

            $table = function (DOMElement $tbl) use (&$page, &$tables, $x): void {
                $tables++;
                foreach (self::nodes($x, './w:tr', $tbl) as $row) {
                    $cells = [];
                    foreach (self::nodes($x, './w:tc', $row) as $cell) {
                        $cells[] = self::cell(array_map(fn (DOMNode $p) => self::texts($x, './/w:t', $p), self::nodes($x, './/w:p', $cell)));
                    }
                    $page[] = '| '.implode(' | ', $cells).' |';
                }
            };

            $walk = null;
            $walk = function (DOMNode $parent) use (&$walk, $paragraph, $table): void {
                foreach ($parent->childNodes as $node) {
                    if (! $node instanceof DOMElement) {
                        continue;
                    }
                    match ($node->localName) {
                        'p' => $paragraph($node),
                        'tbl' => $table($node),
                        'sdt', 'sdtContent', 'customXml', 'ins' => $walk($node),
                        default => null,
                    };
                }
            };
            $body = $x->query('/w:document/w:body')->item(0);
            if ($body !== null) {
                $walk($body);
            }
            $last = trim(implode("\n", $page));
            if ($last !== '') {
                $pages[] = $last;
            }
            if ($pages === [] && str_contains($xml, '<w:t')) {
                // A file in other namespaces (the "strict" kind): its paragraphs as plain text.
                return new OfficeRead(FileTexts::parts(self::plain($xml, 'w')), 'part', facts: $facts);
            }

            $text = implode("\n", $pages);
            $facts += array_filter([
                'pages' => (int) self::text(self::xpath(self::part($zip, 'docProps/app.xml')), '//ep:Pages') ?: ($rendered > 0 ? count($pages) : 0),
                'words' => preg_match_all("/[\\p{L}\\p{N}]+(?:['’-][\\p{L}\\p{N}]+)*/u", $text),
                'headings' => $headings,
                'tables' => $tables,
                'pictures' => $pictures,
            ]);
            // Word's own page breaks (it notes where each page began, as it drew them) or the file's make real pages.
            $real = $breaks > 0 && count($pages) > 1;

            return $real
                ? new OfficeRead($pages, 'page', facts: $facts)
                : new OfficeRead(FileTexts::parts($text), 'part', facts: $facts);
        } finally {
            $zip->close();
        }
    }

    /** "# " on a heading, "- " (indented by its level) on a list item, otherwise nothing. */
    private static function wordPrefix(DOMXPath $x, DOMElement $p, int &$headings): string
    {
        $style = (string) $x->evaluate('string(./w:pPr/w:pStyle/@w:val)', $p);
        if (preg_match('/^(?:heading\s*|title|subtitle)(\d?)$/i', str_replace(' ', '', $style), $m) === 1) {
            $headings++;

            return str_repeat('#', max(1, min(6, (int) $m[1] ?: 1))).' ';
        }
        if ($x->query('./w:pPr/w:numPr', $p)->length > 0 || stripos($style, 'ListParagraph') === 0 || stripos($style, 'ListBullet') === 0 || stripos($style, 'ListNumber') === 0) {
            return str_repeat(self::INDENT, max(0, min(5, (int) $x->evaluate('string(./w:pPr/w:numPr/w:ilvl/@w:val)', $p)))).'- ';
        }

        return '';
    }

    // ---------- PowerPoint ----------

    public static function pptx(string $path): OfficeRead
    {
        $zip = self::open($path);
        try {
            $facts = self::facts($zip);
            $pages = [];
            $captions = [];
            $count = ['hidden' => 0, 'notes' => 0, 'pictures' => 0, 'tables' => 0, 'charts' => 0];
            foreach (self::slideParts($zip) as $part) {
                [$text, $caption] = self::slide($zip, $part, $count);
                $pages[] = $text;
                $captions[] = $caption;
            }
            $facts += array_filter([
                'slides' => count($pages),
                'hidden_slides' => $count['hidden'],
                'slides_with_notes' => $count['notes'],
                'pictures' => $count['pictures'],
                'tables' => $count['tables'],
                'charts' => $count['charts'],
            ]);

            return new OfficeRead($pages, 'slide', $captions, $facts);
        } finally {
            $zip->close();
        }
    }

    /**
     * The slides' parts in the order they are shown: as the presentation lists them, which is not the order of the
     * numbers in their names once a slide has been moved. By those numbers when the list can't be read.
     *
     * @return list<string>
     */
    private static function slideParts(ZipArchive $zip): array
    {
        $ordered = [];
        $x = self::xpath(self::part($zip, 'ppt/presentation.xml'));
        if ($x !== null) {
            $rels = self::rels($zip, 'ppt/presentation.xml');
            foreach (self::nodes($x, '//p:sldIdLst/p:sldId') as $id) {
                $target = $rels[$id instanceof DOMElement ? $id->getAttributeNS(self::NS['r'], 'id') : '']['target'] ?? null;
                if ($target !== null && $zip->locateName($target) !== false) {
                    $ordered[] = $target;
                }
            }
        }
        if ($ordered !== []) {
            return $ordered;
        }
        $numbered = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            if (preg_match('#^ppt/slides/slide(\d+)\.xml$#', (string) $zip->getNameIndex($i), $m) === 1) {
                $numbered[(int) $m[1]] = $m[0];
            }
        }
        ksort($numbered);

        return array_values($numbered);
    }

    /**
     * One slide's text, in the order of its shapes, with the speaker's notes after it, and what the slide is called.
     *
     * @param  array<string, int>  $count
     * @return array{0: string, 1: string}
     */
    private static function slide(ZipArchive $zip, string $part, array &$count): array
    {
        $xml = self::part($zip, $part, self::TREE_BYTES);
        $x = self::xpath($xml);
        $rels = self::rels($zip, $part);
        if ($x === null) {
            return [self::plain(self::part($zip, $part, self::PART_BYTES), 'a'), ''];
        }

        $lines = [];
        $title = '';
        foreach (self::nodes($x, '//p:cSld/p:spTree//*[self::p:sp or self::p:graphicFrame or self::p:pic]') as $shape) {
            if (! $shape instanceof DOMElement) {
                continue;
            }
            if ($shape->localName === 'sp') {
                $type = (string) $x->evaluate('string(./p:nvSpPr/p:nvPr/p:ph/@type)', $shape);
                if (in_array($type, ['sldNum', 'ftr', 'dt', 'hdr'], true)) {
                    continue;
                }
                $shapeLines = [];
                foreach (self::nodes($x, './p:txBody/a:p', $shape) as $p) {
                    $text = self::drawingParagraph($p);
                    if ($text !== '') {
                        $shapeLines[] = str_repeat(self::INDENT, max(0, min(4, (int) $x->evaluate('string(./a:pPr/@lvl)', $p)))).$text;
                    }
                }
                if ($title === '' && in_array($type, ['title', 'ctrTitle'], true) && $shapeLines !== []) {
                    $title = trim($shapeLines[0]);
                }
                array_push($lines, ...$shapeLines);
            } elseif ($shape->localName === 'pic') {
                $count['pictures']++;
                $alt = trim((string) $x->evaluate('string(./p:nvPicPr/p:cNvPr/@descr)', $shape));
                if ($alt !== '') {
                    $lines[] = '[Picture: '.$alt.']';
                }
            } else {
                foreach (self::nodes($x, './/a:tbl/a:tr', $shape) as $row) {
                    $cells = [];
                    foreach (self::nodes($x, './a:tc', $row) as $cell) {
                        $cells[] = self::cell(array_map(fn (DOMNode $p) => $p instanceof DOMElement ? self::drawingParagraph($p) : '', self::nodes($x, './/a:p', $cell)));
                    }
                    $lines[] = '| '.implode(' | ', $cells).' |';
                }
                if (self::nodes($x, './/a:tbl', $shape) !== []) {
                    $count['tables']++;
                }
                foreach (self::nodes($x, './/c:chart', $shape) as $chart) {
                    $count['charts']++;
                    $target = $rels[$chart instanceof DOMElement ? $chart->getAttributeNS(self::NS['r'], 'id') : '']['target'] ?? null;
                    $lines[] = $target !== null ? self::chart($zip, $target) : '[Chart]';
                }
                foreach (self::nodes($x, './/dgm:relIds', $shape) as $diagram) {
                    $target = $rels[$diagram instanceof DOMElement ? $diagram->getAttributeNS(self::NS['r'], 'dm') : '']['target'] ?? null;
                    $data = $target !== null ? self::xpath(self::part($zip, $target, self::TREE_BYTES)) : null;
                    $words = $data === null ? [] : array_filter(array_map(fn (DOMNode $t) => trim($t->textContent), self::nodes($data, '//a:t')));
                    if ($words !== []) {
                        $lines[] = '[Diagram: '.implode(' / ', $words).']';
                    }
                }
            }
        }

        if ($lines === [] && str_contains($xml, '<a:t')) {
            // A slide in other namespaces (the "strict" kind): its paragraphs as plain text.
            $lines = [self::plain($xml, 'a')];
        }
        $hidden = $x->document->documentElement?->getAttribute('show') === '0';
        if ($hidden) {
            $count['hidden']++;
            array_unshift($lines, '(Hidden slide: it is skipped when presenting.)');
        }
        $text = implode("\n", $lines);

        foreach ($rels as $rel) {
            if (str_ends_with($rel['type'], '/notesSlide')) {
                $notes = self::notes($zip, $rel['target']);
                if ($notes !== '') {
                    $count['notes']++;
                    $text .= "\n\nSpeaker's notes: ".$notes;
                }
            }
        }
        if ($title === '') {
            foreach (explode("\n", implode("\n", $lines)) as $line) {
                if (trim($line) !== '' && ! str_starts_with($line, '(') && ! str_starts_with($line, '[') && ! str_starts_with($line, '|')) {
                    $title = trim($line);
                    break;
                }
            }
        }

        return [$text, mb_substr($title, 0, 100)];
    }

    /** The speaker's words on a notes slide (its slide number and picture are not). */
    private static function notes(ZipArchive $zip, string $part): string
    {
        $xml = self::part($zip, $part, self::TREE_BYTES);
        $x = self::xpath($xml);
        if ($x === null) {
            return '';
        }
        $paragraphs = self::nodes($x, "//p:sp[./p:nvSpPr/p:nvPr/p:ph[@type='body']]//a:p");
        if ($paragraphs === []) {
            $paragraphs = self::nodes($x, '//a:p');
        }
        $lines = array_map(fn (DOMNode $p) => $p instanceof DOMElement ? self::drawingParagraph($p) : '', $paragraphs);
        if ($lines === [] && str_contains($xml, '<a:t')) {
            $lines = explode("\n", self::plain($xml, 'a'));
        }

        return trim(implode("\n", array_filter($lines, fn (string $line) => trim($line) !== '' && preg_match('/^\d+$/', trim($line)) !== 1)));
    }

    /** A DrawingML paragraph's words (a slide number or a date that updates itself is not words). */
    private static function drawingParagraph(DOMElement $p): string
    {
        $text = '';
        foreach ($p->childNodes as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }
            if ($child->localName === 'br') {
                $text .= ' ';
            } elseif ($child->localName === 'r' || ($child->localName === 'fld' && preg_match('/^(slidenum|datetime)/i', $child->getAttribute('type')) !== 1)) {
                foreach ($child->childNodes as $t) {
                    if ($t instanceof DOMElement && $t->localName === 't') {
                        $text .= $t->textContent;
                    }
                }
            }
        }

        return trim($text);
    }

    // ---------- Excel ----------

    public static function xlsx(string $path): OfficeRead
    {
        $zip = self::open($path);
        try {
            $facts = self::facts($zip);
            $book = self::xpath(self::part($zip, 'xl/workbook.xml', self::TREE_BYTES));
            if ($book === null) {
                throw new RuntimeException('Not a readable workbook.');
            }
            $rels = self::rels($zip, 'xl/workbook.xml');
            $date1904 = in_array(strtolower((string) $book->evaluate('string(//s:workbookPr/@date1904)')), ['1', 'true'], true);
            $strings = self::sharedStrings($path, $zip);
            $dates = self::dateStyles($zip);

            $pages = [];
            $captions = [];
            $named = [];
            $formulas = 0;
            $sheets = 0;
            foreach (self::nodes($book, '//s:sheets/s:sheet') as $sheet) {
                if (! $sheet instanceof DOMElement) {
                    continue;
                }
                $name = $sheet->getAttribute('name');
                $hidden = in_array($sheet->getAttribute('state'), ['hidden', 'veryHidden'], true);
                $target = $rels[$sheet->getAttributeNS(self::NS['r'], 'id')]['target'] ?? null;
                if ($target === null || $zip->locateName($target) === false) {
                    continue;
                }
                $sheets++;
                if ($sheets > self::SHEETS || count($pages) >= self::CHUNKS) {
                    $facts['sheets_not_read'] = ((int) ($facts['sheets_not_read'] ?? 0)) + 1;

                    continue;
                }
                $read = self::sheet($path, $target, $strings, $dates, $date1904);
                $formulas += $read['formulas'];
                $named[] = $name.($read['dimension'] !== '' ? ' ('.$read['dimension'].')' : '').($hidden ? ' [hidden]' : '');
                foreach (self::sheetChunks($name, $read, $hidden) as [$text, $caption]) {
                    $pages[] = $text;
                    $captions[] = $caption;
                }
            }
            $facts += array_filter([
                'sheets' => implode(', ', $named),
                'formulas' => $formulas,
                'charts' => self::countEntries($zip, '#^xl/charts/chart\d+\.xml$#'),
                'pictures' => self::countEntries($zip, '#^xl/media/#'),
                'tables' => self::countEntries($zip, '#^xl/tables/table\d+\.xml$#'),
            ]);

            return new OfficeRead($pages, count($pages) === count($named) ? 'sheet' : 'part', $captions, $facts);
        } finally {
            $zip->close();
        }
    }

    /**
     * One sheet as text, in chunks of rows: a header of the column letters, then a line a row, "12 | Rent | 500",
     * with a formula beside its result, "1234 [=SUM(B2:B9)]".
     *
     * @param  array{rows: array<int, array<int, string>>, dimension: string, truncated: bool, formulas: int}  $read
     * @return list<array{0: string, 1: string}>
     */
    private static function sheetChunks(string $name, array $read, bool $hidden): array
    {
        $label = 'Sheet “'.$name.'”';
        $used = $read['dimension'] !== '' ? ' (used range '.$read['dimension'].')' : '';
        $note = $hidden ? "(Hidden sheet.)\n" : '';
        if ($read['rows'] === []) {
            return [[$note.'(This sheet is empty.)', $label.$used]];
        }

        $chunks = [];
        $rows = [];
        $chars = 0;
        $flush = function () use (&$rows, &$chars, &$chunks, $label, $used, $note): void {
            if ($rows === []) {
                return;
            }
            $width = max(array_map(fn (array $cells) => max(array_keys($cells)), $rows)) + 1;
            $head = 'Row | '.implode(' | ', array_map(fn (int $c) => self::columnLetters($c), range(0, $width - 1)));
            $lines = [];
            foreach ($rows as $number => $cells) {
                $line = [];
                for ($c = 0; $c < $width; $c++) {
                    $line[] = $cells[$c] ?? '';
                }
                $lines[] = $number.' | '.implode(' | ', $line);
            }
            $first = array_key_first($rows);
            $lastRow = array_key_last($rows);
            $chunks[] = [($chunks === [] ? $note : '').$head."\n".implode("\n", $lines), $label.($lastRow !== $first ? ', rows '.$first.'–'.$lastRow : ', row '.$first).$used];
            $rows = [];
            $chars = 0;
        };
        foreach ($read['rows'] as $number => $cells) {
            $size = array_sum(array_map('mb_strlen', $cells)) + count($cells) * 3 + 8;
            if ($rows !== [] && $chars + $size > self::CHUNK_CHARS) {
                $flush();
            }
            $rows[$number] = $cells;
            $chars += $size;
        }
        $flush();
        if ($read['truncated']) {
            $last = array_key_last($chunks);
            $chunks[$last][0] .= "\n(The sheet goes on: only its first ".number_format(self::SHEET_ROWS).' rows with something in them are read.)';
        }

        return $chunks;
    }

    /**
     * A sheet's rows with something in them, streamed so a big sheet never sits in memory whole.
     *
     * @param  list<string>  $strings
     * @param  array<int, bool>  $dates
     * @return array{rows: array<int, array<int, string>>, dimension: string, truncated: bool, formulas: int}
     */
    private static function sheet(string $path, string $part, array $strings, array $dates, bool $date1904): array
    {
        $out = ['rows' => [], 'dimension' => '', 'truncated' => false, 'formulas' => 0];
        $reader = self::stream($path, $part);
        if ($reader === null) {
            return $out;
        }
        $hiddenColumns = [];
        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT) {
                continue;
            }
            if ($reader->localName === 'dimension') {
                $out['dimension'] = (string) $reader->getAttribute('ref');

                continue;
            }
            if ($reader->localName === 'col' && self::yes($reader->getAttribute('hidden')) && (int) $reader->getAttribute('min') >= 1) {
                for ($c = (int) $reader->getAttribute('min') - 1; $c < min((int) $reader->getAttribute('max'), self::SHEET_COLUMNS); $c++) {
                    $hiddenColumns[$c] = true;
                }

                continue;
            }
            if ($reader->localName !== 'row' || $reader->isEmptyElement || self::yes($reader->getAttribute('hidden'))) {
                continue;
            }
            $number = (int) $reader->getAttribute('r');
            $row = @simplexml_load_string($reader->readOuterXml(), options: LIBXML_NONET);
            if ($row === false) {
                continue;
            }
            $cells = [];
            foreach ($row->c as $cell) {
                $column = self::columnIndex((string) $cell['r']);
                if ($column >= self::SHEET_COLUMNS || isset($hiddenColumns[$column])) {
                    continue;
                }
                $value = self::cellValue($cell, $strings, $dates, $date1904, $out['formulas']);
                if ($value !== '') {
                    $cells[$column] = $value;
                }
            }
            if ($cells === []) {
                continue;
            }
            if (count($out['rows']) >= self::SHEET_ROWS) {
                $out['truncated'] = true;
                break;
            }
            ksort($cells);
            $out['rows'][$number ?: (array_key_last($out['rows']) ?? 0) + 1] = $cells;
        }
        $reader->close();

        return $out;
    }

    /**
     * A cell as the student sees it: text, a number without the noise of floating point, a date as a date; and its
     * formula beside it.
     *
     * @param  list<string>  $strings
     * @param  array<int, bool>  $dates
     */
    private static function cellValue(\SimpleXMLElement $cell, array $strings, array $dates, bool $date1904, int &$formulas): string
    {
        $type = (string) $cell['t'];
        $raw = isset($cell->v) ? (string) $cell->v : '';
        $value = match ($type) {
            's' => $strings[(int) $raw] ?? '',
            'inlineStr' => self::inlineString(isset($cell->is) ? $cell->is : null),
            'b' => $raw === '1' ? 'TRUE' : ($raw === '' ? '' : 'FALSE'),
            'e', 'str', 'd' => $raw,
            default => self::number($raw, $dates[(int) $cell['s']] ?? false, $date1904),
        };
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));
        $formula = isset($cell->f) ? trim((string) $cell->f) : '';
        if ($formula !== '') {
            $formulas++;
            $value = trim($value.' [='.mb_substr($formula, 0, 120).']');
        }

        return mb_substr(str_replace('|', '/', $value), 0, self::CELL_CHARS);
    }

    private static function inlineString(?\SimpleXMLElement $is): string
    {
        if ($is === null) {
            return '';
        }
        $text = '';
        foreach ($is->xpath('.//*[local-name()="t"][not(ancestor::*[local-name()="rPh"])]') ?: [] as $t) {
            $text .= (string) $t;
        }

        return $text;
    }

    /** @return list<string> the workbook's shared strings (what most text cells point to), streamed */
    private static function sharedStrings(string $path, ZipArchive $zip): array
    {
        if ($zip->locateName('xl/sharedStrings.xml') === false) {
            return [];
        }
        $reader = self::stream($path, 'xl/sharedStrings.xml');
        if ($reader === null) {
            return [];
        }
        $strings = [];
        $current = null;
        $phonetic = 0;
        while ($reader->read() && count($strings) < 200_000) {
            if ($reader->nodeType === XMLReader::ELEMENT) {
                if ($reader->localName === 'si') {
                    $current = '';
                    if ($reader->isEmptyElement) {
                        $strings[] = '';
                        $current = null;
                    }
                } elseif ($reader->localName === 'rPh' && ! $reader->isEmptyElement) {
                    $phonetic++;
                } elseif ($reader->localName === 't' && $current !== null && $phonetic === 0 && ! $reader->isEmptyElement) {
                    $current .= $reader->readString();
                }
            } elseif ($reader->nodeType === XMLReader::END_ELEMENT) {
                if ($reader->localName === 'si' && $current !== null) {
                    $strings[] = mb_substr($current, 0, self::CELL_CHARS * 2);
                    $current = null;
                } elseif ($reader->localName === 'rPh') {
                    $phonetic = max(0, $phonetic - 1);
                }
            }
        }
        $reader->close();

        return $strings;
    }

    /** @return array<int, bool> for each cell style: whether it shows a date or a time */
    private static function dateStyles(ZipArchive $zip): array
    {
        $x = self::xpath(self::part($zip, 'xl/styles.xml', self::TREE_BYTES));
        if ($x === null) {
            return [];
        }
        $codes = [];
        foreach (self::nodes($x, '//s:numFmts/s:numFmt') as $format) {
            if ($format instanceof DOMElement) {
                $codes[(int) $format->getAttribute('numFmtId')] = $format->getAttribute('formatCode');
            }
        }
        $styles = [];
        foreach (self::nodes($x, '//s:cellXfs/s:xf') as $i => $xf) {
            $id = $xf instanceof DOMElement ? (int) $xf->getAttribute('numFmtId') : 0;
            $styles[$i] = in_array($id, [14, 15, 16, 17, 18, 19, 20, 21, 22, 45, 46, 47], true)
                || (isset($codes[$id]) && preg_match('/[ymdhs]/i', (string) preg_replace('/"[^"]*"|\[[^\]]*\]|\\\\./', '', $codes[$id])) === 1);
        }

        return $styles;
    }

    private static function number(string $raw, bool $date, bool $date1904): string
    {
        if ($raw === '' || ! is_numeric($raw)) {
            return $raw;
        }
        $number = (float) $raw;
        if ($date && ($shown = self::serialDate($number, $date1904)) !== null) {
            return $shown;
        }

        return $number == floor($number) && abs($number) < 1e15 ? (string) (int) $number : sprintf('%.12G', $number);
    }

    /** An Excel date serial as a date, or a date and time, or a time of day. */
    private static function serialDate(float $serial, bool $date1904): ?string
    {
        $days = (int) floor($serial);
        if ($serial < 0 || $days > 2_958_465) {
            return null;
        }
        $seconds = (int) round(($serial - $days) * 86_400);
        $base = $date1904 ? '1904-01-01' : ($days >= 61 ? '1899-12-30' : '1899-12-31');
        $moment = (new DateTimeImmutable($base.' 00:00:00 UTC'))->modify("+{$days} days")->modify("+{$seconds} seconds");

        return match (true) {
            $seconds === 0 => $moment->format('Y-m-d'),
            $days === 0 => $moment->format($seconds % 60 === 0 ? 'H:i' : 'H:i:s'),
            default => $moment->format('Y-m-d H:i'),
        };
    }

    /** An XML true: Excel writes "1", LibreOffice "true". */
    private static function yes(?string $value): bool
    {
        return in_array(strtolower((string) $value), ['1', 'true'], true);
    }

    /** "B" is 1, "AA" is 26: a cell reference's column, from 0. */
    private static function columnIndex(string $reference): int
    {
        $index = 0;
        foreach (str_split(strtoupper((string) preg_replace('/[^A-Za-z]/', '', $reference))) as $letter) {
            $index = $index * 26 + (ord($letter) - 64);
        }

        return max(0, $index - 1);
    }

    private static function columnLetters(int $index): string
    {
        $letters = '';
        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $letters = chr(65 + ($n - 1) % 26).$letters;
        }

        return $letters;
    }

    // ---------- Charts, and what a file says about itself ----------

    /** A chart as words: its kind, title, and each series with its points, "[Chart “Sales” (bar): Series “2025”: Q1=10, Q2=12]". */
    private static function chart(ZipArchive $zip, string $part): string
    {
        $x = self::xpath(self::part($zip, $part, self::TREE_BYTES));
        if ($x === null) {
            return '[Chart]';
        }
        $title = trim(self::texts($x, '//c:chart/c:title//a:t'));
        $kind = '';
        $plot = $x->query('//c:plotArea/*[substring(local-name(), string-length(local-name()) - 4) = "Chart"]')->item(0);
        if ($plot instanceof DOMElement) {
            $kind = preg_replace('/3D$/', '', substr($plot->localName, 0, -5));
        }
        $series = [];
        foreach (self::nodes($x, '//c:ser') as $ser) {
            $points = function (string $query) use ($x, $ser): array {
                $out = [];
                foreach (self::nodes($x, $query, $ser) as $pt) {
                    if ($pt instanceof DOMElement) {
                        $out[(int) $pt->getAttribute('idx')] = trim($pt->textContent);
                    }
                }

                return $out;
            };
            $categories = $points('./c:cat//c:pt');
            $values = $points('./c:val//c:pt');
            $pairs = [];
            foreach (array_slice($values, 0, 24, true) as $i => $value) {
                $pairs[] = (($categories[$i] ?? '') !== '' ? $categories[$i].'=' : '').$value;
            }
            $name = trim(self::texts($x, './c:tx//c:v', $ser));
            $series[] = 'Series'.($name !== '' ? ' “'.$name.'”' : '').': '.implode(', ', $pairs);
        }

        return '[Chart'.($title !== '' ? ' “'.$title.'”' : '').($kind !== '' ? ' ('.strtolower($kind).')' : '').($series !== [] ? ': '.implode('; ', $series) : '').']';
    }

    /**
     * What the file says about itself, from its document properties: title, author, who saved it last, and when.
     *
     * @return array<string, string|int>
     */
    private static function facts(ZipArchive $zip): array
    {
        $facts = [];
        $core = self::xpath(self::part($zip, 'docProps/core.xml'));
        if ($core !== null) {
            foreach (['title' => 'dc:title', 'subject' => 'dc:subject', 'author' => 'dc:creator', 'last_saved_by' => 'cp:lastModifiedBy'] as $key => $query) {
                $value = trim(self::texts($core, '//'.$query));
                if ($value !== '') {
                    $facts[$key] = mb_substr($value, 0, 150);
                }
            }
            foreach (['created' => 'dcterms:created', 'modified' => 'dcterms:modified'] as $key => $query) {
                $value = trim(self::texts($core, '//'.$query));
                try {
                    if ($value !== '') {
                        $facts[$key] = (new DateTimeImmutable($value))->format('Y-m-d H:i');
                    }
                } catch (Throwable) {
                    // A date that can't be read is left out.
                }
            }
        }
        $application = trim(self::text(self::xpath(self::part($zip, 'docProps/app.xml')), '//ep:Application'));
        if ($application !== '') {
            $facts['application'] = mb_substr($application, 0, 80);
        }

        return $facts;
    }

    // ---------- The package ----------

    private static function open(string $path): ZipArchive
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('Not a readable Office file.');
        }

        return $zip;
    }

    /** One part of the file, if it isn't unreasonably big once unpacked; '' when it isn't there or is. */
    private static function part(ZipArchive $zip, string $name, int $limit = self::PART_BYTES): string
    {
        $stat = $zip->statName($name);
        if ($stat === false || $stat['size'] > $limit) {
            return '';
        }

        return (string) $zip->getFromName($name);
    }

    private static function countEntries(ZipArchive $zip, string $pattern): int
    {
        $count = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $count += (int) (preg_match($pattern, (string) $zip->getNameIndex($i)) === 1);
        }

        return $count;
    }

    /** A part streamed from the file, without unpacking it whole; null when it can't be opened. */
    private static function stream(string $path, string $part): ?XMLReader
    {
        $reader = new XMLReader;
        $opened = @$reader->open('zip://'.str_replace('\\', '/', $path).'#'.$part, null, LIBXML_NONET | LIBXML_COMPACT);

        return $opened ? $reader : null;
    }

    /** An XML part as a tree to ask questions of, with the namespaces Office uses; null when it isn't readable XML. */
    private static function xpath(string $xml): ?DOMXPath
    {
        if (trim($xml) === '') {
            return null;
        }
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument;
        $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $loaded) {
            return null;
        }
        $x = new DOMXPath($document);
        foreach (self::NS as $prefix => $uri) {
            $x->registerNamespace($prefix, $uri);
        }

        return $x;
    }

    /** @return list<DOMNode> */
    private static function nodes(DOMXPath $x, string $query, ?DOMNode $context = null): array
    {
        $found = $context === null ? $x->query($query) : $x->query($query, $context);

        return $found === false ? [] : iterator_to_array($found, false);
    }

    /** The text of everything the query finds, run together. */
    private static function texts(DOMXPath $x, string $query, ?DOMNode $context = null): string
    {
        return implode('', array_map(fn (DOMNode $node) => $node->textContent, self::nodes($x, $query, $context)));
    }

    private static function text(?DOMXPath $x, string $query): string
    {
        return $x === null ? '' : self::texts($x, $query);
    }

    /**
     * A part's relationships: the parts it points to, by the id it calls them, as paths within the file.
     *
     * @return array<string, array{target: string, type: string}>
     */
    private static function rels(ZipArchive $zip, string $part): array
    {
        $folder = dirname($part) === '.' ? '' : dirname($part);
        $x = self::xpath(self::part($zip, ($folder !== '' ? $folder.'/' : '').'_rels/'.basename($part).'.rels'));
        if ($x === null) {
            return [];
        }
        $rels = [];
        foreach (self::nodes($x, '//*[local-name()="Relationship"]') as $rel) {
            if (! $rel instanceof DOMElement || $rel->getAttribute('TargetMode') === 'External') {
                continue;
            }
            $target = $rel->getAttribute('Target');
            $segments = $target !== '' && $target[0] === '/' ? [] : ($folder === '' ? [] : explode('/', $folder));
            foreach (explode('/', ltrim($target, '/')) as $segment) {
                if ($segment === '..') {
                    array_pop($segments);
                } elseif ($segment !== '.' && $segment !== '') {
                    $segments[] = $segment;
                }
            }
            $rels[$rel->getAttribute('Id')] = ['target' => implode('/', $segments), 'type' => $rel->getAttribute('Type')];
        }

        return $rels;
    }

    /** A picture or a chart in a Word paragraph: its description, or the chart as words. Counts the pictures. */
    private static function drawing(DOMXPath $x, DOMElement $drawing, ZipArchive $zip, array $rels, int &$pictures): string
    {
        $out = '';
        if (self::nodes($x, './/a:blip', $drawing) !== []) {
            $pictures++;
            $alt = trim((string) $x->evaluate('string(.//wp:docPr/@descr)', $drawing));
            $out .= $alt !== '' ? ' [Picture: '.$alt.'] ' : '';
        }
        foreach (self::nodes($x, './/c:chart', $drawing) as $chart) {
            $target = $rels[$chart instanceof DOMElement ? $chart->getAttributeNS(self::NS['r'], 'id') : '']['target'] ?? null;
            $out .= ' '.($target !== null ? self::chart($zip, $target) : '[Chart]').' ';
        }

        return $out;
    }

    /**
     * A table cell as one line: its paragraphs run together, and a bar that would split the row made a slash.
     *
     * @param  list<string>  $paragraphs
     */
    private static function cell(array $paragraphs): string
    {
        return trim(str_replace('|', '/', (string) preg_replace('/\s+/u', ' ', implode(' ', array_filter(array_map('trim', $paragraphs))))));
    }

    /** The text of an Office XML part, a line a paragraph, when it can't be read as a tree: `a` for PowerPoint's, `w` for Word's. */
    private static function plain(string $xml, string $ns): string
    {
        $lines = [];
        preg_match_all('#<'.$ns.':p\b[^>]*>(.*?)</'.$ns.':p>#s', $xml, $paragraphs);
        foreach ($paragraphs[1] as $paragraph) {
            $paragraph = (string) preg_replace('#<'.$ns.':(tab|br)\b[^>]*/>#', ' ', $paragraph);
            preg_match_all('#<'.$ns.':t(?:\s[^>]*)?>(.*?)</'.$ns.':t>#s', $paragraph, $runs);
            $lines[] = html_entity_decode(implode('', $runs[1]), ENT_QUOTES | ENT_XML1, 'UTF-8');
        }

        return implode("\n", $lines);
    }
}
