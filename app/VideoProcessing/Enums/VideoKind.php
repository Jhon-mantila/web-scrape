<?php

namespace App\VideoProcessing\Enums;

enum VideoKind: string
{
    case Original = 'original';
    case Clip = 'clip';
}
