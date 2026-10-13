<?php

namespace Tests\Concerns;

use ZipArchive;

/**
 * Word, PowerPoint and Excel files with real content, small but valid (LibreOffice opens them too): what
 * App\Study\OfficeReader reads. Each returns the file's bytes.
 */
trait MakesOfficeFiles
{
    private const W = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:c="http://schemas.openxmlformats.org/drawingml/2006/chart"';

    private const P = 'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:c="http://schemas.openxmlformats.org/drawingml/2006/chart"';

    private const REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    private const TYPES = 'xmlns="http://schemas.openxmlformats.org/package/2006/content-types"';

    private const DEFAULTS = '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>';

    /** A paragraph of Word, with a style ("Heading1") and a list level if it has them. */
    protected function wPara(string $text, ?string $style = null, ?int $list = null, string $extra = ''): string
    {
        $props = ($style !== null ? '<w:pStyle w:val="'.$style.'"/>' : '').($list !== null ? '<w:numPr><w:ilvl w:val="'.$list.'"/><w:numId w:val="1"/></w:numPr>' : '');

        return '<w:p>'.($props !== '' ? "<w:pPr>{$props}</w:pPr>" : '').$extra.'<w:r><w:t xml:space="preserve">'.htmlspecialchars($text, ENT_XML1).'</w:t></w:r></w:p>';
    }

    /** A table of Word, a row of cells each. */
    protected function wTable(array $rows): string
    {
        $out = '<w:tbl>';
        foreach ($rows as $row) {
            $out .= '<w:tr>'.implode('', array_map(fn ($cell) => '<w:tc>'.$this->wPara((string) $cell).'</w:tc>', $row)).'</w:tr>';
        }

        return $out.'</w:tbl>';
    }

    /**
     * @param  string  $body  the paragraphs and tables, from wPara() and wTable()
     * @param  array<string, string>  $core  document properties: title, creator, lastModifiedBy, created, modified
     */
    protected function docxBytes(string $body, array $core = [], ?int $pages = null, array $extra = []): string
    {
        return $this->package([
            '[Content_Types].xml' => '<Types '.self::TYPES.'>'.self::DEFAULTS.'<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>',
            '_rels/.rels' => '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="'.self::REL.'/officeDocument" Target="word/document.xml"/></Relationships>',
            'word/document.xml' => '<w:document '.self::W.'><w:body>'.$body.'</w:body></w:document>',
            ...$this->properties($core, $pages === null ? '' : "<Pages>{$pages}</Pages>", 'Microsoft Office Word'),
            ...$extra,
        ]);
    }

    /** A slide's shapes: a title, then paragraphs of text. */
    protected function slideTitle(string $text): string
    {
        return '<p:sp><p:nvSpPr><p:cNvPr id="2" name="Title"/><p:cNvSpPr/><p:nvPr><p:ph type="title"/></p:nvPr></p:nvSpPr><p:txBody><a:p><a:r><a:t>'.htmlspecialchars($text, ENT_XML1).'</a:t></a:r></a:p></p:txBody></p:sp>';
    }

    /** @param  list<array{0: string, 1?: int}>  $paragraphs  text and its list level */
    protected function slideText(array $paragraphs, string $type = 'body'): string
    {
        $out = '';
        foreach ($paragraphs as $paragraph) {
            $out .= '<a:p>'.(($paragraph[1] ?? 0) > 0 ? '<a:pPr lvl="'.$paragraph[1].'"/>' : '').'<a:r><a:t>'.htmlspecialchars($paragraph[0], ENT_XML1).'</a:t></a:r></a:p>';
        }

        return '<p:sp><p:nvSpPr><p:cNvPr id="3" name="Content"/><p:cNvSpPr/><p:nvPr><p:ph type="'.$type.'"/></p:nvPr></p:nvSpPr><p:txBody>'.$out.'</p:txBody></p:sp>';
    }

    protected function slidePicture(string $description): string
    {
        return '<p:pic><p:nvPicPr><p:cNvPr id="4" name="Picture" descr="'.htmlspecialchars($description, ENT_XML1).'"/><p:cNvPicPr/><p:nvPr/></p:nvPicPr><p:blipFill/></p:pic>';
    }

    protected function slideTable(array $rows): string
    {
        $out = '';
        foreach ($rows as $row) {
            $out .= '<a:tr>'.implode('', array_map(fn ($cell) => '<a:tc><a:txBody><a:p><a:r><a:t>'.htmlspecialchars((string) $cell, ENT_XML1).'</a:t></a:r></a:p></a:txBody></a:tc>', $row)).'</a:tr>';
        }

        return '<p:graphicFrame><a:graphic><a:graphicData><a:tbl>'.$out.'</a:tbl></a:graphicData></a:graphic></p:graphicFrame>';
    }

