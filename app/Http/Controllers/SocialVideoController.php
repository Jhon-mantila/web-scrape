<?php

namespace App\Http\Controllers;

use App\Models\SocialPublication;
use App\Models\SocialVideo;
use App\SocialPublishing\Actions\DeleteSocialVideosAction;
use App\SocialPublishing\Actions\GenerateSocialCaptionsAction;
use App\SocialPublishing\Actions\GenerateSocialTitleAction;
use App\SocialPublishing\Actions\PublishAllSocialPublicationsAction;
use App\SocialPublishing\Actions\PublishSocialPublicationAction;
use App\SocialPublishing\Enums\Platform;
use App\SocialPublishing\Enums\PublicationStatus;
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
            'thumbnail' => 'required|file|mimes:'.implode(',', config('social.upload.thumbnail_mimes')).'|max:10240',
        ]);

        DB::transaction(function () use ($request, $validated): void {
            $videoPath = $request->file('video')->store('social-videos', 'public');
            $thumbPath = $request->file('thumbnail')->store('social-thumbnails', 'public');

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
            'thumbnail' => 'sometimes|file|mimes:'.implode(',', config('social.upload.thumbnail_mimes')).'|max:10240',
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
            Storage::disk('public')->delete($video->thumbnail_path);
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

        $summary = $action->execute($video->fresh(['publications']));

        $message = "Envío masivo: {$summary['published']} OK, {$summary['failed']} fallidas, {$summary['skipped']} omitidas.";

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
        return [
            'id' => $video->id,
            'title' => $video->title,
            'notes' => $video->notes,
            'video_url' => $this->publicStorageUrl($video->video_path),
            'thumbnail_url' => $this->publicStorageUrl($video->thumbnail_path),
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
                'external_url' => $p->external_url,
                'last_error' => $detailed ? $p->last_error : null,
                'api_response' => $detailed ? $p->api_response : null,
                'coming_soon' => (bool) config("social.platforms.{$p->platform}.coming_soon"),
            ])->values(),
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
