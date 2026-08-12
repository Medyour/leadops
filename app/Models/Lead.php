<?php

namespace App\Models;

use App\Domain\LeadProcessing\Enums\LeadClassification;
use App\Domain\LeadProcessing\Enums\LeadSource;
use App\Domain\LeadProcessing\Enums\LeadStatus;
use Database\Factories\LeadFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lead extends Model
{
    /** @use HasFactory<LeadFactory> */
    use HasFactory;

    protected $fillable = [
        'source',
        'external_reference',
        'first_name',
        'last_name',
        'company',
        'email',
        'phone',
        'country',
        'service_interest',
        'company_size',
        'message',
        'consent',
        'received_at',
        'normalized_email',
        'normalized_phone',
        'score',
        'classification',
        'assigned_to',
        'status',
        'duplicate_of',
        'processing_attempts',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'source' => LeadSource::class,
            'company_size' => 'integer',
            'consent' => 'boolean',
            'received_at' => 'datetime',
            'score' => 'integer',
            'classification' => LeadClassification::class,
            'status' => LeadStatus::class,
            'processing_attempts' => 'integer',
            'processed_at' => 'datetime',
        ];
    }

    public function processingEvents(): HasMany
    {
        return $this->hasMany(ProcessingEvent::class)
            ->orderBy('created_at')
            ->orderBy('id');
    }

    public function duplicateOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'duplicate_of');
    }

    public function duplicates(): HasMany
    {
        return $this->hasMany(self::class, 'duplicate_of');
    }
}
