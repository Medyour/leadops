<?php

namespace App\Domain\LeadProcessing\Enums;

enum ProcessingEventType: string
{
    case LeadReceived = 'lead.received';
    case ValidationFailed = 'validation.failed';
    case LeadNormalized = 'lead.normalized';
    case DuplicateDetected = 'duplicate.detected';
    case ScoreCalculated = 'score.calculated';
    case LeadClassified = 'lead.classified';
    case LeadRouted = 'lead.routed';
    case TransmissionSucceeded = 'transmission.succeeded';
    case ProcessingRetryScheduled = 'processing.retry_scheduled';
    case ProcessingFailed = 'processing.failed';
    case ReviewRequested = 'review.requested';
}
