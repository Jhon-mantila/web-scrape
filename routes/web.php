<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FacebookOAuthController;
use App\Http\Controllers\LinkedInOAuthController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SocialVideoController;
use App\Http\Controllers\YouTubeOAuthController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::middleware('guest')->group(function () {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('login', [AuthenticatedSessionController::class, 'store']);
    Route::get('register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('register', [RegisteredUserController::class, 'store']);
});

Route::middleware('auth')->group(function () {
    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');

    Route::get('/auth/youtube/redirect', [YouTubeOAuthController::class, 'redirect'])->name('youtube.oauth.redirect');
    Route::get('/auth/youtube/callback', [YouTubeOAuthController::class, 'callback'])->name('youtube.oauth.callback');
    Route::post('/auth/youtube/disconnect', [YouTubeOAuthController::class, 'disconnect'])->name('youtube.oauth.disconnect');

    Route::get('/auth/facebook/{account}/redirect', [FacebookOAuthController::class, 'redirect'])->name('facebook.oauth.redirect');
    Route::get('/auth/facebook/{account}/callback', [FacebookOAuthController::class, 'callback'])->name('facebook.oauth.callback');
    Route::post('/auth/facebook/{account}/disconnect', [FacebookOAuthController::class, 'disconnect'])->name('facebook.oauth.disconnect');

    Route::get('/auth/linkedin/redirect', [LinkedInOAuthController::class, 'redirect'])->name('linkedin.oauth.redirect');
    Route::get('/auth/linkedin/{account}/redirect', [LinkedInOAuthController::class, 'redirect'])->where('account', 'jessika')->name('linkedin.oauth.redirect.account');
    Route::get('/auth/linkedin/callback', [LinkedInOAuthController::class, 'callback'])->name('linkedin.oauth.callback');
    Route::post('/auth/linkedin/disconnect', [LinkedInOAuthController::class, 'disconnect'])->name('linkedin.oauth.disconnect');
    Route::post('/auth/linkedin/{account}/disconnect', [LinkedInOAuthController::class, 'disconnect'])->where('account', 'jessika')->name('linkedin.oauth.disconnect.account');

    Route::get('/videos', [SocialVideoController::class, 'index'])->name('videos.index');
    Route::get('/videos/create', [SocialVideoController::class, 'create'])->name('videos.create');
    Route::post('/videos', [SocialVideoController::class, 'store'])->name('videos.store');
    Route::post('/videos/bulk-destroy', [SocialVideoController::class, 'bulkDestroy'])->name('videos.bulk-destroy');
    Route::get('/videos/{video}', [SocialVideoController::class, 'show'])->name('videos.show');
    Route::put('/videos/{video}', [SocialVideoController::class, 'update'])->name('videos.update');
    Route::delete('/videos/{video}', [SocialVideoController::class, 'destroy'])->name('videos.destroy');
    Route::post('/videos/{video}/generate-captions', [SocialVideoController::class, 'generateCaptions'])
        ->name('videos.generate-captions');
    Route::post('/videos/{video}/generate-title', [SocialVideoController::class, 'generateTitle'])
        ->name('videos.generate-title');
    Route::post('/videos/{video}/publish-all', [SocialVideoController::class, 'publishAll'])
        ->name('videos.publish-all');
    Route::post('/videos/{video}/publications/{publication}/publish', [SocialVideoController::class, 'publish'])
        ->name('videos.publications.publish');
    Route::delete('/videos/{video}/publications/{publication}/facebook', [SocialVideoController::class, 'destroyOnFacebook'])
        ->name('videos.publications.facebook.destroy');
});
