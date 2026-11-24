<?php

namespace App\VideoProcessing\Enums;

enum VideoFitFill: string
{
    case Blur = 'blur';
    case White = 'white';
    case Black = 'black';
}
