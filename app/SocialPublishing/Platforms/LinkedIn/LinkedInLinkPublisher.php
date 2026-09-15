<?php

namespace App\SocialPublishing\Platforms\LinkedIn;

use App\Models\SocialPlatformAccount;
use App\SocialPublishing\DTO\PublishResult;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class LinkedInLinkPublisher
{
    public function __construct(
        private readonly string $platformKey,
        private readonly LinkedInTokenService $tokens,
    ) {}

    public function publishLink(
        string $commentary,
        string $articleUrl,
        string $title,
        ?string $description = null,
        ?string $thumbnailUrl = null,
    ): PublishResult {
        if (SocialPlatformAccount::linkedinCredentials($this->platformKey) === null) {
            return PublishResult::fail("LinkedIn ({$this->platformKey}) no conectado. Ve a Configuración.");
        }

        try {
            $personUrn = $this->tokens->personUrn();
            $headers = $this->tokens->apiHeaders();

            $article = [
                'source' => $articleUrl,
                'title' => mb_substr($title, 0, 200),
            ];

            if (is_string($description) && $description !== '') {
                $article['description'] = mb_substr($description, 0, 500);
            }

            if (is_string($thumbnailUrl) && $thumbnailUrl !== '') {
                $article['thumbnail'] = $thumbnailUrl;
            }

            $postResponse = LinkedInHttpClient::api($headers)
                ->post('https://api.linkedin.com/rest/posts', [
                    'author' => $personUrn,
                    'commentary' => mb_substr($commentary, 0, 3000),
                    'visibility' => 'PUBLIC',
                    'distribution' => [
                        'feedDistribution' => 'MAIN_FEED',
                        'targetEntities' => [],
                        'thirdPartyDistributionChannels' => [],
                    ],
                    'content' => [
                        'article' => $article,
                    ],
                    'lifecycleState' => 'PUBLISHED',
                    'isReshareDisabledByAuthor' => false,
                ]);

            if ($postResponse->failed()) {
                Log::warning('linkedin: link post failed', ['body' => $postResponse->body()]);

                return PublishResult::fail(
                    'LinkedIn posts: '.$postResponse->status().' — '.$postResponse->body(),
                    $postResponse->json(),
                );
            }

            $postId = $postResponse->header('x-restli-id') ?? $postResponse->header('X-RestLi-Id');
            $postId = is_string($postId) ? $postId : null;

            return PublishResult::ok(
                $postId ?? $articleUrl,
                $postId !== null ? $this->buildPostUrl($postId) : $articleUrl,
                [
                    'post_id' => $postId,
                    'response' => $postResponse->json(),
                ],
            );
        } catch (RuntimeException $e) {
            return PublishResult::fail($e->getMessage());
        } catch (\Throwable $e) {
            return PublishResult::fail($e->getMessage());
        }
    }

    private function buildPostUrl(string $postId): string
    {
        return 'https://www.linkedin.com/feed/update/'.rawurlencode($postId);
    }
}
