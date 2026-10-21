<?php

namespace Tests\Feature\Mcp;

use App\Console\Commands\RecordAcceptance;
use App\Identity\AdmissionGate;
use App\Platform\Access\Principal;
use App\Platform\Access\Role;
use App\Study\Files;
use App\Study\Modules;
use App\Study\Workspaces;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAccounts;
use Tests\Concerns\MakesStudyFiles;
use Tests\Concerns\RefreshesDatabase;
use Tests\TestCase;

/** Each admission check can fail on its own. The gate stays closed while the policy is a draft. */
class SecondLearnerGateTest extends TestCase
{
    use CreatesAccounts, MakesStudyFiles, RefreshesDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_the_gate_reports_each_real_check_and_stays_closed_while_the_policy_is_a_draft(): void
    {
        $this->artisan('vistud:gate:second-learner')
            ->expectsOutputToContain('master key loads and a learner key wraps: yes')
            ->expectsOutputToContain('key database is separate: yes')
            ->expectsOutputToContain('learner text is encrypted: yes')
            ->expectsOutputToContain('files are encrypted: yes')
            ->expectsOutputToContain('student uses own AI key only: yes')
            ->expectsOutputToContain('acceptance run matches this commit: no')
            ->expectsOutputToContain('privacy policy is reviewed: no')
            ->expectsOutputToContain('result: closed')
            ->assertFailed();
    }

    public function test_the_gate_opens_only_when_every_check_passes(): void
    {
        $this->reviewedPolicy();
        $this->passingAcceptance();

        $this->artisan('vistud:gate:second-learner')
            ->expectsOutputToContain('result: open')
            ->assertSuccessful();
    }

    public function test_a_missing_master_key_fails_its_check(): void
    {
        $saved = $this->hideMasterKey();
        try {
            $this->assertFalse(app(AdmissionGate::class)->masterKeyWraps());
        } finally {
            $this->restoreMasterKey($saved);
        }
    }

    public function test_a_key_database_named_like_the_content_database_fails_its_check(): void
    {
        $keys = config('database.connections.mysql_keys.database');
        config(['database.connections.mysql_keys.database' => config('database.connections.mysql.database')]);
        try {
            $this->assertFalse(app(AdmissionGate::class)->keyDatabaseSeparate());
        } finally {
            config(['database.connections.mysql_keys.database' => $keys]);
        }
    }

    public function test_a_row_with_an_old_content_version_fails_its_check(): void
    {
        $by = $this->principal($this->student());
        app(Workspaces::class)->create($by, ['name' => 'Gate']);
        DB::table('workspaces')->update(['content_version' => 0]);

        $this->assertFalse(app(AdmissionGate::class)->textEncrypted());
    }

    public function test_a_plain_file_fails_its_check(): void
    {
        $by = $this->principal($this->student());
        $course = app(Workspaces::class)->create($by, ['name' => 'Gate']);
        $module = app(Modules::class)->create($by, $course->id, ['title' => 'Week']);
        $file = app(Files::class)->upload($by, 'module', $module->id, $this->temp("Plain notes.\n"), 'Notes.txt');
        $this->assertTrue(app(AdmissionGate::class)->filesEncrypted(), 'a file stored by the app is encrypted');

        $path = 'learners/'.$by->learnerId.'/files/'.$file->id;
        $sealed = Storage::disk('local')->get($path);
        Storage::disk('local')->put($path, 'plain notes');
        $this->assertFalse(app(AdmissionGate::class)->filesEncrypted(), 'a plain file');

        Storage::disk('local')->put($path, $sealed);
        $this->assertTrue(app(AdmissionGate::class)->filesEncrypted());
        Storage::disk('local')->put($path.'.writing-'.str_repeat('a', 32), 'half written');
        $this->assertFalse(app(AdmissionGate::class)->filesEncrypted(), 'a half-written stage is left behind');
    }

