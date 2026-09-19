<?php

namespace App\Http\Support;

use Illuminate\Http\Request;

class RequestsBackgroundJson
{
    public static function matches(Request $request): bool
    {
        if ($request->header('X-Inertia')) {
            return false;
        }

        $accept = strtolower((string) $request->header('Accept', ''));

        return $request->expectsJson()
            || $request->wantsJson()
            || $request->ajax()
            || str_contains($accept, 'application/json')
            || str_contains($accept, '+json');
    }
}
