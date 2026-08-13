<?php

namespace App\Jobs;

use App\Services\Leads\LeadProcessor;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class ProcessLead implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor;

    public int $timeout;

    public function __construct(
        public readonly int $leadId,
        public readonly ?string $cycleId = null,
    ) {
        $this->uniqueFor = (int) config('leadops.processing.unique_for');
        $this->timeout = (int) config('leadops.processing.job_timeout');
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
                ->dontRelease()
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
        $processor->process($this->leadId, $this->cycleId);
    }

    public function failed(?Throwable $exception): void
    {
        app(LeadProcessor::class)->markFailedAfterExhaustion($this->leadId, $this->attempts());
    }
}
