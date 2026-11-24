<?php

namespace App\VideoProcessing\Enums;

enum VideoSplitMode: string
{
    case Smart = 'smart';
    case Scene = 'scene';
    case Manual = 'manual';
}
