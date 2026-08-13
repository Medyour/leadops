<?php

namespace App\Domain\LeadProcessing\Enums;

enum ProcessingEventType: string
{
    case LeadReceived = 'lead.received';
    case ProcessingStarted = 'processing.started';
    case ValidationFailed = 'validation.failed';
    case ValidationSucceeded = 'validation.succeeded';
    case LeadNormalized = 'lead.normalized';
    case DuplicateChecked = 'duplicate.checked';
    case DuplicateDetected = 'duplicate.detected';
    case ScoreCalculated = 'score.calculated';
    case LeadClassified = 'lead.classified';
    case LeadRoutingDecided = 'lead.routing_decided';
    case LeadQualified = 'lead.qualified';
    case LeadRouted = 'lead.routed';
    case TransmissionSucceeded = 'transmission.succeeded';
    case TransmissionFailed = 'transmission.failed';
    case ProcessingAttemptFailed = 'processing.attempt_failed';
    case ProcessingRetryScheduled = 'processing.retry_scheduled';
    case ProcessingFailed = 'processing.failed';
    case ReviewRequested = 'review.requested';
    case ReviewCorrected = 'review.corrected';
    case ReviewReprocessingRequested = 'review.reprocessing_requested';
    case ManualRetryRequested = 'processing.manual_retry_requested';
}
