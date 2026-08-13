<?php

return [
    'csv_import' => [
        'max_bytes' => 2 * 1024 * 1024,
        'max_rows' => 500,
        'rate_limit_per_minute' => 3,
    ],

    'local_webhook' => [
        'rate_limit_per_minute' => max(1, min(
            600,
            (int) env('LEADOPS_LOCAL_WEBHOOK_RATE_LIMIT', 30),
        )),
    ],

    'exception_actions' => [
        'rate_limit_per_minute' => max(1, min(
            60,
            (int) env('LEADOPS_EXCEPTION_ACTIONS_RATE_LIMIT', 10),
        )),
    ],

    'normalization' => [
        'phone_country_calling_codes' => [
            'BE' => '32',
            'CA' => '1',
            'CH' => '41',
            'DE' => '49',
            'ES' => '34',
            'FR' => '33',
            'GB' => '44',
            'IT' => '39',
            'NL' => '31',
            'US' => '1',
        ],
    ],

    'score' => [
        'minimum' => 0,
        'maximum' => 100,
        'base' => 20,
        'weights' => [
            'company_size_present' => 10,
            'company_size_at_least_five' => 10,
            'valid_email' => 10,
            'valid_phone' => 10,
            'recognized_service' => 20,
            'long_message' => 10,
            'generic_email_domain' => -5,
            'missing_company_or_country' => -10,
        ],
        'long_message_threshold' => 80,
        'generic_email_domains' => [
            'gmail.com',
            'outlook.com',
            'hotmail.com',
            'yahoo.com',
            'icloud.com',
        ],
    ],

    'qualification' => [
        'services' => [
            'automation',
            'api',
            'business_application',
            'dashboard',
            'other',
        ],
        'classification_thresholds' => [
            'HOT' => 80,
            'WARM' => 50,
            'LOW' => 0,
        ],
    ],

    'routing' => [
        'automation' => 'Sarah',
        'api' => 'Sarah',
        'business_application' => 'Yassine',
        'dashboard' => 'Yassine',
        'other' => 'Emma',
    ],

    'processing' => [
        'max_attempts' => 3,
        'backoff' => [1, 5],
        'job_timeout' => 60,
        'lock_expires_after' => 75,
        'unique_for' => 300,
    ],

    'transmission' => [
        'reference_prefix' => 'simulated',
    ],
];
