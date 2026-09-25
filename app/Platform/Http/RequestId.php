<?php

namespace App\Platform\Http;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;

/** The request ID, set by AssignRequestId and echoed in X-Request-Id and every error envelope. */
final class RequestId
{
    public const ATTRIBUTE = 'request_id';

    public static function of(?Request $request = null): ?string
    {
        $id = $request?->attributes->get(self::ATTRIBUTE);

        return is_string($id) ? $id : Context::get(self::ATTRIBUTE);
    }
}