    protected function slideChart(string $relationshipId): string
    {
        return '<p:graphicFrame><a:graphic><a:graphicData><c:chart r:id="'.$relationshipId.'"/></a:graphicData></a:graphic></p:graphicFrame>';
    }

    /**
     * A deck: each slide is the shapes in it. Slide parts are numbered 1, 2, 3 in the order given, and shown in
     * $order (the numbers, as a moved slide would be) when it is.
     *
     * @param  list<string>  $slides  the shapes of each slide
     * @param  list<int>|null  $order  the slides' numbers in the order they are shown
     * @param  array<int, string>  $notes  a slide's number → its speaker's notes
     * @param  list<int>  $hidden  numbers of the slides that are hidden
     */
    protected function pptxBytes(array $slides, ?array $order = null, array $notes = [], array $hidden = [], array $core = [], array $extra = []): string
    {
        $order ??= range(1, count($slides));
        $parts = [
            '[Content_Types].xml' => '<Types '.self::TYPES.'>'.self::DEFAULTS.'<Override PartName="/ppt/presentation.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml"/>'.implode('', array_map(fn ($n) => '<Override PartName="/ppt/slides/slide'.$n.'.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slide+xml"/>', range(1, count($slides)))).'</Types>',
            '_rels/.rels' => '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="'.self::REL.'/officeDocument" Target="ppt/presentation.xml"/></Relationships>',
            'ppt/presentation.xml' => '<p:presentation '.self::P.'><p:sldIdLst>'.implode('', array_map(fn ($n) => '<p:sldId id="'.(255 + $n).'" r:id="rId'.(10 + $n).'"/>', $order)).'</p:sldIdLst></p:presentation>',
            'ppt/_rels/presentation.xml.rels' => '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.implode('', array_map(fn ($n) => '<Relationship Id="rId'.(10 + $n).'" Type="'.self::REL.'/slide" Target="slides/slide'.$n.'.xml"/>', range(1, count($slides)))).'</Relationships>',
            ...$this->properties($core, '', 'Microsoft Office PowerPoint'),
        ];
        foreach ($slides as $i => $shapes) {
            $n = $i + 1;
            $parts["ppt/slides/slide{$n}.xml"] = '<p:sld '.self::P.(in_array($n, $hidden, true) ? ' show="0"' : '').'><p:cSld><p:spTree>'.$shapes.'</p:spTree></p:cSld></p:sld>';
            if (isset($notes[$n])) {
                $parts["ppt/slides/_rels/slide{$n}.xml.rels"] = '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId2" Type="'.self::REL.'/notesSlide" Target="../notesSlides/notesSlide'.$n.'.xml"/></Relationships>';
                $parts["ppt/notesSlides/notesSlide{$n}.xml"] = '<p:notes '.self::P.'><p:cSld><p:spTree>'
                    .'<p:sp><p:nvSpPr><p:cNvPr id="2" name="Slide Image"/><p:cNvSpPr/><p:nvPr><p:ph type="sldImg"/></p:nvPr></p:nvSpPr></p:sp>'
                    .$this->slideText([[$notes[$n]]]).'<p:sp><p:nvSpPr><p:cNvPr id="4" name="Number"/><p:cNvSpPr/><p:nvPr><p:ph type="sldNum"/></p:nvPr></p:nvSpPr><p:txBody><a:p><a:r><a:t>'.$n.'</a:t></a:r></a:p></p:txBody></p:sp>'
                    .'</p:spTree></p:cSld></p:notes>';
            }
        }

        return $this->package([...$parts, ...$extra]);
    }