    public function test_an_admin_principal_fails_the_student_key_check(): void
    {
        config(['vistud.engine.key' => 'sk-or-install-'.bin2hex(random_bytes(8))]);
        $admin = $this->principal($this->admin());
        $asStudent = new Principal($admin->userId, $admin->learnerId, [Role::Student], true, null, 'console');

        $this->assertFalse(app(AdmissionGate::class)->ownKeyOnly($asStudent));
        $this->assertTrue(app(AdmissionGate::class)->ownKeyOnly());
    }

    public function test_a_missing_wrong_or_failed_acceptance_record_fails_its_check(): void
    {
        $path = sys_get_temp_dir().'/vistud-gate-'.bin2hex(random_bytes(4)).'.json';
        config(['vistud.gate.acceptance' => $path]);
        $gate = app(AdmissionGate::class);
        $this->assertFalse($gate->acceptanceMatches());

        file_put_contents($path, json_encode(['commit' => str_repeat('a', 40), 'passed' => true]));
        $this->assertFalse($gate->acceptanceMatches());

        file_put_contents($path, json_encode(['commit' => $gate->head(), 'passed' => false]));
        $this->assertFalse($gate->acceptanceMatches());

        $this->passingAcceptance($path);
        $this->assertTrue(app(AdmissionGate::class)->acceptanceMatches());
    }

    public function test_a_draft_or_unreviewed_policy_fails_its_check(): void
    {
        $path = sys_get_temp_dir().'/vistud-privacy-'.bin2hex(random_bytes(4)).'.md';
        config(['vistud.privacy.path' => $path]);
        file_put_contents($path, "DRAFT: needs a human legal review.\n\nReviewed by: Ada Lovelace, 2026-10-21\n");
        $this->assertFalse(app(AdmissionGate::class)->policyReviewed());

        file_put_contents($path, "ViStud keeps your notes.\n");
        $this->assertFalse(app(AdmissionGate::class)->policyReviewed());

        file_put_contents($path, "ViStud keeps your notes.\n\nReviewed by: Ada Lovelace, 2026-10-21\n");
        $this->assertTrue(app(AdmissionGate::class)->policyReviewed());
    }

    public function test_recording_acceptance_always_targets_the_test_databases(): void
    {
        config(['database.connections.mysql.database' => 'vistud']);

        $env = app(RecordAcceptance::class)->childEnvironment();

        $this->assertSame('vistud_test', $env['DB_DATABASE']);
        $this->assertSame('vistud_keys_test', $env['DB_KEYS_DATABASE']);
        $this->assertSame('', $env['DB_URL']);
    }

    private function reviewedPolicy(): void
    {
        $path = sys_get_temp_dir().'/vistud-privacy-'.bin2hex(random_bytes(4)).'.md';
        file_put_contents($path, "ViStud keeps your notes.\n\nReviewed by: Ada Lovelace, 2026-10-21\n");
        config(['vistud.privacy.path' => $path]);
    }

    private function passingAcceptance(?string $path = null): void
    {
        $path ??= sys_get_temp_dir().'/vistud-gate-'.bin2hex(random_bytes(4)).'.json';
        $head = app(AdmissionGate::class)->head();
        file_put_contents($path, json_encode(['commit' => $head, 'passed' => true]));
        config(['vistud.gate.acceptance' => $path]);
    }

    /** @return array{env: string, server: string, getenv: string} */
    private function hideMasterKey(): array
    {
        $saved = [
            'env' => (string) ($_ENV['VISTUD_MASTER_KEY'] ?? ''),
            'server' => (string) ($_SERVER['VISTUD_MASTER_KEY'] ?? ''),
            'getenv' => (string) getenv('VISTUD_MASTER_KEY'),
        ];
        $_ENV['VISTUD_MASTER_KEY'] = '';
        $_SERVER['VISTUD_MASTER_KEY'] = '';
        putenv('VISTUD_MASTER_KEY=');

        return $saved;
    }

    /** @param array{env: string, server: string, getenv: string} $saved */
    private function restoreMasterKey(array $saved): void
    {
        $_ENV['VISTUD_MASTER_KEY'] = $saved['env'];
        $_SERVER['VISTUD_MASTER_KEY'] = $saved['server'];
        putenv('VISTUD_MASTER_KEY='.$saved['getenv']);
    }
}
