<?php

namespace App\Domain\LeadProcessing\Enums;

enum LeadSource: string
{
    case Form = 'form';
    case Csv = 'csv';
    case Webhook = 'webhook';
}
