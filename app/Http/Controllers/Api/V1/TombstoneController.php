<?php

namespace App\Http\Controllers\Api\V1;

use App\Identity\PrincipalFactory;
use App\Study\Input;
use App\Study\Tombstones;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** GET /api/v1/sync/tombstones?since={cursor} (docs/api/openapi.json, ADR 0003 §5.4). */
class TombstoneController
{
    public function index(Request $request, PrincipalFactory $principals, Tombstones $tombstones): JsonResponse
    {
        $since = $request->query('since', '0');
        Input::refuse(is_string($since) && ctype_digit($since) && strlen($since) <= 18 ? [] : ['since' => 'Send the cursor as a whole number.']);

        return response()->json($tombstones->since($principals->fromRequest($request), (int) $since));
    }
}
