<?php

namespace App\Http\Controllers;

use App\Models\SocialPublication;
use App\Models\SocialVideo;
use App\SocialPublishing\Enums\PublicationStatus;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $videos = SocialVideo::query()
            ->where('user_id', $request->user()->id)
            ->with('publications')
            ->latest()
            ->get();

        $stats = [
            'total_videos' => $videos->count(),
            'pending' => 0,
            'scheduled' => 0,
            'published' => 0,
            'failed' => 0,
            'publishing' => 0,
            'unavailable' => 0,
        ];

        foreach ($videos as $video) {
            foreach ($video->publications as $publication) {
                $this->incrementStat($stats, $publication->status);
            }
        }

        return Inertia::render('Dashboard', [
            'stats' => $stats,
            'videos' => $videos->map(fn (SocialVideo $video) => $this->videoItem($video))->values(),
        ]);
    }

    /**
     * @param  array<string, int>  $stats
     */
    private function incrementStat(array &$stats, PublicationStatus $status): void
    {
        match ($status) {
            PublicationStatus::Published => $stats['published']++,
            PublicationStatus::Scheduled => $stats['scheduled']++,
            PublicationStatus::Failed => $stats['failed']++,
            PublicationStatus::Publishing => $stats['publishing']++,
            PublicationStatus::Unavailable => $stats['unavailable']++,
            PublicationStatus::Draft, PublicationStatus::CaptionReady => $stats['pending']++,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function videoItem(SocialVideo $video): array
    {
        $publications = $video->publications->map(fn (SocialPublication $p) => [
            'id' => $p->id,
            'platform' => $p->platform,
            'platform_label' => $p->platformLabel(),
            'status' => $p->status->value,
            'status_label' => $p->status->label(),
            'status_icon' => $p->status->icon(),
        ])->values();

        $hasActionNeeded = $video->publications->contains(
            fn (SocialPublication $p) => in_array($p->status, [
                PublicationStatus::Draft,
                PublicationStatus::CaptionReady,
                PublicationStatus::Failed,
            ], true),
        );

        return [
            'id' => $video->id,
            'title' => $video->title,
            'thumbnail_url' => '/storage/'.ltrim($video->thumbnail_path, '/'),
            'created_at' => $video->created_at?->toIso8601String(),
            'publications' => $publications,
            'has_action_needed' => $hasActionNeeded,
        ];
    }
}
