<?php

namespace App\Http\Controllers;

use App\SocialPublishing\Platforms\LinkedIn\LinkedInOAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

class LinkedInOAuthController extends Controller
{
    public function redirect(string $account = 'default'): RedirectResponse
    {
        $oauth = $this->oauth($account);

        if (! $oauth->hasClient()) {
            return redirect()->route('settings.index')
                ->with('error', 'Configura LINKEDIN_CLIENT_ID y LINKEDIN_CLIENT_SECRET en .env');
        }

        $state = $oauth->newState();
        session([
            'linkedin_oauth_state' => $state,
            'linkedin_oauth_account' => $account,
        ]);

        return redirect()->away($oauth->authorizationUrl($state));
    }

    public function callback(Request $request): RedirectResponse
    {
        $account = session('linkedin_oauth_account', 'default');
        $oauth = $this->oauth($account);

        if ($request->string('state')->toString() !== session('linkedin_oauth_state')) {
            return redirect()->route('settings.index')->with('error', 'Estado OAuth inválido. Intenta de nuevo.');
        }

        session()->forget(['linkedin_oauth_state', 'linkedin_oauth_account']);

        if ($request->filled('error')) {
            return redirect()->route('settings.index')
                ->with('error', 'LinkedIn OAuth: '.$request->string('error_description', $request->string('error'))->toString());
        }

        try {
            $tokens = $oauth->exchangeCode($request->string('code')->toString());
            $profile = $oauth->fetchProfile($tokens['access_token']);
            $oauth->storeCredentials($tokens, $profile);
        } catch (Throwable $e) {
            return redirect()->route('settings.index')->with('error', $e->getMessage());
        }

        return redirect()->route('settings.index')->with('success', "{$oauth->label()} conectado correctamente.");
    }

    public function disconnect(string $account = 'default'): RedirectResponse
    {
        $oauth = $this->oauth($account);
        $oauth->disconnect();

        return redirect()->route('settings.index')->with('success', "{$oauth->label()} desconectado.");
    }

    private function oauth(string $account): LinkedInOAuthService
    {
        if (! in_array($account, ['default', 'jessika'], true)) {
            abort(404);
        }

        return new LinkedInOAuthService($account);
    }
}
