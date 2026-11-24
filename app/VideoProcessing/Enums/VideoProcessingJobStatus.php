<?php

namespace App\VideoProcessing\Enums;

enum VideoProcessingJobStatus: string
{
    case Queued = 'queued';
    case Claimed = 'claimed';
    case Analyzing = 'analyzing';
    case AwaitingApproval = 'awaiting_approval';
    case Processing = 'processing';
    case Uploading = 'uploading';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /**
     * @return list<self>
     */
    public static function active(): array
    {
        return [
            self::Queued,
            self::Claimed,
            self::Analyzing,
            self::AwaitingApproval,
            self::Processing,
            self::Uploading,
        ];
    }

    /**
     * @return list<self>
     */
    public static function claimedByWorker(): array
    {
        return [
            self::Claimed,
            self::Analyzing,
            self::Processing,
            self::Uploading,
        ];
    }
}
