<?php

namespace App\VideoProcessing\Enums;

enum VideoFramingStrategy: string
{
    case SmartCrop = 'smart_crop';
    case BlurredBackground = 'blurred_background';
    case Fit = 'fit';
}
