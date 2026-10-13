<?php

namespace Tests\Unit\Study;

use App\Study\OfficeReader;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\MakesOfficeFiles;

/** Word, PowerPoint and Excel files read straight from the file, for the tutor (App\Study\OfficeReader). */
class OfficeReaderTest extends TestCase
{
    use MakesOfficeFiles;

    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        array_map(fn (string $path) => @unlink($path), $this->paths);
        parent::tearDown();
    }

    /** The bytes as a file on disk, removed when the test ends. */
    private function file(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'vistud-reader-');
        file_put_contents($path, $bytes);

        return $this->paths[] = $path;
    }

    // ---------- Word ----------

    public function test_a_word_document_keeps_its_headings_lists_and_tables_and_splits_at_its_page_breaks(): void
    {
        $body = $this->wPara('Why cells divide', 'Heading1')
            .$this->wPara('Cells divide to grow and repair.')
            .$this->wPara('Growth', null, 0).$this->wPara('Repair', null, 1)
            .$this->wTable([['Phase', 'Result'], ['Mitosis', '2 | cells']])
            .'<w:p><w:r><w:br w:type="page"/></w:r></w:p>'
            .$this->wPara('Second page here.', 'Heading2');
        $read = OfficeReader::docx($this->file($this->docxBytes($body, ['title' => 'Essay', 'creator' => 'Ada', 'lastModifiedBy' => 'Alan', 'modified' => '2026-10-01T16:40:00Z'], pages: 2)));

        $this->assertSame('page', $read->unit);
        $this->assertCount(2, $read->pages);
        $this->assertSame("# Why cells divide\nCells divide to grow and repair.\n- Growth\n\u{2003}\u{2003}- Repair\n| Phase | Result |\n| Mitosis | 2 / cells |", $read->pages[0]);
        $this->assertSame('## Second page here.', $read->pages[1]);
        $this->assertSame(['title' => 'Essay', 'author' => 'Ada', 'last_saved_by' => 'Alan', 'modified' => '2026-10-01 16:40', 'application' => 'Microsoft Office Word', 'pages' => 2, 'words' => 19, 'headings' => 2, 'tables' => 1], $read->facts);
    }

    public function test_the_pages_word_itself_drew_are_the_pages_and_a_document_without_any_is_read_in_parts(): void
    {
        // Word notes in the file where each page began, as it drew them.
        $drawn = $this->wPara('First page.').'<w:p><w:r><w:lastRenderedPageBreak/><w:t>Second page.</w:t></w:r></w:p><w:p><w:r><w:lastRenderedPageBreak/><w:t>Third page.</w:t></w:r></w:p>';
        $read = OfficeReader::docx($this->file($this->docxBytes($drawn)));
        $this->assertSame(['page', ['First page.', 'Second page.', 'Third page.'], 3], [$read->unit, $read->pages, $read->facts['pages']]);

        $long = str_repeat('Meiosis halves the chromosomes. ', 60);
        $parts = OfficeReader::docx($this->file($this->docxBytes($this->wPara($long).$this->wPara($long).$this->wPara('The end.'))));
        $this->assertSame('part', $parts->unit);
        $this->assertGreaterThan(1, count($parts->pages));
        $this->assertArrayNotHasKey('pages', $parts->facts);
    }

    public function test_a_word_file_in_other_namespaces_is_still_read_as_plain_text(): void
    {
        $bytes = $this->docxBytes('', extra: ['word/document.xml' => '<w:document><w:body><w:p><w:r><w:t>Strict &amp; plain</w:t></w:r></w:p></w:body></w:document>']);
        $this->assertSame(['Strict & plain'], OfficeReader::docx($this->file($bytes))->pages);
    }

    // ---------- PowerPoint ----------

    public function test_slides_are_in_the_order_they_are_shown_with_their_text_tables_pictures_notes_and_whether_hidden(): void
    {
        $deck = $this->pptxBytes([
            $this->slideTitle('Cell division').$this->slideText([['Why cells divide'], ['Growth', 1], ['Repair', 1]]),
            $this->slideTitle('Mitosis').$this->slideTable([['Phase', 'What happens'], ['Prophase', 'Chromosomes condense']]).$this->slidePicture('Diagram of the four phases'),
            $this->slideTitle('Summary').$this->slideText([['Two identical cells']]),
        ], order: [3, 1, 2], notes: [2 => 'Stress the word identical.'], hidden: [1], core: ['title' => 'Week 2', 'creator' => 'Ada']);
        $read = OfficeReader::pptx($this->file($deck));

        // Slide 3 was moved to the front: the file's numbers are not the order.
        $this->assertSame(['slide', ['Summary', 'Cell division', 'Mitosis']], [$read->unit, $read->captions]);
        $this->assertSame("Summary\nTwo identical cells", $read->pages[0]);
        $this->assertSame("(Hidden slide: it is skipped when presenting.)\nCell division\nWhy cells divide\n\u{2003}\u{2003}Growth\n\u{2003}\u{2003}Repair", $read->pages[1]);
        $this->assertSame("Mitosis\n| Phase | What happens |\n| Prophase | Chromosomes condense |\n[Picture: Diagram of the four phases]\n\nSpeaker's notes: Stress the word identical.", $read->pages[2]);
        $this->assertSame(['title' => 'Week 2', 'author' => 'Ada', 'application' => 'Microsoft Office PowerPoint', 'slides' => 3, 'hidden_slides' => 1, 'slides_with_notes' => 1, 'pictures' => 1, 'tables' => 1], $read->facts);
    }

    public function test_a_chart_on_a_slide_is_read_as_its_title_and_numbers(): void
    {
        $chart = '<c:chartSpace xmlns:c="http://schemas.openxmlformats.org/drawingml/2006/chart" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"><c:chart><c:title><c:tx><c:rich><a:p><a:r><a:t>Sales</a:t></a:r></a:p></c:rich></c:tx></c:title><c:plotArea><c:barChart>'
            .'<c:ser><c:tx><c:strRef><c:strCache><c:pt idx="0"><c:v>2025</c:v></c:pt></c:strCache></c:strRef></c:tx><c:cat><c:strRef><c:strCache><c:pt idx="0"><c:v>Q1</c:v></c:pt><c:pt idx="1"><c:v>Q2</c:v></c:pt></c:strCache></c:strRef></c:cat><c:val><c:numRef><c:numCache><c:pt idx="0"><c:v>10</c:v></c:pt><c:pt idx="1"><c:v>12</c:v></c:pt></c:numCache></c:numRef></c:val></c:ser>'
            .'</c:barChart></c:plotArea></c:chart></c:chartSpace>';
        $deck = $this->pptxBytes([$this->slideTitle('Results').$this->slideChart('rId5')], extra: [
            'ppt/slides/_rels/slide1.xml.rels' => '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId5" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/chart" Target="../charts/chart1.xml"/></Relationships>',
            'ppt/charts/chart1.xml' => $chart,
        ]);
        $read = OfficeReader::pptx($this->file($deck));

        $this->assertSame("Results\n[Chart “Sales” (bar): Series “2025”: Q1=10, Q2=12]", $read->pages[0]);
        $this->assertSame(1, $read->facts['charts']);
    }

    // ---------- Excel ----------

    public function test_every_sheet_is_read_with_row_numbers_column_letters_formulas_dates_and_hidden_sheets(): void
    {
        $book = $this->xlsxBytes([
            'Budget' => [
                ['Item', 'Jan', 'Total'],
                ['Rent', 500, ['formula' => 'SUM(B2:B2)', 'value' => 500]],
                ['Paid on', ['date' => 45930], ['date' => 45930.5]],
                ['Done', true, 0.1 + 0.2],
                [null, null, 'A | B'],
            ],
            'Summary' => [['Total spend', ['formula' => 'SUM(Budget!C2:C2)', 'value' => 500]]],
            'Scratch' => [['x']],
        ], hidden: ['Scratch'], core: ['title' => 'Term budget', 'creator' => 'Ada', 'created' => '2026-09-20T10:12:00Z']);
        $read = OfficeReader::xlsx($this->file($book));

        $this->assertSame(['sheet', 3], [$read->unit, count($read->pages)]);
        $this->assertSame(['Sheet “Budget”, rows 1–5 (used range A1:C5)', 'Sheet “Summary”, row 1 (used range A1:B1)', 'Sheet “Scratch”, row 1 (used range A1:A1)'], $read->captions);
        $this->assertSame(
            "Row | A | B | C\n1 | Item | Jan | Total\n2 | Rent | 500 | 500 [=SUM(B2:B2)]\n3 | Paid on | 2025-09-30 | 2025-09-30 12:00\n4 | Done | TRUE | 0.3\n5 |  |  | A / B",
            $read->pages[0],
        );
        $this->assertSame("Row | A | B\n1 | Total spend | 500 [=SUM(Budget!C2:C2)]", $read->pages[1]);
        $this->assertSame("(Hidden sheet.)\nRow | A\n1 | x", $read->pages[2]);
        $this->assertSame('Budget (A1:C5), Summary (A1:B1), Scratch (A1:A1) [hidden]', $read->facts['sheets']);
        $this->assertSame([2, 'Term budget', 'Ada', '2026-09-20 10:12'], [$read->facts['formulas'], $read->facts['title'], $read->facts['author'], $read->facts['created']]);
    }

    public function test_a_long_sheet_is_read_in_chunks_that_say_which_rows_they_hold_and_stops_at_a_limit(): void
    {
        $rows = [['Name', 'Mark']];
        for ($i = 1; $i <= OfficeReader::SHEET_ROWS + 50; $i++) {
            $rows[] = ["Student {$i}", $i % 100];
        }
        $read = OfficeReader::xlsx($this->file($this->xlsxBytes(['Marks' => $rows])));

        $this->assertSame('part', $read->unit);
        $this->assertGreaterThan(5, count($read->pages));
        $this->assertStringStartsWith('Sheet “Marks”, rows 1–', $read->captions[0]);
        $this->assertStringEndsWith('(used range A1:B'.(count($rows)).')', $read->captions[0]);
        $this->assertStringContainsString('The sheet goes on: only its first 1,500 rows', $read->pages[count($read->pages) - 1]);
        $this->assertStringNotContainsString('Student '.(OfficeReader::SHEET_ROWS + 10), implode("\n", $read->pages));
    }

    public function test_hidden_rows_and_columns_are_left_out_however_the_file_says_hidden(): void
    {
        $sheet = '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><cols><col min="2" max="2" hidden="true"/></cols><sheetData>'
            .'<row r="1"><c r="A1" t="inlineStr"><is><t>Shown</t></is></c><c r="B1" t="inlineStr"><is><t>Hidden column</t></is></c></row>'
            .'<row r="2" hidden="1"><c r="A2" t="inlineStr"><is><t>Hidden by Excel</t></is></c></row>'
            .'<row r="3" hidden="true"><c r="A3" t="inlineStr"><is><t>Hidden by LibreOffice</t></is></c></row>'
            .'<row r="4" hidden="false"><c r="A4" t="inlineStr"><is><t>Also shown</t></is></c></row></sheetData></worksheet>';
        $read = OfficeReader::xlsx($this->file($this->xlsxBytes(['Data' => []], extra: ['xl/worksheets/sheet1.xml' => $sheet])));

        $this->assertSame("Row | A\n1 | Shown\n4 | Also shown", $read->pages[0]);
    }

    public function test_a_file_that_is_not_a_workbook_is_refused(): void
    {
        $this->expectException(\RuntimeException::class);
        OfficeReader::xlsx($this->file($this->docxBytes($this->wPara('Not a workbook'))));
    }
}
