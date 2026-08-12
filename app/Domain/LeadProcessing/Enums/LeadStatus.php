<?php

namespace App\Domain\LeadProcessing\Enums;

enum LeadStatus: string
{
    case Received = 'RECEIVED';
    case Validating = 'VALIDATING';
    case Invalid = 'INVALID';
    case Duplicate = 'DUPLICATE';
    case Qualified = 'QUALIFIED';
    case NeedsReview = 'NEEDS_REVIEW';
    case Routed = 'ROUTED';
    case Failed = 'FAILED';
}
