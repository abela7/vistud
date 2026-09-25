<?php

namespace App\Http\Controllers\Api\V1;

use App\Identity\PrincipalFactory;
use App\Models\User;
use App\Platform\Access\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** GET /api/v1/me (docs/api/openapi.json). */
class MeController
{
    public function show(Request $request, PrincipalFactory $principals): JsonResponse
    {
        $principal = $principals->fromRequest($request);
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'account' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'roles' => array_map(fn (Role $role) => $role->value, $principal->roles),
                'two_factor_confirmed' => $principal->twoFactorConfirmed,
            ],
            'learner' => $principal->learnerId === null ? null : [
                'id' => $principal->learnerId,
                'timezone' => DB::table('learners')->where('id', $principal->learnerId)->value('timezone'),
            ],
            'workspace' => $principal->workspace?->value,
        ]);
    }
}
