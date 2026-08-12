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

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Received => [self::Validating],
            self::Validating => [
                self::Invalid,
                self::Duplicate,
                self::Qualified,
                self::NeedsReview,
                self::Failed,
            ],
            self::Qualified => [self::Routed, self::NeedsReview, self::Failed],
            self::NeedsReview, self::Failed => [self::Validating],
            self::Invalid, self::Duplicate, self::Routed => [],
        };
    }

    public function canTransitionTo(self $status): bool
    {
        return in_array($status, $this->allowedTransitions(), true);
    }
}
