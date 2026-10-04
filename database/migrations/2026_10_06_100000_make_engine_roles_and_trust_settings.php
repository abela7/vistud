<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Three AI roles instead of two models (docs/specs/vistud-2-blueprint.md §3.6.1): the tutor teaches, the
 * reader reads files and writes the small records, the helper does quick jobs on every page. The old quick
 * model becomes the reader model with its value kept, in the students' settings and in the owner's defaults
 * (`platform_settings`), and the helper model is new. The three trust toggles of §3.6.6 are kept beside the
 * one that exists (`ask_topics`): they take effect as the phases that use them arrive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('engine_settings', function (Blueprint $table) {
            $table->renameColumn('quick_model', 'reader_model');
        });
        Schema::table('engine_settings', function (Blueprint $table) {
            $table->string('helper_model', 120)->nullable()->after('reader_model');
            // Files uploaded to a module are read at once; the tutor marks topics itself; the copy-paste path is shown.
            $table->boolean('auto_read_files')->default(true)->after('ask_topics');
            $table->boolean('tutor_marks_topics')->default(true)->after('auto_read_files');
            $table->boolean('copy_paste_ai')->default(false)->after('tutor_marks_topics');
        });

        $this->carryOwnerDefault();
    }

    /** The owner's default for the reader is the quick model they set; an installation that set both keeps the new one. */
    public function carryOwnerDefault(): void
    {
        if (DB::table('platform_settings')->where('key', 'engine.reader_model')->doesntExist()) {
            DB::table('platform_settings')->where('key', 'engine.quick_model')->update(['key' => 'engine.reader_model']);
        } else {
            DB::table('platform_settings')->where('key', 'engine.quick_model')->delete();
        }
    }

    public function down(): void
    {
        DB::table('platform_settings')->where('key', 'engine.reader_model')->update(['key' => 'engine.quick_model']);
        DB::table('platform_settings')->where('key', 'engine.helper_model')->delete();
        Schema::table('engine_settings', function (Blueprint $table) {
            $table->dropColumn(['helper_model', 'auto_read_files', 'tutor_marks_topics', 'copy_paste_ai']);
        });
        Schema::table('engine_settings', function (Blueprint $table) {
            $table->renameColumn('reader_model', 'quick_model');
        });
    }
};
