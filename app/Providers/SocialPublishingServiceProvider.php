<?php

namespace App\Providers;

use App\SocialPublishing\Contracts\SocialPublisherInterface;
use App\SocialPublishing\Platforms\Facebook\FacebookPublisher;
use App\SocialPublishing\Platforms\LinkedIn\LinkedInPublisher;
use App\SocialPublishing\Platforms\LinkedIn\LinkedInTokenService;
use App\SocialPublishing\Platforms\TikTok\TikTokPublisher;
use App\SocialPublishing\Platforms\YouTube\YouTubePublisher;
use App\SocialPublishing\Registry\SocialPublisherRegistry;
use Illuminate\Support\ServiceProvider;

class SocialPublishingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SocialPublisherRegistry::class, function (): SocialPublisherRegistry {
            $registry = new SocialPublisherRegistry;
            $linkedInTokensDefault = new LinkedInTokenService('linkedin');
            $linkedInTokensJessika = new LinkedInTokenService('linkedin_jessika');

            $publishers = [
                new YouTubePublisher,
                new FacebookPublisher('facebook_esquinaweb', 'esquinaweb'),
                new FacebookPublisher('facebook_esquinagamers', 'esquinagamers'),
                new LinkedInPublisher('linkedin', $linkedInTokensDefault),
                new LinkedInPublisher('linkedin_jessika', $linkedInTokensJessika),
                new TikTokPublisher,
            ];

            foreach ($publishers as $publisher) {
                if ($publisher instanceof SocialPublisherInterface) {
                    $registry->register($publisher);
                }
            }

            return $registry;
        });
    }
}
