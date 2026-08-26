<?php

namespace App\Http\Controllers;

use App\SocialPublishing\Platforms\YouTube\YouTubeOAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

class YouTubeOAuthController extends Controller
{
    public function redirect(YouTubeOAuthService $oauth): RedirectResponse
    {
        if (! config('social.youtube.client_id') || ! config('social.youtube.client_secret')) {
            return redirect()->route('settings.index')
                ->with('error', 'Configura YOUTUBE_CLIENT_ID y YOUTUBE_CLIENT_SECRET en .env');
        }

        $state = $oauth->newState();
        session(['youtube_oauth_state' => $state]);

        return redirect()->away($oauth->authorizationUrl($state));
    }

    public function callback(Request $request, YouTubeOAuthService $oauth): RedirectResponse
    {
        if ($request->string('state')->toString() !== session('youtube_oauth_state')) {
            return redirect()->route('settings.index')->with('error', 'Estado OAuth inválido. Intenta de nuevo.');
        }

        session()->forget('youtube_oauth_state');

        if ($request->filled('error')) {
            return redirect()->route('settings.index')
                ->with('error', 'Google OAuth: '.$request->string('error')->toString());
        }

        try {
            $tokens = $oauth->exchangeCode($request->string('code')->toString());
            $oauth->storeTokens($tokens);
        } catch (Throwable $e) {
            return redirect()->route('settings.index')->with('error', $e->getMessage());
        }

        return redirect()->route('settings.index')->with('success', 'YouTube conectado correctamente.');
    }

    public function disconnect(YouTubeOAuthService $oauth): RedirectResponse
    {
        $oauth->disconnect();

        return redirect()->route('settings.index')->with('success', 'YouTube desconectado.');
    }
}
