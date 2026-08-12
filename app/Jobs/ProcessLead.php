<?php

namespace App\Jobs;

use App\Services\Leads\LeadProcessor;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class ProcessLead implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 300;

    public function __construct(public readonly int $leadId)
    {
        $this->onConnection('database');
        $this->onQueue('lead-processing');
        $this->afterCommit();
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return config('leadops.processing.backoff');
    }

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->uniqueId()))
                ->releaseAfter((int) config('leadops.processing.overlap_release_after'))
                ->expireAfter((int) config('leadops.processing.lock_expires_after'))
                ->shared(),
        ];
    }

    public function uniqueId(): string
    {
        return "lead:{$this->leadId}";
    }

    public function handle(LeadProcessor $processor): void
    {
        $processor->process($this->leadId);
    }

    public function failed(?Throwable $exception): void
    {
        app(LeadProcessor::class)->markFailedAfterExhaustion($this->leadId);
    }
}
