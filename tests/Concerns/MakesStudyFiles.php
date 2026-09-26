<?php

namespace Tests\Concerns;

use ZipArchive;

/** Small but real files of each kind students upload, for the file tests. */
trait MakesStudyFiles
{
    protected function pdf(string $extra = ''): string
    {
        return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R {$extra} >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [] /Count 0 >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
    }

    /** A Word, PowerPoint or Excel file with its main part, and any extra entries. */
    protected function ooxml(string $mainPart, array $extra = []): string
    {
        return $this->zip(['[Content_Types].xml' => '<Types/>', $mainPart => '<main/>', ...$extra]);
    }

    /** An OpenDocument file: `mimetype` first and uncompressed, as the format requires. */
    protected function odf(string $mime, array $extra = []): string
    {
        return $this->zip(['mimetype' => $mime, 'content.xml' => '<content/>', ...$extra], stored: 'mimetype');
    }

    /** An older Office file (a compound file), with or without its macro storage. */
    protected function ole(bool $macros = false): string
    {
        $name = fn (string $n) => implode("\0", str_split($n))."\0";

        return "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1".str_repeat("\0", 504).$name('PowerPoint Document').($macros ? $name('_VBA_PROJECT') : '');
    }

    protected function image(string $type = 'png'): string
    {
        $image = imagecreatetruecolor(4, 3);
        ob_start();
        $type === 'png' ? imagepng($image) : imagejpeg($image);

        return (string) ob_get_clean();
    }

    /** A temporary file holding $bytes, removed when the test ends. */
    protected function temp(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'vistud-test-');
        file_put_contents($path, $bytes);
        $this->beforeApplicationDestroyed(fn () => @unlink($path));

        return $path;
    }

    private function zip(array $entries, ?string $stored = null): string
    {
        $path = tempnam(sys_get_temp_dir(), 'vistud-zip-');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
            if ($name === $stored) {
                $zip->setCompressionName($name, ZipArchive::CM_STORE);
            }
        }
        $zip->close();
        $bytes = (string) file_get_contents($path);
        unlink($path);

        return $bytes;
    }
}
