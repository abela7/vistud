<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Plugins\PluginBuilder;
use App\Mcp\Servers\ViStudServer;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use ZipArchive;

/** The install packages are built from one source and carry no secret (docs/specs/mcp-connections-plan.md M6.4). */
class PluginBuildTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['mcp.public_url' => 'https://vistud.example']);
    }

    public function test_every_skill_has_a_name_that_matches_its_folder_and_a_description_within_the_limit(): void
    {
        $builder = new PluginBuilder;
        $this->assertSame(['quiz-me', 'student-record', 'study-session'], array_keys($builder->skills()));
        foreach ($builder->skills() as $name => $skill) {
            $this->assertFileExists(resource_path("skills/{$name}/SKILL.md"));
            $this->assertLessThanOrEqual(1024, mb_strlen($skill['description']), $name);
            $this->assertGreaterThan(40, mb_strlen($skill['description']));
        }
    }

    public function test_the_claude_package_has_its_manifest_server_address_skills_and_readme(): void
    {
        $files = (new PluginBuilder)->files('claude');

        $this->assertSame('vistud', json_decode($files['.claude-plugin/plugin.json'], true)['name']);
        $this->assertSame(ViStudServer::CONTRACT, json_decode($files['.claude-plugin/plugin.json'], true)['version']);
        $this->assertSame('https://vistud.example/mcp', json_decode($files['.mcp.json'], true)['mcpServers']['vistud']['url']);
        $this->assertGreaterThanOrEqual(40, str_word_count($files['README.md']));
        $this->assertArrayHasKey('LICENSE', $files);
        $this->assertArrayHasKey('skills/study-session/SKILL.md', $files);
    }

    public function test_every_package_has_the_same_skills_from_the_one_source(): void
    {
        $builder = new PluginBuilder;
        $claude = array_keys(array_filter($builder->files('claude'), fn ($v, $k) => str_starts_with($k, 'skills/'), ARRAY_FILTER_USE_BOTH));
        $openai = array_keys(array_filter($builder->files('openai'), fn ($v, $k) => str_starts_with($k, 'skills/'), ARRAY_FILTER_USE_BOTH));

        $this->assertSame($claude, $openai);
        $this->assertCount(3, $claude);
        $this->assertStringContainsString('study-session', $builder->files('gemini')['SKILL.md']);
        $this->assertStringContainsString('untested', strtolower($builder->files('gemini')['SKILL.md']));
    }

    public function test_the_openai_package_streams_over_oauth_and_never_says_claude(): void
    {
        $files = (new PluginBuilder)->files('openai');
        $server = json_decode($files['mcp.json'], true)['servers']['vistud'];

        $this->assertSame(['streamable-http', 'oauth', 'https://vistud.example/mcp'], [$server['type'], $server['auth'], $server['url']]);
        foreach ($files as $name => $content) {
            $this->assertStringNotContainsString('Claude', $content, $name);
        }
    }

    public function test_no_package_holds_a_key_or_a_token(): void
    {
        foreach (PluginBuilder::TARGETS as $target) {
            foreach ((new PluginBuilder)->files($target) as $name => $content) {
                $this->assertDoesNotMatchRegularExpression('/(sk-[A-Za-z0-9]{10,}|Bearer\s+\S{10,}|client_secret|api[_-]?key\s*[:=])/i', $content, "{$target}/{$name}");
            }
        }
    }

    public function test_the_command_writes_folders_and_zips_with_the_right_names(): void
    {
        $out = storage_path('framework/testing/plugins-'.uniqid());
        $this->artisan('vistud:mcp:plugins', ['--out' => str_replace(base_path().DIRECTORY_SEPARATOR, '', $out)])->assertSuccessful();

        foreach (PluginBuilder::TARGETS as $target) {
            $this->assertDirectoryExists("{$out}/{$target}/vistud");
            $zip = new ZipArchive;
            $this->assertTrue($zip->open("{$out}/{$target}/vistud-{$target}.zip") === true);
            $names = array_map(fn (int $i) => $zip->getNameIndex($i), range(0, $zip->numFiles - 1));
            $this->assertNotEmpty(array_filter($names, fn (string $n) => str_starts_with($n, 'vistud/')));
            $zip->close();
        }
        $this->assertFileExists("{$out}/claude/vistud/.claude-plugin/plugin.json");
        File::deleteDirectory($out);
    }
}
