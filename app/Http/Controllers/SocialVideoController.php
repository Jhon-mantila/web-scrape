<?php

namespace App\Http\Controllers;

use App\Models\SocialPlatformAccount;
use App\Models\SocialPublication;
use App\Models\SocialVideo;
use App\SocialPublishing\Actions\DeleteSocialVideosAction;
use App\SocialPublishing\Actions\GenerateSocialCaptionsAction;
use App\SocialPublishing\Actions\GenerateSocialTitleAction;
use App\SocialPublishing\Actions\PublishAllSocialPublicationsAction;
use App\SocialPublishing\Actions\PublishSocialPublicationAction;
use App\SocialPublishing\Enums\Platform;
use App\SocialPublishing\Enums\PublicationStatus;
use App\SocialPublishing\Platforms\Facebook\FacebookVideoDeleter;
use App\SocialPublishing\Platforms\Facebook\FacebookVideoMetadata;
use App\SocialPublishing\Platforms\Facebook\FacebookVideoPermalink;
use App\SocialPublishing\Support\VideoFileSize;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class SocialVideoController extends Controller
{
    public function index(Request $request): Response
    {
        $videos = SocialVideo::query()
            ->where('user_id', $request->user()->id)
            ->with('publications')
            ->latest()
            ->get()
            ->map(fn (SocialVideo $video) => $this->videoPayload($video));

        return Inertia::render('Videos/Index', [
            'videos' => $videos,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Videos/Create', [
            'platformOptions' => $this->platformOptions(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $maxMb = config('social.upload.max_video_mb', 500);

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'notes' => 'nullable|string|max:2000',
            'platforms' => 'required|array|min:1',
            'platforms.*' => 'string|in:'.implode(',', Platform::values()),
            'video' => 'required|file|mimes:'.implode(',', config('social.upload.video_mimes')).'|max:'.($maxMb * 1024),
            'thumbnail' => 'nullable|file|mimes:'.implode(',', config('social.upload.thumbnail_mimes')).'|max:10240',
        ]);

        DB::transaction(function () use ($request, $validated): void {
            $videoPath = $request->file('video')->store('social-videos', 'public');
            $thumbPath = $request->hasFile('thumbnail')
                ? $request->file('thumbnail')->store('social-thumbnails', 'public')
                : null;

            $video = SocialVideo::create([
                'user_id' => $request->user()->id,
                'title' => $validated['title'],
                'video_path' => $videoPath,
                'thumbnail_path' => $thumbPath,
                'notes' => $validated['notes'] ?? null,
            ]);

            foreach ($validated['platforms'] as $platform) {
                $status = config("social.platforms.{$platform}.coming_soon")
                    ? PublicationStatus::Unavailable
                    : PublicationStatus::Draft;

                $video->publications()->create([
                    'platform' => $platform,
                    'status' => $status,
                ]);
            }
        });

        return redirect()->route('videos.index')->with('success', 'Video subido correctamente.');
    }

    public function show(Request $request, SocialVideo $video): Response
    {
        $this->authorizeVideo($request, $video);

        $video->load('publications');

        return Inertia::render('Videos/Show', [
            'video' => $this->videoPayload($video, detailed: true),
            'platformOptions' => $this->platformOptions(),
        ]);
    }

    public function update(Request $request, SocialVideo $video): RedirectResponse
    {
        $this->authorizeVideo($request, $video);

        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'notes' => 'nullable|string|max:2000',
            'thumbnail' => 'nullable|file|mimes:'.implode(',', config('social.upload.thumbnail_mimes')).'|max:10240',
            'publications' => 'sometimes|array',
            'publications.*.id' => 'required|integer|exists:social_publications,id',
            'publications.*.caption_edited' => 'nullable|string|max:10000',
            'publications.*.scheduled_at' => 'nullable|date',
        ]);

        $updates = [];

        if (isset($validated['title'])) {
            $updates['title'] = $validated['title'];
            $updates['notes'] = $validated['notes'] ?? $video->notes;
        }

        if ($request->hasFile('thumbnail')) {
            if ($video->thumbnail_path) {
                Storage::disk('public')->delete($video->thumbnail_path);
            }
            $updates['thumbnail_path'] = $request->file('thumbnail')->store('social-thumbnails', 'public');
        }

        if ($updates !== []) {
            $video->update($updates);
        }

        if (isset($validated['publications'])) {
            foreach ($validated['publications'] as $row) {
                SocialPublication::query()
                    ->where('social_video_id', $video->id)
                    ->where('id', $row['id'])
                    ->update([
                        'caption_edited' => $row['caption_edited'] ?? null,
                        'scheduled_at' => $this->normalizeScheduledAt($row['scheduled_at'] ?? null),
                    ]);
            }
        }

        return back()->with('success', 'Cambios guardados.');
    }

    public function generateCaptions(
        Request $request,
        SocialVideo $video,
        GenerateSocialCaptionsAction $action,
    ): RedirectResponse {
        $this->authorizeVideo($request, $video);

        $platforms = $request->input('platforms');

        $action->execute($video, is_array($platforms) ? $platforms : null);

        return back()->with('success', 'Textos generados con IA.');
    }

    public function generateTitle(
        Request $request,
        SocialVideo $video,
        GenerateSocialTitleAction $action,
    ): RedirectResponse {
        $this->authorizeVideo($request, $video);

        $action->execute($video);

        return back()->with('success', 'Título generado con IA.');
    }

    public function publishAll(
        SocialVideo $video,
        PublishAllSocialPublicationsAction $action,
        Request $request,
    ): RedirectResponse {
        $this->syncFromRequest($video, $request);

        $validated = $request->validate([
            'publication_ids' => 'sometimes|array',
            'publication_ids.*' => 'integer',
        ]);

        $publicationIds = null;

        if ($request->has('publication_ids')) {
            $validIds = $video->publications()->pluck('id')->all();
            $publicationIds = array_values(array_intersect(
                $validated['publication_ids'] ?? [],
                $validIds,
            ));

            if ($publicationIds === []) {
                return back()->with('error', 'No seleccionaste ninguna plataforma para enviar.');
            }
        }

        $summary = $action->execute($video->fresh(['publications']), $publicationIds);

        $selected = $publicationIds !== null ? count($publicationIds) : $video->publications->count();
        $message = "Envío ({$selected} seleccionada(s)): {$summary['published']} OK, {$summary['failed']} fallidas, {$summary['skipped']} omitidas.";

        return back()->with(
            $summary['failed'] > 0 ? 'error' : 'success',
            $message,
        );
    }

    public function publish(
        SocialVideo $video,
        SocialPublication $publication,
        PublishSocialPublicationAction $action,
        Request $request,
    ): RedirectResponse {
        abort_unless($publication->social_video_id === $video->id, 404);

        $this->syncFromRequest($video, $request);

        $action->execute($publication->fresh());

        $publication->refresh();
        $message = match ($publication->status) {
            PublicationStatus::Scheduled => 'Video programado.',
            PublicationStatus::Published => 'Video publicado.',
            PublicationStatus::Failed => $publication->last_error ?? 'Error al publicar.',
            default => 'Publicación procesada.',
        };

        return back()->with(
            $publication->status === PublicationStatus::Failed ? 'error' : 'success',
            $message,
        );
    }

    public function destroyOnFacebook(
        Request $request,
        SocialVideo $video,
        SocialPublication $publication,
        FacebookVideoDeleter $deleter,
    ): RedirectResponse {
        abort_unless($publication->social_video_id === $video->id, 404);
        abort_unless(str_starts_with($publication->platform, 'facebook_'), 404);

        $this->authorizeVideo($request, $video);

        $credentials = SocialPlatformAccount::facebookPageCredentials($publication->platform);

        if ($credentials === null) {
            return back()->with('error', 'Facebook no está conectado para esta plataforma.');
        }

        if ($publication->external_id === null || $publication->external_id === '') {
            $fromResponse = is_array($publication->api_response)
                ? ($publication->api_response['facebook_video_id'] ?? $publication->api_response['id'] ?? null)
                : null;

            if (is_string($fromResponse) && $fromResponse !== '') {
                $publication->external_id = $fromResponse;
            }
        }

        if ($publication->external_id === null || $publication->external_id === '') {
            return back()->with('error', 'No hay video registrado en Facebook para eliminar.');
        }

        try {
            $deleter->delete($publication->external_id, $credentials['page_access_token']);
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
        ]);

        return back()->with('success', 'Enlace con Facebook limpiado. Ya puedes volver a enviar (programado o inmediato).');
    }

    public function destroy(Request $request, SocialVideo $video, DeleteSocialVideosAction $action): RedirectResponse
    {
        $this->authorizeVideo($request, $video);

        $action->execute(collect([$video]));

        return redirect()->route('videos.index')->with('success', 'Video eliminado.');
    }

    public function bulkDestroy(Request $request, DeleteSocialVideosAction $action): RedirectResponse
    {
        $validated = $request->validate([
            'ids' => 'sometimes|array',
            'ids.*' => 'integer|exists:social_videos,id',
            'all' => 'sometimes|boolean',
        ]);

        $query = SocialVideo::query()->where('user_id', $request->user()->id);

        if ($request->boolean('all')) {
            $videos = $query->get();
        } else {
            $ids = $validated['ids'] ?? [];

            if ($ids === []) {
                return back()->with('error', 'No seleccionaste ningún video.');
            }

            $videos = $query->whereIn('id', $ids)->get();
        }

        if ($videos->isEmpty()) {
            return back()->with('error', 'No hay videos para eliminar.');
        }

        $count = $action->execute($videos);

        return redirect()->route('videos.index')->with('success', "{$count} video(s) eliminado(s) del historial.");
    }

    /**
     * @return array<string, mixed>
     */
    private function videoPayload(SocialVideo $video, bool $detailed = false): array
    {
        $videoSizeBytes = VideoFileSize::bytesFromPath(
            Storage::disk('public')->path($video->video_path),
        );
        $videoMetadata = FacebookVideoMetadata::tryFromPath(
            Storage::disk('public')->path($video->video_path),
        );

        return [
            'id' => $video->id,
            'title' => $video->title,
            'notes' => $video->notes,
            'video_url' => $this->publicStorageUrl($video->video_path),
            'video_size_bytes' => $videoSizeBytes,
            'video_size_label' => VideoFileSize::label($videoSizeBytes),
            'video_size_mb' => VideoFileSize::megabytes($videoSizeBytes),
            'video_width' => $videoMetadata?->width,
            'video_height' => $videoMetadata?->height,
            'facebook_content_type' => 'Video de página',
            'facebook_max_video_gb' => (int) config('social.facebook.max_video_gb', 2),
            'thumbnail_url' => $video->thumbnail_path
                ? $this->publicStorageUrl($video->thumbnail_path)
                : null,
            'has_thumbnail' => $video->thumbnail_path !== null && $video->thumbnail_path !== '',
            'created_at' => $video->created_at?->toIso8601String(),
            'publications' => $video->publications->map(fn (SocialPublication $p) => [
                'id' => $p->id,
                'platform' => $p->platform,
                'platform_label' => $p->platformLabel(),
                'status' => $p->status->value,
                'status_label' => $p->status->label(),
                'status_icon' => $p->status->icon(),
                'caption_generated' => $p->caption_generated,
                'caption_edited' => $p->caption_edited,
                'caption' => $p->caption(),
                'scheduled_at' => $p->scheduled_at?->toIso8601String(),
                'published_at' => $p->published_at?->toIso8601String(),
                'external_id' => $p->external_id,
                'external_url' => $this->facebookPublicationUrl($p),
                'last_error' => $detailed ? $p->last_error : null,
                'api_response' => $detailed ? $p->api_response : null,
                'coming_soon' => (bool) config("social.platforms.{$p->platform}.coming_soon"),
                'platform_hints' => config("social.platforms.{$p->platform}.hints"),
            ])->values(),
            'max_video_mb' => (int) config('social.upload.max_video_mb', 500),
        ];
    }

    /**
     * @return list<array{key: string, label: string, enabled: bool, coming_soon: bool}>
     */
    private function platformOptions(): array
    {
        return collect(config('social.platforms', []))
            ->map(fn (array $cfg, string $key) => [
                'key' => $key,
                'label' => $cfg['label'] ?? $key,
                'enabled' => (bool) ($cfg['enabled'] ?? false),
                'coming_soon' => (bool) ($cfg['coming_soon'] ?? false),
            ])
            ->values()
            ->all();
    }

    private function normalizeScheduledAt(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }

    private function publicStorageUrl(string $path): string
    {
        return '/storage/'.ltrim($path, '/');
    }

    private function facebookPublicationUrl(SocialPublication $publication): ?string
    {
        if ($publication->external_url === null || ! str_starts_with($publication->platform, 'facebook_')) {
            return $publication->external_url;
        }

        $videoId = $publication->external_id;

        if ($videoId === null || $videoId === '') {
            return $publication->external_url;
        }

        $apiResponse = is_array($publication->api_response) ? $publication->api_response : [];
        $contentType = is_string($apiResponse['content_type'] ?? null)
            ? $apiResponse['content_type']
            : 'page_video';

        if ($contentType === 'reel') {
            $contentType = 'page_video';
        }

        $credentials = SocialPlatformAccount::facebookPageCredentials($publication->platform);
        $pageId = is_array($credentials) ? ($credentials['page_id'] ?? null) : null;

        if ($pageId === null || $pageId === '') {
            $configKey = str_replace('facebook_', '', $publication->platform);
            $pageId = config("social.facebook.{$configKey}.page_id");
        }

        return FacebookVideoPermalink::build(
            $videoId,
            $contentType,
            is_string($pageId) ? $pageId : null,
            $publication->external_url,
        );
    }

    private function syncFromRequest(SocialVideo $video, Request $request): void
    {
        $this->authorizeVideo($request, $video);

        if ($request->filled('title')) {
            $video->update([
                'title' => $request->string('title')->toString(),
                'notes' => $request->input('notes'),
            ]);
        }

        $publications = $request->input('publications');
        if (! is_array($publications)) {
            return;
        }

        foreach ($publications as $row) {
            if (! is_array($row) || empty($row['id'])) {
                continue;
            }

            SocialPublication::query()
                ->where('social_video_id', $video->id)
                ->where('id', $row['id'])
                ->update([
                    'caption_edited' => $row['caption_edited'] ?? null,
                    'scheduled_at' => $this->normalizeScheduledAt($row['scheduled_at'] ?? null),
                ]);
        }
    }

    private function authorizeVideo(Request $request, SocialVideo $video): void
    {
        abort_unless($video->user_id === $request->user()->id, 403);
    }
}
