<?php

return [
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
];
