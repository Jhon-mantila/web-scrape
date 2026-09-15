<?php

namespace App\Http\Controllers;

use App\Models\SocialArticlePublication;
use App\Models\SocialPlatformAccount;
use App\Models\WordpressPost;
use App\SocialPublishing\Platforms\Facebook\FacebookVideoDeleter;
use App\SocialPublishing\Actions\GenerateSocialArticleCaptionAction;
use App\SocialPublishing\Actions\PublishSocialArticleAction;
use App\SocialPublishing\Actions\PublishSocialArticleBatchAction;
use App\SocialPublishing\Enums\PublicationStatus;
use App\SocialPublishing\Enums\WordpressSite;
use App\Wordpress\Actions\SyncWordpressPostsAction;
use App\Wordpress\WordpressArticleStats;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class WordpressArticleController extends Controller
{
    public function index(Request $request): Response
    {
        $siteFilter = (string) $request->query('site', 'esquinaweb');
        $site = WordpressSite::tryFromEnabled($siteFilter);

        if ($site === null) {
            $site = collect(WordpressSite::cases())
                ->first(fn (WordpressSite $candidate) => $candidate->isEnabled())
                ?? WordpressSite::Esquinaweb;
            $siteFilter = $site->value;
        }

        $pendingFacebook = $request->boolean('pending_facebook');
        $pendingLinkedin = $request->boolean('pending_linkedin');
        $search = trim((string) $request->query('q', ''));

        $query = WordpressPost::query()
            ->forSite($siteFilter)
            ->with('publications')
            ->orderByDesc('published_at_wp')
            ->orderByDesc('id');

        if ($search !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';

            $query->where(function ($builder) use ($like) {
                $builder
                    ->where('title', 'like', $like)
                    ->orWhere('excerpt', 'like', $like)
                    ->orWhere('url', 'like', $like);
            });
        }

        $facebookPlatform = $site->facebookPlatform();

        if ($pendingFacebook && $facebookPlatform !== null) {
            $query->pendingPlatform($facebookPlatform);
        }

        if ($pendingLinkedin) {
            $linkedinPlatforms = config('wordpress_sources.linkedin_platforms', []);

            $query->whereDoesntHave('publications', function ($publications) use ($linkedinPlatforms) {
                $publications
                    ->whereIn('platform', $linkedinPlatforms)
                    ->whereIn('status', [
                        PublicationStatus::Published,
                        PublicationStatus::Scheduled,
                        PublicationStatus::Publishing,
                    ]);
            });
        }

        $posts = $query->paginate(15)->withQueryString();

        return Inertia::render('Articles/Index', [
            'filters' => [
                'site' => $siteFilter,
                'pending_facebook' => $pendingFacebook,
                'pending_linkedin' => $pendingLinkedin,
                'q' => $search,
            ],
            'sites' => WordpressSite::optionsForUi(),
            'platforms' => $this->platformOptions(),
            'stats' => WordpressArticleStats::compute($siteFilter),
            'sync' => $this->syncSettings(),
            'posts' => $posts->through(fn (WordpressPost $post) => $this->postPayload($post)),
        ]);
    }

    public function sync(Request $request, SyncWordpressPostsAction $action): RedirectResponse
    {
        $limits = config('wordpress_sources.sync.limits', []);
        $validated = $request->validate([
            'site' => 'nullable|string',
            'per_page' => 'nullable|integer|min:'.($limits['per_page']['min'] ?? 1).'|max:'.($limits['per_page']['max'] ?? 100),
            'backfill_pages' => 'nullable|integer|min:'.($limits['backfill_pages']['min'] ?? 1).'|max:'.($limits['backfill_pages']['max'] ?? 20),
            'new_pages' => 'nullable|integer|min:'.($limits['new_pages']['min'] ?? 1).'|max:'.($limits['new_pages']['max'] ?? 10),
        ]);

        $sites = isset($validated['site']) && $validated['site'] !== ''
            ? [(string) $validated['site']]
            : null;

        $summary = $action->execute(
            $sites,
            isset($validated['per_page']) ? (int) $validated['per_page'] : null,
            isset($validated['backfill_pages']) ? (int) $validated['backfill_pages'] : null,
            isset($validated['new_pages']) ? (int) $validated['new_pages'] : null,
        );

        if ($summary['total_added'] === 0 && $this->hasSyncErrors($summary)) {
            $firstError = collect($summary['sites'])->pluck('error')->filter()->first();

            return back()->with('error', 'No se pudo sincronizar: '.($firstError ?? 'error desconocido'));
        }

        $parts = [];

        foreach ($summary['sites'] as $siteKey => $row) {
            if ($row['error'] !== null) {
                $parts[] = "{$siteKey}: error ({$row['error']})";
            } else {
                $parts[] = "{$siteKey}: +{$row['added']} nuevos ({$row['skipped']} ya estaban)";
            }
        }

        return back()->with(
            $this->hasSyncErrors($summary) ? 'error' : 'success',
            'Sincronización: '.implode(' · ', $parts),
        );
    }

    public function generateCaption(
        Request $request,
        WordpressPost $post,
        GenerateSocialArticleCaptionAction $action,
    ): JsonResponse {
        $validated = $request->validate([
            'platform' => 'nullable|string|max:64',
            'platforms' => 'nullable|array|min:1',
            'platforms.*' => 'string|max:64',
        ]);

        $site = $post->siteEnum();

        if ($site === null) {
            return response()->json(['message' => 'Sitio WordPress no válido.'], 422);
        }

        $platformKeys = $validated['platforms'] ?? null;

        if ($platformKeys !== null) {
            foreach ($platformKeys as $platformKey) {
                if (! $site->allowsPlatform($platformKey)) {
                    return response()->json(['message' => "Plataforma no permitida: {$platformKey}"], 422);
                }
            }

            try {
                $captions = $action->executeMany($post, $platformKeys);
            } catch (\Throwable $e) {
                return response()->json(['message' => $e->getMessage()], 500);
            }

            return response()->json(['captions' => $captions]);
        }

        $platform = (string) ($validated['platform'] ?? '');

        if ($platform === '' || ! $site->allowsPlatform($platform)) {
            return response()->json(['message' => 'Plataforma no permitida.'], 422);
        }

        try {
            $caption = $action->execute($post, $platform);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }

        return response()->json(['caption' => $caption]);
    }

    public function destroyOnFacebook(
        Request $request,
        WordpressPost $post,
        SocialArticlePublication $publication,
        FacebookVideoDeleter $deleter,
    ): RedirectResponse {
        abort_unless($publication->wordpress_post_id === $post->id, 404);
        abort_unless(str_starts_with($publication->platform, 'facebook_'), 404);

        if (! $publication->canDeleteFromFacebook()) {
            return back()->with('error', 'No hay publicación en Facebook que se pueda eliminar.');
        }

        $credentials = SocialPlatformAccount::facebookPageCredentials($publication->platform);

        if ($credentials === null) {
            return back()->with('error', 'Facebook no está conectado para esta plataforma.');
        }

        $postId = $publication->facebookPostId();

        try {
            $deleter->delete((string) $postId, $credentials['page_access_token']);
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        $publication->update([
            'status' => PublicationStatus::Draft,
            'external_id' => null,
            'external_url' => null,
            'api_response' => null,
            'last_error' => null,
            'published_at' => null,
            'scheduled_at' => null,
        ]);

        return back()->with('success', 'Publicación eliminada de Facebook. Ya puedes volver a enviar el artículo.');
    }

    public function publishBatch(
        Request $request,
        WordpressPost $post,
        PublishSocialArticleBatchAction $action,
    ): RedirectResponse {
        $validated = $request->validate([
            'publications' => 'required|array|min:1',
            'publications.*.platform' => 'required|string|max:64',
            'publications.*.message' => 'nullable|string|max:5000',
        ]);

        $summary = $action->execute(
            $post,
            $validated['publications'],
            (int) $request->user()->id,
        );

        $message = "LinkedIn: {$summary['published']} OK, {$summary['failed']} fallidas, {$summary['skipped']} omitidas.";

        if ($summary['errors'] !== []) {
            $message .= ' '.implode(' | ', $summary['errors']);
        }

        return back()->with(
            $summary['failed'] > 0 ? 'error' : 'success',
            $message,
        );
    }

    public function publish(
        Request $request,
        WordpressPost $post,
        PublishSocialArticleAction $action,
    ): RedirectResponse {
        $validated = $request->validate([
            'platform' => 'required|string|max:64',
            'message' => 'nullable|string|max:5000',
            'scheduled_at' => 'nullable|date',
        ]);

        $site = $post->siteEnum();

        if ($site === null || ! $site->allowsPlatform($validated['platform'])) {
            return back()->with('error', 'Plataforma no permitida para este artículo.');
        }

        $scheduledAt = $this->parseScheduledAt($validated['scheduled_at'] ?? null);

        if ($scheduledAt !== null && ! str_starts_with($validated['platform'], 'facebook_')) {
            return back()->with('error', 'Solo Facebook admite programación de artículos.');
        }

        try {
            $publication = $action->execute(
                $post,
                $validated['platform'],
                $validated['message'] ?? null,
                (int) $request->user()->id,
                $scheduledAt,
            );
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        $label = $publication->platformLabel();

        if ($publication->status === PublicationStatus::Scheduled) {
            return back()->with('success', "Artículo programado en {$label}.");
        }

        if ($publication->status === PublicationStatus::Published) {
            return back()->with('success', "Artículo publicado en {$label}.");
        }

        return back()->with('error', $publication->last_error ?? "Error al publicar en {$label}.");
    }

    /**
     * @return list<array{key: string, label: string, kind: string}>
     */
    private function platformOptions(): array
    {
        $options = [];

        foreach (config('social.platforms', []) as $key => $meta) {
            if (! str_starts_with($key, 'facebook_') && ! str_starts_with($key, 'linkedin')) {
                continue;
            }

            $options[] = [
                'key' => $key,
                'label' => (string) ($meta['label'] ?? $key),
                'kind' => str_starts_with($key, 'facebook_') ? 'facebook' : 'linkedin',
            ];
        }

        return $options;
    }

    /**
     * @return array<string, mixed>
     */
    private function postPayload(WordpressPost $post): array
    {
        $site = $post->siteEnum();
        $allowed = $site?->allowedPlatforms() ?? [];

        $publicationsByPlatform = $post->publications
            ->keyBy(fn (SocialArticlePublication $publication) => $publication->platform);

        $platformStatuses = [];

        foreach ($allowed as $platformKey) {
            /** @var SocialArticlePublication|null $publication */
            $publication = $publicationsByPlatform->get($platformKey);

            $platformStatuses[] = [
                'publication_id' => $publication?->id,
                'platform' => $platformKey,
                'platform_label' => config("social.platforms.{$platformKey}.label", $platformKey),
                'status' => $publication?->status->value ?? 'pending',
                'status_label' => $publication?->status->label() ?? 'Pendiente',
                'status_icon' => $publication?->status->icon() ?? '—',
                'scheduled_at' => $publication?->scheduled_at?->toIso8601String(),
                'published_at' => $publication?->published_at?->toIso8601String(),
                'external_url' => $publication?->external_url,
                'can_publish' => $publication === null || ! $publication->blocksRepublish(),
                'can_delete_from_facebook' => $publication?->canDeleteFromFacebook() ?? false,
            ];
        }

        return [
            'id' => $post->id,
            'site' => $post->site,
            'site_label' => $post->siteLabel(),
            'wp_post_id' => $post->wp_post_id,
            'title' => $post->title,
            'excerpt' => $post->excerpt,
            'url' => $post->url,
            'featured_image_url' => $post->featured_image_url,
            'published_at_wp' => $post->published_at_wp?->toIso8601String(),
            'synced_at' => $post->synced_at?->toIso8601String(),
            'facebook_platform' => $post->facebookPlatform(),
            'platforms' => $platformStatuses,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function syncSettings(): array
    {
        $limits = config('wordpress_sources.sync.limits', []);

        return [
            'per_page' => (int) config('wordpress_sources.sync.per_page', 20),
            'backfill_pages' => (int) config('wordpress_sources.sync.backfill_pages', 5),
            'new_pages' => (int) config('wordpress_sources.sync.new_pages', 3),
            'limits' => $limits,
        ];
    }

    /**
     * @param  array{sites: array<string, array{added: int, skipped: int, error: ?string}>, total_added: int}  $summary
     */
    private function hasSyncErrors(array $summary): bool
    {
        foreach ($summary['sites'] as $row) {
            if ($row['error'] !== null) {
                return true;
            }
        }

        return false;
    }

    private function parseScheduledAt(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        $scheduledAt = Carbon::parse((string) $value, config('app.timezone'));

        return $scheduledAt->isFuture() ? $scheduledAt : null;
    }
}
