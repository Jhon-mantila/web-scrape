<?php

namespace App\Http\Controllers;

use App\Models\SocialPublication;
use App\Models\SocialVideo;
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
    public function index(): Response
    {
        $videos = SocialVideo::query()
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

    public function show(SocialVideo $video): Response
    {
        $video->load('publications');

        return Inertia::render('Videos/Show', [
            'video' => $this->videoPayload($video, detailed: true),
            'platformOptions' => $this->platformOptions(),
        ]);
    }

    public function update(Request $request, SocialVideo $video): RedirectResponse
    {
        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'notes' => 'nullable|string|max:2000',
            'publications' => 'sometimes|array',
            'publications.*.id' => 'required|integer|exists:social_publications,id',
            'publications.*.caption_edited' => 'nullable|string|max:10000',
            'publications.*.scheduled_at' => 'nullable|date',
        ]);

        if (isset($validated['title'])) {
            $video->update([
                'title' => $validated['title'],
                'notes' => $validated['notes'] ?? $video->notes,
            ]);
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
        SocialVideo $video,
        GenerateSocialCaptionsAction $action,
        Request $request,
    ): RedirectResponse {
        $platforms = $request->input('platforms');

        $action->execute($video, is_array($platforms) ? $platforms : null);

        return back()->with('success', 'Textos generados con IA.');
    }

    public function generateTitle(
        SocialVideo $video,
        GenerateSocialTitleAction $action,
    ): RedirectResponse {
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
        $message = $publication->status === PublicationStatus::Scheduled
            ? 'Video programado en YouTube.'
            : ($publication->status === PublicationStatus::Published
                ? 'Video publicado en YouTube.'
                : 'Publicación procesada.');

        return back()->with(
            $publication->status === PublicationStatus::Failed ? 'error' : 'success',
            $message,
        );
    }

    public function destroy(SocialVideo $video): RedirectResponse
    {
        Storage::disk('public')->delete([$video->video_path, $video->thumbnail_path]);
        $video->delete();

        return redirect()->route('videos.index')->with('success', 'Video eliminado.');
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
}