    /**
     * A workbook. Each sheet is its rows; a cell is text, a number, true or false, ['formula' => 'SUM(B2:B3)', 'value' => 5],
     * or ['date' => 45000] (an Excel date serial); null is empty.
     *
     * @param  array<string, list<list<mixed>>>  $sheets  name → rows
     * @param  list<string>  $hidden  names of the sheets that are hidden
     */
    protected function xlsxBytes(array $sheets, array $hidden = [], array $core = [], array $extra = []): string
    {
        $strings = [];
        $string = function (string $text) use (&$strings): int {
            $index = array_search($text, $strings, true);

            return $index === false ? array_push($strings, $text) - 1 : $index;
        };
        $parts = [];
        $names = '';
        $rels = '';
        $n = 0;
        foreach ($sheets as $name => $rows) {
            $n++;
            $sheetData = '';
            foreach ($rows as $r => $row) {
                $cells = '';
                foreach ($row as $c => $value) {
                    if ($value === null) {
                        continue;
                    }
                    $ref = $this->letters($c).($r + 1);
                    $cells .= match (true) {
                        is_array($value) && isset($value['formula']) => '<c r="'.$ref.'"><f>'.htmlspecialchars($value['formula'], ENT_XML1).'</f><v>'.$value['value'].'</v></c>',
                        is_array($value) && isset($value['date']) => '<c r="'.$ref.'" s="1"><v>'.$value['date'].'</v></c>',
                        is_bool($value) => '<c r="'.$ref.'" t="b"><v>'.(int) $value.'</v></c>',
                        is_int($value) || is_float($value) => '<c r="'.$ref.'"><v>'.json_encode($value).'</v></c>',
                        default => '<c r="'.$ref.'" t="s"><v>'.$string((string) $value).'</v></c>',
                    };
                }
                $sheetData .= '<row r="'.($r + 1).'">'.$cells.'</row>';
            }
            $width = $rows === [] ? 1 : max(array_map('count', $rows));
            $parts["xl/worksheets/sheet{$n}.xml"] = '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><dimension ref="A1:'.$this->letters($width - 1).max(1, count($rows)).'"/><sheetData>'.$sheetData.'</sheetData></worksheet>';
            $names .= '<sheet name="'.htmlspecialchars($name, ENT_XML1).'" sheetId="'.$n.'" '.(in_array($name, $hidden, true) ? 'state="hidden" ' : '').'r:id="rId'.$n.'"/>';
            $rels .= '<Relationship Id="rId'.$n.'" Type="'.self::REL.'/worksheet" Target="worksheets/sheet'.$n.'.xml"/>';
        }
        $parts['xl/workbook.xml'] = '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="'.self::REL.'"><sheets>'.$names.'</sheets></workbook>';
        $parts['xl/_rels/workbook.xml.rels'] = '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$rels.'</Relationships>';
        $parts['xl/sharedStrings.xml'] = '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'.implode('', array_map(fn ($s) => '<si><t xml:space="preserve">'.htmlspecialchars($s, ENT_XML1).'</t></si>', $strings)).'</sst>';
        $parts['xl/styles.xml'] = '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><cellXfs count="2"><xf numFmtId="0"/><xf numFmtId="14" applyNumberFormat="1"/></cellXfs></styleSheet>';

        $main = 'application/vnd.openxmlformats-officedocument.spreadsheetml.';
        $types = '<Types '.self::TYPES.'>'.self::DEFAULTS.'<Override PartName="/xl/workbook.xml" ContentType="'.$main.'sheet.main+xml"/><Override PartName="/xl/sharedStrings.xml" ContentType="'.$main.'sharedStrings+xml"/><Override PartName="/xl/styles.xml" ContentType="'.$main.'styles+xml"/>'.implode('', array_map(fn ($i) => '<Override PartName="/xl/worksheets/sheet'.$i.'.xml" ContentType="'.$main.'worksheet+xml"/>', range(1, max(1, $n)))).'</Types>';

        return $this->package([
            '[Content_Types].xml' => $types,
            '_rels/.rels' => '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="'.self::REL.'/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            ...$parts,
            ...$this->properties($core, '', 'Microsoft Excel'),
            ...$extra,
        ]);
    }

    /** @return array<string, string> docProps/core.xml and app.xml */
    private function properties(array $core, string $app, string $application): array
    {
        $core = array_filter($core);

        return [
            'docProps/core.xml' => '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/">'
                .implode('', array_map(fn ($key, $value) => match ($key) {
                    'title', 'subject' => "<dc:{$key}>".htmlspecialchars($value, ENT_XML1)."</dc:{$key}>",
                    'creator' => '<dc:creator>'.htmlspecialchars($value, ENT_XML1).'</dc:creator>',
                    'lastModifiedBy' => '<cp:lastModifiedBy>'.htmlspecialchars($value, ENT_XML1).'</cp:lastModifiedBy>',
                    default => "<dcterms:{$key}>{$value}</dcterms:{$key}>",
                }, array_keys($core), $core)).'</cp:coreProperties>',
            'docProps/app.xml' => '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"><Application>'.$application.'</Application>'.$app.'</Properties>',
        ];
    }

    private function letters(int $index): string
    {
        $letters = '';
        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $letters = chr(65 + ($n - 1) % 26).$letters;
        }

        return $letters;
    }

    /** @param  array<string, string>  $entries */
    private function package(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'vistud-office-');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.$content);
        }
        $zip->close();
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }
}
