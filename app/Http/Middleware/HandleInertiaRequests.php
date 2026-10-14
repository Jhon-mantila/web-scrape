<?php

namespace App\Http\Middleware;

use App\ProcessScraping\Support\PipelineRunState;
use App\SocialPublishing\Support\PlatformConnectionAlertService;
use App\SocialPublishing\Support\PlatformTokenAlertService;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user(),
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'backgroundRunSnapshot' => fn () => $request->session()->get('background_run_snapshot'),
            ],
            'platforms' => config('social.platforms'),
            'backgroundRun' => fn () => $request->user()
                ? PipelineRunState::snapshot($request->user()->id)
                : null,
            'pipelineRun' => fn () => $request->user()
                ? PipelineRunState::snapshot($request->user()->id)
                : null,
            'platformTokenAlerts' => fn () => $request->user()
                ? app(PlatformTokenAlertService::class)->collect()
                : [],
            'platformConnectionAlerts' => fn () => $request->user()
                ? app(PlatformConnectionAlertService::class)->collect()
                : [],
        ];
    }
}
