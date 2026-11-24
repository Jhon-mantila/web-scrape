<?php

namespace App\VideoProcessing\Http\Middleware;

use App\Models\VideoWorker;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateVideoWorker
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! is_string($token) || $token === '') {
            return response()->json(['message' => 'Worker token required.'], 401);
        }

        $worker = VideoWorker::query()
            ->where('token_hash', VideoWorker::hashToken($token))
            ->where('is_enabled', true)
            ->first();

        if ($worker === null) {
            return response()->json(['message' => 'Invalid worker token.'], 401);
        }

        $worker->forceFill(['last_seen_at' => now()])->save();
        $request->attributes->set('videoWorker', $worker);

        return $next($request);
    }
}
