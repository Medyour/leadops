<?php

namespace App\Domain\LeadProcessing\Enums;

enum ServiceInterest: string
{
    case Automation = 'automation';
    case Api = 'api';
    case BusinessApplication = 'business_application';
    case Dashboard = 'dashboard';
    case Other = 'other';
}
