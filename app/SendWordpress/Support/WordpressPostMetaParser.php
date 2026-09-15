<?php

namespace App\SendWordpress\Support;

use Illuminate\Support\Carbon;

class WordpressPostMetaParser
{
    /**
     * @param  array<string, mixed>  $post
     * @return array{status: ?string, scheduled_at: ?Carbon, url: ?string, post_id: ?int}
     */
    public function fromApiPost(array $post): array
    {
        $status = isset($post['status']) ? (string) $post['status'] : null;
        $timezone = (string) config('services.wordpress.schedule_timezone', config('app.timezone', 'UTC'));

        $scheduledAt = null;

        if ($status === 'future' && ! empty($post['date'])) {
            $scheduledAt = Carbon::parse((string) $post['date'], $timezone);
        }

        return [
            'post_id' => isset($post['id']) ? (int) $post['id'] : null,
            'status' => $status,
            'scheduled_at' => $scheduledAt,
            'url' => isset($post['link']) ? (string) $post['link'] : null,
        ];
    }
}
