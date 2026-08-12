<?php

namespace App\Models;

use App\Domain\LeadProcessing\Enums\LeadStatus;
use App\Domain\LeadProcessing\Enums\ProcessingEventType;
use Database\Factories\ProcessingEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProcessingEvent extends Model
{
    /** @use HasFactory<ProcessingEventFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'lead_id',
        'type',
        'from_status',
        'to_status',
        'attempt',
        'context',
    ];

    protected function casts(): array
    {
        return [
            'type' => ProcessingEventType::class,
            'from_status' => LeadStatus::class,
            'to_status' => LeadStatus::class,
            'attempt' => 'integer',
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}
