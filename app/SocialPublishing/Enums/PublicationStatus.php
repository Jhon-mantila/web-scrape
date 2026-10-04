<?php

namespace App\SocialPublishing\Enums;

enum PublicationStatus: string
{
    case Draft = 'draft';
    case CaptionReady = 'caption_ready';
    case Queued = 'queued';
    case Publishing = 'publishing';
    case Scheduled = 'scheduled';
    case Published = 'published';
    case Failed = 'failed';
    case Unavailable = 'unavailable';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Borrador',
            self::CaptionReady => 'Texto listo',
            self::Queued => 'En cola (app)',
            self::Publishing => 'Publicando…',
            self::Scheduled => 'Programado',
            self::Published => 'Publicado',
            self::Failed => 'Error',
            self::Unavailable => 'Próximamente',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Published => '✅',
            self::Scheduled => '📅',
            self::Queued => '🕐',
            self::Publishing, self::CaptionReady, self::Draft => '⏳',
            self::Failed => '❌',
            self::Unavailable => '🔒',
        };
    }
}
