<?php

namespace App\Http\Controllers;

use App\SocialPublishing\Platforms\Facebook\FacebookOAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

class FacebookOAuthController extends Controller
{
    public function redirect(string $account): RedirectResponse
    {
        $oauth = $this->oauth($account);

        if (! $oauth->hasClient()) {
            return redirect()->route('settings.index')
                ->with('error', "Configura FACEBOOK_{$this->envPrefix($account)}_APP_ID y APP_SECRET en .env");
        }

        if ($oauth->expectedPageId() === null) {
            return redirect()->route('settings.index')
                ->with('error', "Configura FACEBOOK_{$this->envPrefix($account)}_PAGE_ID en .env");
        }

        $state = $oauth->newState();
        session(["facebook_oauth_state_{$account}" => $state]);

        return redirect()->away($oauth->authorizationUrl($state));
    }

    public function callback(Request $request, string $account): RedirectResponse
    {
        $oauth = $this->oauth($account);
        $sessionKey = "facebook_oauth_state_{$account}";

        if ($request->string('state')->toString() !== session($sessionKey)) {
            return redirect()->route('settings.index')->with('error', 'Estado OAuth inválido. Intenta de nuevo.');
        }

        session()->forget($sessionKey);

        if ($request->filled('error')) {
            return redirect()->route('settings.index')
                ->with('error', 'Facebook OAuth: '.$request->string('error_description', $request->string('error'))->toString());
        }

        try {
            $tokenData = $oauth->exchangeCode($request->string('code')->toString());
            $page = $oauth->resolvePageToken($tokenData['access_token']);
            $oauth->storePageCredentials($page, $tokenData['expires_in']);
        } catch (Throwable $e) {
            return redirect()->route('settings.index')->with('error', $e->getMessage());
        }

        return redirect()->route('settings.index')->with('success', "{$oauth->label()} conectado correctamente.");
    }

    public function disconnect(string $account): RedirectResponse
    {
        $oauth = $this->oauth($account);
        $oauth->disconnect();

        return redirect()->route('settings.index')->with('success', "{$oauth->label()} desconectado.");
    }

    private function oauth(string $account): FacebookOAuthService
    {
        if (! in_array($account, ['esquinaweb', 'esquinagamers'], true)) {
            abort(404);
        }

        return new FacebookOAuthService($account);
    }

    private function envPrefix(string $account): string
    {
        return strtoupper($account);
    }
}
