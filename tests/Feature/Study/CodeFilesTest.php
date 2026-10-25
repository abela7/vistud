<?php

namespace Tests\Feature\Study;

use App\Platform\Errors\Unprocessable;
use App\Study\Files;
use App\Study\Workspaces;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\MakesStudyFiles;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/**
 * Code and config files upload as text (the owner's ask, 2026-10-25: a lab's JavaApp1.java was refused), and are only
 * ever served as plain text: a script shows its source, never runs. A zip, a web page, or a program renamed to .java,
 * is not taken.
 */
class CodeFilesTest extends TestCase
{
    use CreatesAccounts, MakesStudyFiles, RefreshesDatabase;

    public function test_code_uploads_as_text_and_is_served_as_plain_text_and_a_zip_or_a_binary_is_refused(): void
    {
        $user = $this->student();
        $by = $this->principal($user);
        $course = app(Workspaces::class)->create($by, ['name' => 'Database Systems'])->id;
        $files = app(Files::class);

        $java = $files->upload($by, 'workspace', $course, $this->temp("public class JavaApp1 {\n    public static void main(String[] args) {}\n}\n"), 'JavaApp1.java');
        $this->assertSame(['text', 'Java'], [$java->kind, $java->typeLabel()]);
        $this->actingAs($user)->get(route('files.content', $java->id))->assertOk()->assertHeader('Content-Type', 'text/plain; charset=utf-8');
        $this->get(route('workspaces.files.show', [$course, $java->id]))->assertOk()->assertSee('public class JavaApp1');

        // A script is shown as its source: plain text, in a sandbox, never run (a web page itself is still refused).
        $page = $files->upload($by, 'workspace', $course, $this->temp("document.body.innerHTML = '<b>hi</b>';\n"), 'app.js');
        $served = $this->get(route('files.content', $page->id))->assertOk()->assertHeader('Content-Type', 'text/plain; charset=utf-8');
        $this->assertStringContainsString('sandbox', (string) $served->headers->get('Content-Security-Policy'));
        $this->get(route('files.content', [$page->id, 'download' => 1]))->assertOk()->assertHeader('Content-Type', 'text/plain; charset=utf-8');

        foreach (['Lab_07.zip' => "PK\x03\x04zip", 'Sneaky.java' => "MZ\x00\x90\x00binary", 'page.html' => '<script>alert(1)</script>'] as $name => $bytes) {
            try {
                $files->upload($by, 'workspace', $course, $this->temp($bytes), $name);
                $this->fail("{$name} was taken.");
            } catch (Unprocessable $e) {
                $this->assertArrayHasKey('file', $e->details['fields']);
            }
        }
    }
}
