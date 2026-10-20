<?php

namespace App\Http\Controllers\Api\V1;

use App\Identity\PrincipalFactory;
use App\Identity\TimeZones;
use App\Models\User;
use App\Platform\Access\Principal;
use App\Platform\Access\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * GET and PATCH /api/v1/me (docs/api/openapi.json). PATCH changes what the
 * student may change about themselves: for now their time zone, so the
 * phone app can set it too. It answers with the account as GET does.
 */
class MeController
{
    public function show(Request $request, PrincipalFactory $principals): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json($this->describe($principals->fromRequest($request), $user));
    }

    public function update(Request $request, PrincipalFactory $principals, TimeZones $zones): JsonResponse
    {
        // The shape here; the service checks the name is a real zone (and that there is a learner to set it for).
        $input = $request->validate(['timezone' => ['required', 'string', 'max:64']]);
        /** @var User $user */
        $user = $request->user();
        $zones->change($principals->fromRequest($request), $input['timezone']);

        return response()->json($this->describe($principals->fromRequest($request), $user));
    }

    /** @return array<string, mixed> */
    private function describe(Principal $principal, User $user): array
    {
        return [
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
            'area' => $principal->area?->value,
        ];
    }
}
