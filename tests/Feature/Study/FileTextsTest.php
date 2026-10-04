<?php

namespace Tests\Feature\Study;

use App\Platform\Access\Principal;
use App\Study\FileDetails;
use App\Study\Files;
use App\Study\FileText;
use App\Study\FileTexts;
use App\Study\Modules;
use App\Study\WorkspaceDetails;
use App\Study\Workspaces;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\MakesStudyFiles;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** What a file says, page by page, for the tutor (docs/specs/study-memory.md §6). */
class FileTextsTest extends TestCase
{
    use CreatesAccounts, MakesStudyFiles, RefreshesDatabase;

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

    public function test_a_picture_is_seen_not_read_and_a_kind_without_a_reader_says_so(): void
    {
        $this->assertSame(FileText::PICTURE, $this->read($this->upload('Onion cells.png', $this->image()))->state);
        // An Excel file needs LibreOffice's PDF, and there is none here.
        $this->assertSame(FileText::NONE, $this->read($this->upload('Marks.xlsx', $this->ooxml('xl/workbook.xml')))->state);
        // A PDF with no pages to read.
        $empty = $this->read($this->upload('Scan.pdf', $this->pdf()));
        $this->assertFalse($empty->state === FileText::READY && $empty->hasWords());
    }
}
