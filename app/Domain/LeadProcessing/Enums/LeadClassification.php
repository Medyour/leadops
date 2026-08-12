<?php

namespace App\Domain\LeadProcessing\Enums;

enum LeadClassification: string
{
    case Hot = 'HOT';
    case Warm = 'WARM';
    case Low = 'LOW';
}
