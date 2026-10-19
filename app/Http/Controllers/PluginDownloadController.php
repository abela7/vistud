<?php

namespace App\Http\Controllers;

use App\Mcp\Plugins\PluginBuilder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** The install package for a chat app, built from resources/skills when asked (docs/specs/mcp-connections-plan.md M6.4). It holds nothing of the student's. */
class PluginDownloadController
{
    public function __invoke(Request $request, PluginBuilder $builder, string $target): BinaryFileResponse
    {
        abort_unless(in_array($target, PluginBuilder::TARGETS, true), 404);

        return response()->download($builder->zip($target), PluginBuilder::NAME.'-'.$target.'.zip', ['Content-Type' => 'application/zip'])->deleteFileAfterSend(true);
    }
}
