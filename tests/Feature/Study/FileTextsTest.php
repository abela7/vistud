<?php

namespace Tests\Feature\Study;

use App\Jobs\ExtractFileText;
use App\Platform\Access\Principal;
use App\Study\FileDetails;
use App\Study\Files;
use App\Study\FileText;
use App\Study\FileTexts;
use App\Study\Modules;
use App\Study\WorkspaceDetails;
use App\Study\Workspaces;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\MakesOfficeFiles;
use Tests\Concerns\MakesStudyFiles;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** What a file says, page by page, for the tutor (docs/specs/study-memory.md §6). */
class FileTextsTest extends TestCase
{
    use CreatesAccounts, MakesOfficeFiles, MakesStudyFiles, RefreshesDatabase;

    private Principal $by;

    private WorkspaceDetails $biology;

    private string $week2;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['vistud.files.office' => 'none']);
        $this->by = $this->principal($this->student());
        $this->biology = app(Workspaces::class)->create($this->by, ['name' => 'Biology']);
        $this->week2 = app(Modules::class)->create($this->by, $this->biology->id, ['title' => 'Week 2'])->id;
    }

    private function upload(string $name, string $bytes): FileDetails
    {
        return app(Files::class)->upload($this->by, 'module', $this->week2, $this->temp($bytes), $name);
    }

    private function read(FileDetails $file): FileText
    {
        return app(FileTexts::class)->of($this->by, $file->id);
    }

    public function test_a_pdf_is_read_page_by_page_and_kept_beside_the_file_until_it_is_deleted(): void
    {
        $file = $this->upload('Lecture 2.pdf', (string) file_get_contents(base_path('tests/Browser/fixtures/files/Lecture 2 - cell division.pdf')));

        $text = $this->read($file);
        $this->assertSame([FileText::READY, 1, 'Page 1 of 1', '1 page'], [$text->state, $text->count(), $text->label(1), $text->size()]);
        $this->assertStringContainsString('Mitosis makes two identical cells.', $text->pages[0]);
        $kept = 'learners/'.$this->by->learnerId."/texts/{$file->id}.json";
        Storage::disk('local')->assertExists($kept);

        // Read again from what was kept, then gone with the file.
        $this->assertSame($text->pages, $this->read($file)->pages);
        app(Files::class)->trash($this->by, $file->id);
        app(Files::class)->destroy($this->by, $file->id);
        Storage::disk('local')->assertMissing($kept);
    }

    public function test_a_big_pdf_is_read_on_the_queue_and_says_preparing_until_then(): void
    {
        config(['vistud.files.inline_pdf_bytes' => 10]);
        Queue::fake();
        $file = $this->upload('Lecture 2.pdf', (string) file_get_contents(base_path('tests/Browser/fixtures/files/Lecture 2 - cell division.pdf')));

        $this->assertSame(FileText::PREPARING, $this->read($file)->state);
        Queue::assertPushed(ExtractFileText::class, fn (ExtractFileText $job) => $job->fileId === $file->id);

        // The worker reads it; from then on the text is there at once.
        [, $key] = app(Files::class)->content($this->by, $file->id);
        (new ExtractFileText($file->id, $key, $this->by->learnerId))->handle();
        $text = $this->read($file);
        $this->assertSame(FileText::READY, $text->state);
        $this->assertStringContainsString('Mitosis makes two identical cells.', $text->pages[0]);
    }

    public function test_a_big_pdf_that_cannot_be_read_says_so_instead_of_preparing_for_ever(): void
    {
        config(['vistud.files.inline_pdf_bytes' => 10]);
        Queue::fake();
        $file = $this->upload('Broken.pdf', (string) file_get_contents(base_path('tests/Browser/fixtures/files/Lecture 2 - cell division.pdf')));
        [, $key] = app(Files::class)->content($this->by, $file->id);
        // The bytes on disk are damaged after the upload was checked.
        Storage::disk('local')->put($key, "%PDF-1.4\nnot really a pdf\n%%EOF");

        (new ExtractFileText($file->id, $key, $this->by->learnerId))->handle();

        $this->assertSame(FileText::NONE, $this->read($file)->state);
    }

    public function test_slides_come_with_their_speakers_notes_and_word_and_text_files_in_parts(): void
    {
        $slide = fn (string ...$lines) => '<p:sld><p:txBody>'.implode('', array_map(fn ($line) => "<a:p><a:r><a:t>{$line}</a:t></a:r></a:p>", $lines)).'</p:txBody></p:sld>';
        $deck = $this->ooxml('ppt/presentation.xml', [
            'ppt/slides/slide1.xml' => $slide('Cell division', 'Why cells divide'),
            'ppt/slides/slide2.xml' => $slide('Mitosis', 'Two identical cells &amp; one nucleus each'),
            'ppt/slides/slide10.xml' => $slide('Summary'),
            'ppt/slides/_rels/slide2.xml.rels' => '<Relationships><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/notesSlide" Target="../notesSlides/notesSlide1.xml"/></Relationships>',
            'ppt/notesSlides/notesSlide1.xml' => $slide('Stress the word identical.', '2'),
        ]);
        $slides = $this->read($this->upload('Week 2 slides.pptx', $deck));
        $this->assertSame([FileText::READY, 'slide', 3, 'Slide 2 of 3'], [$slides->state, $slides->unit, $slides->count(), $slides->label(2)]);
        $this->assertSame("Mitosis\nTwo identical cells & one nucleus each\n\nSpeaker's notes: Stress the word identical.", $slides->pages[1]);
        $this->assertSame('Summary', $slides->pages[2]);

        $paragraph = fn (string $text) => '<w:p><w:r><w:t xml:space="preserve">'.$text.'</w:t></w:r></w:p>';
        $long = str_repeat('Meiosis halves the chromosomes. ', 60);
        $essay = $this->ooxml('word/document.xml', ['word/document.xml' => '<w:document><w:body>'.$paragraph('Why cells divide').$paragraph($long).$paragraph($long).$paragraph('The end.').'</w:body></w:document>']);
        $word = $this->read($this->upload('Essay.docx', $essay));
        $this->assertSame([FileText::READY, 'part'], [$word->state, $word->unit]);
        $this->assertGreaterThan(1, $word->count());
        $this->assertStringStartsWith('Why cells divide', $word->pages[0]);
        $this->assertStringEndsWith('The end.', $word->pages[$word->count() - 1]);

        $notes = $this->read($this->upload('notes.txt', "Line one\r\n\r\n\r\n\r\nLine   two"));
        $this->assertSame([FileText::READY, ["Line one\n\nLine two"]], [$notes->state, $notes->pages]);
    }

    public function test_an_excel_workbook_is_read_by_sheet_without_libreoffice_and_says_what_it_is_and_about_itself(): void
    {
        $book = $this->xlsxBytes([
            'Budget' => [['Item', 'Cost'], ['Rent', ['formula' => 'SUM(B2:B2)', 'value' => 500]]],
            'Notes' => [['Remember the deposit']],
        ], core: ['title' => 'Term budget', 'creator' => 'Ada Lovelace', 'lastModifiedBy' => 'Alan', 'modified' => '2026-10-01T16:40:00Z']);
        $file = $this->upload('Budget.xlsx', $book);

        $text = $this->read($file);
        $this->assertSame([FileText::READY, 'sheet', 2, 'Sheet 2 of 2'], [$text->state, $text->unit, $text->count(), $text->label(2)]);
        $this->assertSame('Sheet “Budget”, rows 1–2 (used range A1:B2)', $text->caption(1));
        $this->assertStringContainsString('2 | Rent | 500 [=SUM(B2:B2)]', $text->pages[0]);

        $profile = $text->profile();
        $this->assertStringStartsWith('File “Budget.xlsx”: Excel, ', $profile);
        $this->assertMatchesRegularExpression('/; uploaded \d{4}-\d{2}-\d{2}\./', $profile);
        foreach (['2 sheets', 'title “Term budget”', 'author Ada Lovelace', 'last saved by Alan', 'last saved 2026-10-01 16:40', 'made with Microsoft Excel', '1 formula', 'sheets: Budget (A1:B2), Notes (A1:A1)'] as $expected) {
            $this->assertStringContainsString($expected, $profile);
        }

        // Kept beside the file, with what it said about itself, and read back the same.
        $again = $this->read($file);
        $this->assertSame([$text->pages, $text->captions, $text->facts], [$again->pages, $again->captions, $again->facts]);
    }

    public function test_an_old_excel_file_is_read_through_its_modern_copy_which_goes_with_the_file(): void
    {
        $file = $this->upload('Marks.xls', $this->ole());
        // What LibreOffice makes beside the preview (App\Study\FilePreviews::make), there already.
        $copy = 'learners/'.$this->by->learnerId."/previews/{$file->id}.xlsx";
        Storage::disk('local')->put($copy, $this->xlsxBytes(['Marks' => [['Name', 'Mark'], ['Ada', 91]]], core: ['creator' => 'Ada']));

        $text = $this->read($file);
        $this->assertSame([FileText::READY, 'sheet', 'Sheet “Marks”, rows 1–2 (used range A1:B2)'], [$text->state, $text->unit, $text->caption(1)]);
        $this->assertSame("Row | A | B\n1 | Name | Mark\n2 | Ada | 91", $text->pages[0]);
        // The student's file is an Excel file, whoever made the copy.
        $this->assertStringStartsWith('File “Marks.xls”: Excel, ', $text->profile());
        $this->assertStringContainsString('author Ada', $text->profile());
        $this->assertArrayNotHasKey('application', $text->facts);

        app(Files::class)->trash($this->by, $file->id);
        app(Files::class)->destroy($this->by, $file->id);
        Storage::disk('local')->assertMissing($copy);
    }

    public function test_a_word_document_says_its_pages_and_a_pdf_its_title_and_author(): void
    {
        $essay = $this->docxBytes($this->wPara('Why cells divide', 'Heading1').$this->wPara('Cells divide to grow and repair.'), ['creator' => 'Ada'], pages: 2);
        $word = $this->read($this->upload('Essay.docx', $essay));
        $this->assertSame('part', $word->unit);
        $this->assertStringContainsString('2 pages, read in 1 part', $word->profile());
        $this->assertStringContainsString('9 words', $word->profile());
        $this->assertStringContainsString('1 heading', $word->profile());
        $this->assertStringNotContainsString('1 headings', $word->profile());

        $pdf = $this->read($this->upload('Lecture 2.pdf', (string) file_get_contents(base_path('tests/Browser/fixtures/files/Lecture 2 - cell division.pdf'))));
        $this->assertStringStartsWith('File “Lecture 2.pdf”: PDF, ', $pdf->profile());
        $this->assertStringContainsString('1 page', $pdf->profile());
    }

    public function test_a_picture_is_seen_not_read_and_a_kind_without_a_reader_says_so(): void
    {
        $this->assertSame(FileText::PICTURE, $this->read($this->upload('Onion cells.png', $this->image()))->state);
        // An old Excel file (.xls) needs LibreOffice's PDF, and there is none here.
        $this->assertSame(FileText::NONE, $this->read($this->upload('Marks.xls', $this->ole()))->state);
        // A PDF with no pages to read.
        $empty = $this->read($this->upload('Scan.pdf', $this->pdf()));
        $this->assertFalse($empty->state === FileText::READY && $empty->hasWords());
    }
}
